<?php

namespace App\Services\Kikoba;

use App\Models\KikobaLoanProduct;
use Carbon\Carbon;

/**
 * Builds a Kikoba loan's repayment installment schedule at approval time.
 * Kept deliberately self-contained (not the core LoanController's engine)
 * since Kikoba loan products only carry the lean field set — no interest
 * threshold, branch/zone, or holiday calendar to account for.
 */
class KikobaLoanScheduleService
{
    /**
     * @return array<int, array{due_date: string, principal: float, interest: float, total: float}>
     */
    public function generate(KikobaLoanProduct $product, float $approvedAmount, int $loanPeriod, Carbon $startDate): array
    {
        $interval = max(1, (int) $product->repayment_interval);
        $intervalUnit = $product->repayment_interval_unit;

        $endDate = $this->addPeriod($startDate, $product->loan_period_unit, $loanPeriod);

        $installmentCount = $this->countInstallments($startDate, $endDate, $interval, $intervalUnit);

        // Simple (non-reducing-balance) interest: a single flat amount for
        // the whole loan — the product's rate applied once against the
        // approved principal (not per installment, which would scale the
        // total up with the installment count) — then divided evenly across
        // installments below, same as the principal.
        $totalInterest = $product->interest_mode === 'fixed'
            ? (float) $product->interest_amount
            : round($approvedAmount * ((float) $product->interest_rate / 100), 2);

        $totalPrincipal = round($approvedAmount, 2);

        $installments = [];
        $principalRemainder = $totalPrincipal;
        $interestRemainder = $totalInterest;

        for ($i = 1; $i <= $installmentCount; $i++) {
            $isLast = $i === $installmentCount;

            $principal = $isLast ? $principalRemainder : round($totalPrincipal / $installmentCount, 2);
            $interest = $isLast ? $interestRemainder : round($totalInterest / $installmentCount, 2);

            $principalRemainder = round($principalRemainder - $principal, 2);
            $interestRemainder = round($interestRemainder - $interest, 2);

            $dueDate = $this->dueDateFor($startDate, $interval, $intervalUnit, $i, $product);

            $installments[] = [
                'due_date' => $dueDate->toDateString(),
                'principal' => $principal,
                'interest' => $interest,
                'total' => round($principal + $interest, 2),
            ];
        }

        return $installments;
    }

    private function countInstallments(Carbon $start, Carbon $end, int $interval, string $intervalUnit): int
    {
        if ($intervalUnit === 'days') {
            return max(1, (int) ceil($start->diffInDays($end) / $interval));
        }

        if ($intervalUnit === 'weeks') {
            return max(1, (int) ceil($start->diffInDays($end) / ($interval * 7)));
        }

        // months
        $count = 0;
        $cursor = $start->copy();
        while ($cursor->lessThan($end)) {
            $count++;
            $cursor = $this->addMonthsPreservingDay($start, $count * $interval);
        }

        return max(1, $count);
    }

    private function dueDateFor(Carbon $start, int $interval, string $intervalUnit, int $installmentNo, KikobaLoanProduct $product): Carbon
    {
        if ($intervalUnit === 'days') {
            $due = $start->copy()->addDays($interval * $installmentNo);
        } elseif ($intervalUnit === 'weeks') {
            $due = $start->copy()->addWeeks($interval * $installmentNo);
        } else {
            $due = $this->addMonthsPreservingDay($start, $interval * $installmentNo);
        }

        $maxAttempts = 30;
        $attempts = 0;
        while ($attempts < $maxAttempts) {
            $isSkippedWeekend = ($product->skip_sat && $due->isSaturday()) || ($product->skip_sun && $due->isSunday());
            if (! $isSkippedWeekend) {
                break;
            }
            $due->addDay();
            $attempts++;
        }

        return $due;
    }

    private function addPeriod(Carbon $start, string $unit, int $amount): Carbon
    {
        return match ($unit) {
            'days' => $start->copy()->addDays($amount),
            'weeks' => $start->copy()->addWeeks($amount),
            default => $this->addMonthsPreservingDay($start, $amount),
        };
    }

    /**
     * Anchored on the original start date each time (not chained off the
     * previous date) so a start day like 31 snaps back to 31 whenever the
     * target month allows it, instead of permanently drifting after the
     * first short month — same fix applied to the core loan module.
     */
    private function addMonthsPreservingDay(Carbon $base, int $months): Carbon
    {
        $target = $base->copy()->startOfDay()->startOfMonth()->addMonthsNoOverflow($months);

        return $target->day(min($base->day, $target->daysInMonth));
    }
}
