<?php

namespace App\Services\Kikoba;

use App\Models\KikobaFinancialYearCloseReport;
use App\Models\KikobaGroupFinancialYear;
use App\Models\KikobaGroupProduct;
use App\Models\KikobaMemberProductYearSummary;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

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
     * report always reflects the latest contributions.
     */
    public function generate(KikobaGroupFinancialYear $groupFinancialYear, ?int $generatedBy = null): Collection
    {
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

                [$profitAmount, $breakdown] = $this->calculateProfit(
                    $incomeGroupProducts,
                    $incomePools,
                    $totalShareUnits,
                    $totalShareUnitsInGroup,
                    $activeMemberCount
                );

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
     * figures in for disbursement.
     */
    public function finalize(KikobaGroupFinancialYear $groupFinancialYear, ?int $finalizedBy = null): int
    {
        return KikobaFinancialYearCloseReport::where('group_financial_year_id', $groupFinancialYear->id)
            ->where('status', 'draft')
            ->update([
                'status' => 'finalized',
                'finalized_at' => now(),
                'generated_by' => $finalizedBy,
            ]);
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
