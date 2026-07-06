<?php

namespace App\Services\Kikoba;

use App\Models\KikobaGroupFinancialYear;
use App\Models\KikobaGroupMemberProduct;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class KikobaScheduleGeneratorService
{
    /**
     * Generate due-date schedule rows for a member's product enrollment,
     * from the later of (enrolled_date, cycle start) up to cycle end.
     * Safe to re-run: skips due dates that already have a schedule row.
     */
    public function generate(KikobaGroupMemberProduct $memberProduct, KikobaGroupFinancialYear $groupFinancialYear): Collection
    {
        $groupProduct = $memberProduct->groupProduct()->with('product')->first();
        $product = $groupProduct->product;

        // Only mandatory contributions get an auto-generated schedule
        if (! $groupProduct->effective_mandatory) {
            return collect();
        }

        $cycleStart = $groupFinancialYear->start_date ?? $groupFinancialYear->financialYear->start_date;
        $cycleEnd = $groupFinancialYear->end_date ?? $groupFinancialYear->financialYear->end_date;

        $cycleStart = Carbon::parse($cycleStart);
        $cycleEnd = Carbon::parse($cycleEnd);
        $enrolledDate = Carbon::parse($memberProduct->enrolled_date);

        $cursor = $enrolledDate->greaterThan($cycleStart) ? $enrolledDate->copy() : $cycleStart->copy();

        $unit = $product->submission_unit;          // day | week | month
        $frequency = max(1, (int) $product->submission_frequency);

        $sequence = $memberProduct->schedules()
            ->where('kikoba_group_financial_year_id', $groupFinancialYear->id)
            ->max('sequence') ?? 0;
        $sequence++;

        $created = collect();

        while ($cursor->lessThanOrEqualTo($cycleEnd)) {
            $exists = $memberProduct->schedules()
                ->where('kikoba_group_financial_year_id', $groupFinancialYear->id)
                ->whereDate('due_date', $cursor->toDateString())
                ->exists();

            if (! $exists) {
                $created->push(
                    $memberProduct->schedules()->create([
                        'kikoba_group_financial_year_id' => $groupFinancialYear->id,
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

    /**
     * Regenerate remaining (future, unpaid) schedule rows after a change to
     * units/value — removes untouched future pending rows and recreates them
     * at the new expected amount. Past/paid/partial rows are left alone.
     */
    public function regenerateFuture(KikobaGroupMemberProduct $memberProduct, KikobaGroupFinancialYear $groupFinancialYear): Collection
    {
        $memberProduct->schedules()
            ->where('kikoba_group_financial_year_id', $groupFinancialYear->id)
            ->where('status', 'pending')
            ->where('due_date', '>=', now()->toDateString())
            ->delete();

        return $this->generate($memberProduct, $groupFinancialYear);
    }
}
