<?php

namespace App\Services\Kikoba;

use App\Models\KikobaGroupFinancialYear;
use App\Models\KikobaGroupMemberProduct;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class KikobaScheduleGeneratorService
{
    /**
     * Generate due-date schedule rows for a member's product enrollment,
     * from the later of (enrolled_date, cycle start) up to cycle end.
     * Safe to re-run: skips due dates that already have a schedule row.
     */
    public function generateOld(KikobaGroupMemberProduct $memberProduct, KikobaGroupFinancialYear $groupFinancialYear): Collection
    {
        $groupProduct = $memberProduct->groupProduct()->with('product')->first();
        Log::info('Group Product: ' . $groupProduct);
        $product = $groupProduct->product;
        Log::info('Product data: ' . $product);
        // Only mandatory contributions get an auto-generated schedule
        /*  if (! $groupProduct->effective_mandatory) {
            return collect();
        } */

        Log::info("Group financial year data : " . $groupFinancialYear);


        // $cycleStart = $groupFinancialYear->start_date ?? $groupFinancialYear->financialYear->start_date;
        $cycleStart = $groupFinancialYear->start_date;
        Log::info('Cycle Start: ' . $cycleStart);
        // $cycleEnd = $groupFinancialYear->end_date ?? $groupFinancialYear->financialYear->end_date;
        $cycleEnd = $groupFinancialYear->end_date;
        Log::info('Cycle End: ' . $cycleEnd);

        $cycleStart = Carbon::parse($cycleStart);
        $cycleEnd = Carbon::parse($cycleEnd);
        $enrolledDate = Carbon::parse($memberProduct->enrolled_date);

        $cursor = $cycleStart;
        Log::info('Cursor : ' . $cursor);

        $unit = $product->submission_unit;          // day | week | month
        $frequency = max(1, (int) $product->submission_frequency);

        $sequence = $memberProduct->schedules()
            ->where('group_financial_year_id', $groupFinancialYear->id)
            ->max('sequence') ?? 0;
        Log::info('Generating schedule for member product sequence : ' . $sequence);
        $sequence++;

        $created = collect();

        while ($cursor->lessThanOrEqualTo($cycleEnd)) {
            $exists = $memberProduct->schedules()
                ->where('group_financial_year_id', $groupFinancialYear->id)
                ->whereDate('due_date', $cursor->toDateString())
                ->exists();

            if (! $exists) {
                $created->push(
                    $memberProduct->schedules()->create([
                        'group_financial_year_id' => $groupFinancialYear->id,
                        'sequence' => $sequence,
                        'due_date' => $cursor->toDateString(),
                        'expected_amount' => $memberProduct->expected_amount,
                        'paid_amount' => 0,
                        'status' => 'pending',
                        'penalty_applied' => false,
                    ])
                );
                $sequence++;
            }

            $cursor = match ($unit) {
                'day' => $cursor->addDays($frequency),
                'week' => $cursor->addWeeks($frequency),
                'month' => $cursor->addMonths($frequency),
                default => $cursor->addMonths($frequency),
            };
        }

        return $created;
    }


    public function generate(KikobaGroupMemberProduct $memberProduct, KikobaGroupFinancialYear $groupFinancialYear): Collection
    {
        $groupProduct = $memberProduct->groupProduct()->with('product')->first();

        if (! $groupProduct || ! $groupProduct->product) {
            Log::warning('Kikoba schedule generation skipped: member product has no linked catalogue product', [
                'member_product_id' => $memberProduct->id,
                'group_product_id'  => $memberProduct->kikoba_group_product_id,
            ]);
            return collect();
        }

        $product = $groupProduct->product;

        if (! $groupFinancialYear->start_date || ! $groupFinancialYear->end_date) {
            Log::warning('Kikoba schedule generation skipped: financial year has no start/end date', [
                'member_product_id' => $memberProduct->id,
                'group_financial_year_id' => $groupFinancialYear->id,
            ]);
            return collect();
        }

        $cycleStart = Carbon::parse($groupFinancialYear->start_date);
        $cycleEnd   = Carbon::parse($groupFinancialYear->end_date);

        $unit      = $product->submission_unit;     // day | week | month
        $frequency = max(1, (int) $product->submission_frequency);

        $sequence = $memberProduct->schedules()
            ->where('group_financial_year_id', $groupFinancialYear->id)
            ->max('sequence') ?? 0;

        $sequence++;
        $created = collect();

        // First due date depends on unit + the product's day-of-week / day-of-month settings.
        $cursor = $this->getFirstDueDate($cycleStart, $product, $frequency);

        $iteration = 0;
        $maxIterations = 1000; // safety net against a mis-configured product causing a runaway loop

        while ($cursor->lessThanOrEqualTo($cycleEnd)) {
            if (++$iteration > $maxIterations) {
                Log::error('Kikoba generate() exceeded max iterations — check product submission settings', [
                    'member_product_id' => $memberProduct->id,
                    'group_financial_year_id' => $groupFinancialYear->id,
                    'unit' => $unit,
                    'frequency' => $frequency,
                    'last_cursor' => $cursor->toDateString(),
                    'cycle_end' => $cycleEnd->toDateString(),
                ]);
                break;
            }

            $exists = $memberProduct->schedules()
                ->where('group_financial_year_id', $groupFinancialYear->id)
                ->whereDate('due_date', $cursor->toDateString())
                ->exists();

            if (! $exists) {
                $created->push(
                    $memberProduct->schedules()->create([
                        'group_financial_year_id' => $groupFinancialYear->id,
                        'sequence'                => $sequence,
                        'due_date'                => $cursor->toDateString(),
                        'expected_amount'         => $memberProduct->expected_amount,
                        'paid_amount'             => 0,
                        'status'                  => 'pending',
                        'penalty_applied'         => false,
                    ])
                );
                $sequence++;
            }

            // Advance the cursor. For months, anchor on the 1st before adding so a
            // short month (e.g. Feb) never drags the cursor into the following month.
            $cursor = match ($unit) {
                'day'   => $cursor->copy()->addDays($frequency),
                'week'  => $cursor->copy()->addWeeks($frequency), // keeps the same weekday
                default => $this->monthlyDueDate(
                    $cursor->copy()->startOfMonth()->addMonthsNoOverflow($frequency),
                    $product
                ),
            };
        }

        Log::info('Kikoba schedule generation complete', [
            'member_product_id' => $memberProduct->id,
            'group_financial_year_id' => $groupFinancialYear->id,
            'rows_created' => $created->count(),
        ]);

        return $created;
    }
    /**
     * Calculate the first due date according to the new business rules
     */


    private function getFirstDueDate(Carbon $startDate, $product, int $frequency): Carbon
    {
        $date = $startDate->copy()->startOfDay();

        return match ($product->submission_unit) {
            'week'  => $this->firstWeekdayOnOrAfter($date, $this->configuredWeekday($product)),
            'month' => $this->firstMonthlyDueDate($date, $product, $frequency),
            default => $date,
        };
    }

    /**
     * Weekday the product's weekly schedule lands on. 0 = Sunday .. 6 = Saturday.
     * Falls back to Sunday when the product predates this setting.
     */
    private function configuredWeekday($product): int
    {
        return $product->submission_day_of_week !== null
            ? (int) $product->submission_day_of_week
            : Carbon::SUNDAY;
    }

    /**
     * First occurrence of $weekday on or after the given date (inclusive).
     */
    private function firstWeekdayOnOrAfter(Carbon $date, int $weekday): Carbon
    {
        $date = $date->copy()->startOfDay();
        $daysToAdd = ($weekday - $date->dayOfWeek + 7) % 7; // dayOfWeek: Sun=0..Sat=6
        return $date->addDays($daysToAdd);
    }

    /**
     * First monthly due date on or after $startDate. Uses the product's month
     * settings for $startDate's month; if that date already passed, rolls forward
     * by the configured frequency.
     */
    private function firstMonthlyDueDate(Carbon $startDate, $product, int $frequency): Carbon
    {
        $due = $this->monthlyDueDate($startDate, $product);

        if ($due->lt($startDate->copy()->startOfDay())) {
            $due = $this->monthlyDueDate(
                $startDate->copy()->startOfMonth()->addMonthsNoOverflow($frequency),
                $product
            );
        }

        return $due;
    }

    /**
     * The due date within the month of $anchor, per the product's month settings:
     *  - end_of_month  => the last calendar day of that month
     *  - specific_date => that day number, or the last day of the month when the
     *                     month is shorter than the configured day (e.g. 31 -> 30,
     *                     29 -> 28 in a non-leap February).
     * Falls back to end-of-month when the product predates these settings.
     */
    private function monthlyDueDate(Carbon $anchor, $product): Carbon
    {
        $endOfMonth = $anchor->copy()->endOfMonth()->startOfDay();

        $option = $product->submission_month_option ?: 'end_of_month';

        if ($option !== 'specific_date') {
            return $endOfMonth;
        }

        $day = (int) ($product->submission_day_of_month ?: $endOfMonth->day);
        $day = min(max($day, 1), $endOfMonth->day);

        return $anchor->copy()->startOfMonth()->addDays($day - 1)->startOfDay();
    }

    /**
     * Reliable way to get the first Sunday on or after a given date
     */
    private function getFirstSunday(Carbon $date): Carbon
    {
        $dayOfWeek = $date->dayOfWeek; // 0 = Sunday, 1 = Monday, ..., 6 = Saturday

        if ($dayOfWeek === Carbon::SUNDAY) {
            return $date->copy();
        }

        // Add days to reach next Sunday
        $daysToAdd = (7 - $dayOfWeek) % 7;
        return $date->copy()->addDays($daysToAdd);
    }

    /**
     * Regenerate remaining (future, unpaid) schedule rows after a change to
     * units/value — removes untouched future pending rows and recreates them
     * at the new expected amount. Past/paid/partial rows are left alone.
     */
    public function regenerateFuture(KikobaGroupMemberProduct $memberProduct, KikobaGroupFinancialYear $groupFinancialYear): Collection
    {
        $memberProduct->schedules()
            ->where('group_financial_year_id', $groupFinancialYear->id)
            ->where('status', 'pending')
            ->where('due_date', '>=', now()->toDateString())
            ->delete();

        return $this->generate($memberProduct, $groupFinancialYear);
    }
}
