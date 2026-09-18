<?php

namespace App\Services\Kikoba;

use App\Models\KikobaFinancialYearCloseReport;
use App\Models\KikobaGroup;
use App\Models\KikobaGroupFinancialYear;
use App\Models\KikobaGroupProduct;
use App\Models\KikobaLoan;
use App\Models\KikobaLoanInterestClaim;
use App\Models\KikobaMemberProductYearSummary;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class KikobaFinancialYearCloseReportService
{
    public function __construct(protected KikobaMemberProductSummaryService $summaryService)
    {
    }

    /**
     * Build (or rebuild) the close report for every active member of this
     * group-financial-year cycle:
     *   - total_savings_amount: sum of contributions to "saving" products
     *   - profit_amount: income distributed from "used_as_income" products,
     *     using each product's own income_calculation rule
     *   - total_payout: savings + profit
     *
     * Regenerates the underlying product-year summaries first, so the
     * report always reflects the latest contributions. Refuses to run once
     * this cycle has been finalized — see finalize() for the one place a
     * finalized cycle's figures are still allowed to be (re)computed.
     *
     * @throws InvalidArgumentException when this cycle is already finalized
     */
    public function generate(KikobaGroupFinancialYear $groupFinancialYear, ?int $generatedBy = null): Collection
    {
        $this->assertNotFinalized($groupFinancialYear);

        return DB::transaction(function () use ($groupFinancialYear, $generatedBy) {
            $this->summaryService->generate($groupFinancialYear);

            $group = $groupFinancialYear->group;

            $activeMembers = $group->activeMembers()->get();

            $summaries = KikobaMemberProductYearSummary::where('group_financial_year_id', $groupFinancialYear->id)->get();

            $summariesByMember = $summaries->groupBy('kikoba_group_member_id');

            // Denominator for share_value-based income distribution: total
            // share units contributed across the WHOLE group this cycle.
            $totalShareUnitsInGroup = (int) $summaries
                ->where('product_type', 'share')
                ->sum('units');

            $activeMemberCount = $activeMembers->count();

            // Income-generating products for this group, with the total pool
            // collected per product across all members this cycle.
            $incomeGroupProducts = KikobaGroupProduct::with('product')
                ->where('kikoba_group_id', $group->id)
                ->whereHas('product', fn ($q) => $q->where('used_as_income', true))
                ->get();

            $incomePools = $summaries
                ->whereIn('kikoba_group_product_id', $incomeGroupProducts->pluck('id'))
                ->groupBy('kikoba_group_product_id')
                ->map(fn ($rows) => (float) $rows->sum('total_paid_amount'));

            // Loan interest that's accrued/collected (per each product's
            // interest_recognition rule) but not yet locked into a prior
            // closure — see loanInterestData() for how "claimable" is derived.
            [$loanProductsById, $loanInterestPools] = $this->loanInterestData($group);

            $reports = collect();

            foreach ($activeMembers as $groupMember) {
                $memberSummaries = $summariesByMember->get($groupMember->id, collect());

                $totalSavingsAmount = (float) $memberSummaries
                    ->where('product_type', 'saving')
                    ->sum('total_paid_amount');

                $totalShareUnits = (int) $memberSummaries
                    ->where('product_type', 'share')
                    ->sum('units');

                $totalShareAmount = (float) $memberSummaries
                    ->where('product_type', 'share')
                    ->sum('total_paid_amount');

                [$productProfitAmount, $productBreakdown] = $this->calculateProfit(
                    $incomeGroupProducts,
                    $incomePools,
                    $totalShareUnits,
                    $totalShareUnitsInGroup,
                    $activeMemberCount
                );

                [$loanProfitAmount, $loanBreakdown] = $this->calculateLoanInterestProfit(
                    $loanProductsById,
                    $loanInterestPools,
                    $totalShareUnits,
                    $totalShareUnitsInGroup,
                    $activeMemberCount
                );

                $profitAmount = $productProfitAmount + $loanProfitAmount;
                $breakdown = array_merge($productBreakdown, $loanBreakdown);

                $totalPayout = round($totalSavingsAmount + $profitAmount, 2);

                $reports->push(
                    KikobaFinancialYearCloseReport::updateOrCreate(
                        [
                            'group_financial_year_id' => $groupFinancialYear->id,
                            'kikoba_group_member_id' => $groupMember->id,
                        ],
                        [
                            'kikoba_group_id' => $group->id,
                            'total_savings_amount' => round($totalSavingsAmount, 2),
                            'total_share_units' => $totalShareUnits,
                            'total_share_amount' => round($totalShareAmount, 2),
                            'profit_amount' => round($profitAmount, 2),
                            'total_payout' => $totalPayout,
                            'breakdown' => $breakdown,
                            'status' => 'draft',
                            'generated_by' => $generatedBy,
                            'generated_at' => now(),
                        ]
                    )
                );
            }

            return $reports;
        });
    }

    /**
     * Mark draft reports for a cycle as finalized, locking the payout
     * figures in for disbursement — once locked, generate() refuses to
     * touch this cycle again. Regenerates one last time first so the
     * locked-in figures reflect anything that changed since the draft was
     * last reviewed, then permanently claims whatever loan interest was
     * just distributed — via KikobaLoan.interest_claimed_amount — so it can
     * never be pooled into a future close again.
     *
     * Idempotent: calling this again on an already-finalized cycle is a
     * no-op (returns 0) rather than an error, and never re-generates or
     * re-claims — the lock, once set, is never bypassed.
     */
    public function finalize(KikobaGroupFinancialYear $groupFinancialYear, ?int $finalizedBy = null): int
    {
        return DB::transaction(function () use ($groupFinancialYear, $finalizedBy) {
            if ($this->isFinalized($groupFinancialYear)) {
                return 0;
            }

            $this->generate($groupFinancialYear, $finalizedBy);

            [, , $claimableByLoan] = $this->loanInterestData($groupFinancialYear->group);

            if (! empty($claimableByLoan)) {
                $claimedAt = now();

                KikobaLoan::whereIn('id', array_keys($claimableByLoan))
                    ->get()
                    ->each(function (KikobaLoan $loan) use ($claimableByLoan, $groupFinancialYear, $claimedAt) {
                        $loan->update([
                            'interest_claimed_amount' => round(
                                (float) $loan->interest_claimed_amount + $claimableByLoan[$loan->id],
                                2
                            ),
                            'last_claimed_financial_year_id' => $groupFinancialYear->id,
                            'interest_claimed_at' => $claimedAt,
                        ]);

                        KikobaLoanInterestClaim::create([
                            'kikoba_loan_id' => $loan->id,
                            'group_financial_year_id' => $groupFinancialYear->id,
                            'amount' => $claimableByLoan[$loan->id],
                            'claimed_at' => $claimedAt,
                        ]);
                    });
            }

            return KikobaFinancialYearCloseReport::where('group_financial_year_id', $groupFinancialYear->id)
                ->where('status', 'draft')
                ->update([
                    'status' => 'finalized',
                    'finalized_at' => now(),
                    'generated_by' => $finalizedBy,
                ]);
        });
    }

    /**
     * Reverse a finalize(): unlocks the cycle's reports back to draft and
     * gives back exactly the loan interest THIS cycle claimed — read from
     * kikoba_loan_interest_claims, not just zeroed out — so a
     * cash_collected loan that has also been (or later gets) claimed by a
     * different cycle keeps that other cycle's portion untouched. A no-op
     * (returns 0) if this cycle isn't finalized.
     */
    public function unlock(KikobaGroupFinancialYear $groupFinancialYear, ?int $unlockedBy = null): int
    {
        return DB::transaction(function () use ($groupFinancialYear, $unlockedBy) {
            if (! $this->isFinalized($groupFinancialYear)) {
                return 0;
            }

            $claims = KikobaLoanInterestClaim::where('group_financial_year_id', $groupFinancialYear->id)->get();

            foreach ($claims as $claim) {
                /** @var KikobaLoan|null $loan */
                $loan = KikobaLoan::find($claim->kikoba_loan_id);

                if ($loan) {
                    $remainingClaim = KikobaLoanInterestClaim::where('kikoba_loan_id', $loan->id)
                        ->where('group_financial_year_id', '!=', $groupFinancialYear->id)
                        ->orderByDesc('claimed_at')
                        ->first();

                    $loan->update([
                        'interest_claimed_amount' => max(
                            0,
                            round((float) $loan->interest_claimed_amount - (float) $claim->amount, 2)
                        ),
                        'last_claimed_financial_year_id' => $remainingClaim?->group_financial_year_id,
                        'interest_claimed_at' => $remainingClaim?->claimed_at,
                    ]);
                }

                $claim->delete();
            }

            return KikobaFinancialYearCloseReport::where('group_financial_year_id', $groupFinancialYear->id)
                ->where('status', 'finalized')
                ->update([
                    'status' => 'draft',
                    'finalized_at' => null,
                    'generated_by' => $unlockedBy,
                ]);
        });
    }

    /**
     * Whether this cycle already has a locked-in (finalized) report — once
     * true, generate() refuses to recompute it and finalize() becomes a
     * no-op instead of re-locking.
     */
    public function isFinalized(KikobaGroupFinancialYear $groupFinancialYear): bool
    {
        return KikobaFinancialYearCloseReport::where('group_financial_year_id', $groupFinancialYear->id)
            ->where('status', 'finalized')
            ->exists();
    }

    protected function assertNotFinalized(KikobaGroupFinancialYear $groupFinancialYear): void
    {
        if ($this->isFinalized($groupFinancialYear)) {
            throw new InvalidArgumentException(
                'This financial-year cycle has already been finalized and is locked — reports can no longer be regenerated.'
            );
        }
    }

    /**
     * For every loan this group has disbursed, work out how much of its
     * interest is claimable right now: what's recognized as income under
     * its product's interest_recognition rule, minus whatever has already
     * been locked into a past closure (interest_claimed_amount). Grouped by
     * loan product so each product's pool can be split per its own
     * interest_distribution rule, mirroring how product-income pools are
     * kept separate in generate().
     *
     * @return array{0: Collection<int, \App\Models\KikobaLoanProduct>, 1: array<int, float>, 2: array<int, float>}
     *         [loan products by id, pool amount by loan_product_id, claimable amount by loan_id]
     */
    protected function loanInterestData(KikobaGroup $group): array
    {
        $loans = KikobaLoan::with(['loanProduct', 'schedules'])
            ->where('kikoba_group_id', $group->id)
            ->whereNotNull('disbursed_at')
            ->get();

        $productsById = collect();
        $poolsByProduct = [];
        $claimableByLoan = [];

        foreach ($loans as $loan) {
            $product = $loan->loanProduct;

            if (! $product) {
                continue;
            }

            $claimable = round($this->eligibleInterestFor($loan, $product) - (float) $loan->interest_claimed_amount, 2);

            if ($claimable <= 0) {
                continue;
            }

            $claimableByLoan[$loan->id] = $claimable;
            $poolsByProduct[$product->id] = round(($poolsByProduct[$product->id] ?? 0) + $claimable, 2);
            $productsById->put($product->id, $product);
        }

        return [$productsById, $poolsByProduct, $claimableByLoan];
    }

    /**
     * How much of this loan's interest counts as recognized income right
     * now, under its product's interest_recognition rule. This is the
     * running total ever recognized, not a per-cycle delta — the caller
     * subtracts interest_claimed_amount to get what's still claimable.
     */
    protected function eligibleInterestFor(KikobaLoan $loan, \App\Models\KikobaLoanProduct $product): float
    {
        return match ($product->interest_recognition) {
            'on_disbursement' => (float) ($loan->interest_total ?? 0),
            'on_completion' => in_array($loan->status, ['completed', 'early_settled'], true)
                ? (float) ($loan->interest_total ?? 0)
                : 0.0,
            'cash_collected' => $this->collectedInterestFor($loan),
            default => 0.0,
        };
    }

    /**
     * Actual cash-basis interest collected so far: the upfront lump (once
     * disbursed) plus, for each schedule installment, the interest portion
     * of whatever's been paid against it — assumed proportional to that
     * installment's principal/interest split, since payments aren't
     * recorded as covering one before the other.
     */
    protected function collectedInterestFor(KikobaLoan $loan): float
    {
        $collected = (float) $loan->upfront_interest_amount;

        foreach ($loan->schedules as $schedule) {
            $total = (float) $schedule->total_amount;

            if ($total <= 0) {
                continue;
            }

            $collected += (float) $schedule->paid_amount * ((float) $schedule->interest_amount / $total);
        }

        return round($collected, 2);
    }

    /**
     * Same distribution math as calculateProfit(), applied to loan-interest
     * pools (grouped by loan product) instead of product-income pools.
     *
     * @return array{0: float, 1: array} [profit amount, calculation breakdown]
     */
    protected function calculateLoanInterestProfit(
        Collection $loanProductsById,
        array $poolsByProduct,
        int $memberShareUnits,
        int $totalShareUnitsInGroup,
        int $activeMemberCount
    ): array {
        $profitAmount = 0.0;
        $breakdown = [];

        foreach ($loanProductsById as $product) {
            $poolAmount = (float) ($poolsByProduct[$product->id] ?? 0);

            if ($poolAmount <= 0) {
                continue;
            }

            $rule = $product->interest_distribution;
            $memberShare = 0.0;

            if ($rule === 'flat_rate') {
                $memberShare = $activeMemberCount > 0
                    ? $poolAmount / $activeMemberCount
                    : 0.0;
            } elseif ($rule === 'share_value') {
                $memberShare = $totalShareUnitsInGroup > 0
                    ? $poolAmount * ($memberShareUnits / $totalShareUnitsInGroup)
                    : 0.0;
            }

            $profitAmount += $memberShare;

            $breakdown[] = [
                'source' => 'loan_interest',
                'loan_product_id' => $product->id,
                'product_name' => $product->name,
                'calculation' => $rule,
                'pool_amount' => round($poolAmount, 2),
                'total_share_units_in_group' => $rule === 'share_value' ? $totalShareUnitsInGroup : null,
                'member_share_units' => $rule === 'share_value' ? $memberShareUnits : null,
                'active_member_count' => $rule === 'flat_rate' ? $activeMemberCount : null,
                'member_share' => round($memberShare, 2),
            ];
        }

        return [$profitAmount, $breakdown];
    }

    /**
     * @return array{0: float, 1: array} [profit amount, calculation breakdown]
     */
    protected function calculateProfit(
        Collection $incomeGroupProducts,
        Collection $incomePools,
        int $memberShareUnits,
        int $totalShareUnitsInGroup,
        int $activeMemberCount
    ): array {
        $profitAmount = 0.0;
        $breakdown = [];

        foreach ($incomeGroupProducts as $groupProduct) {
            $poolAmount = (float) ($incomePools->get($groupProduct->id) ?? 0);

            if ($poolAmount <= 0) {
                continue;
            }

            $rule = $groupProduct->product->income_calculation;
            $memberShare = 0.0;

            if ($rule === 'flat_rate') {
                $memberShare = $activeMemberCount > 0
                    ? $poolAmount / $activeMemberCount
                    : 0.0;
            } elseif ($rule === 'share_value') {
                $memberShare = $totalShareUnitsInGroup > 0
                    ? $poolAmount * ($memberShareUnits / $totalShareUnitsInGroup)
                    : 0.0;
            }

            $profitAmount += $memberShare;

            $breakdown[] = [
                'source' => 'product_income',
                'group_product_id' => $groupProduct->id,
                'product_name' => $groupProduct->product->name,
                'calculation' => $rule,
                'pool_amount' => round($poolAmount, 2),
                'total_share_units_in_group' => $rule === 'share_value' ? $totalShareUnitsInGroup : null,
                'member_share_units' => $rule === 'share_value' ? $memberShareUnits : null,
                'active_member_count' => $rule === 'flat_rate' ? $activeMemberCount : null,
                'member_share' => round($memberShare, 2),
            ];
        }

        return [$profitAmount, $breakdown];
    }
}
