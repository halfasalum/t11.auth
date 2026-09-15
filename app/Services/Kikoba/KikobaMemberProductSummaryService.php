<?php

namespace App\Services\Kikoba;

use App\Models\KikobaGroupFinancialYear;
use App\Models\KikobaGroupProduct;
use App\Models\KikobaMemberProductYearSummary;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class KikobaMemberProductSummaryService
{ 
    /**
     * Aggregate every contribution made during this group-financial-year
     * cycle into one row per (member, product): total units, the unit
     * value used, and the resulting total. Safe to re-run — upserts on the
     * unique (year, member, product) key, so it can be regenerated at any
     * point during the year for an up-to-date snapshot, not only at close.
     */
    public function generate(KikobaGroupFinancialYear $groupFinancialYear): Collection
    {
        $group = $groupFinancialYear->group;
        $start = $groupFinancialYear->start_date->toDateString();
        $end = $groupFinancialYear->end_date->toDateString();

        // One aggregate query for the whole group — cost scales with total
        // contribution rows in this cycle, not with years of prior history.
        $rows = DB::table('kikoba_contributions as c')
            ->join('kikoba_group_member_products as gmp', 'gmp.id', '=', 'c.group_member_product_id')
            ->join('kikoba_group_members as gm', 'gm.id', '=', 'gmp.kikoba_group_member_id')
            ->where('gm.kikoba_group_id', $group->id)
            ->whereBetween('c.paid_date', [$start, $end])
            ->groupBy('gmp.kikoba_group_member_id', 'gmp.kikoba_group_product_id')
            ->select(
                'gmp.kikoba_group_member_id as group_member_id',
                'gmp.kikoba_group_product_id as group_product_id',
                DB::raw('SUM(c.units) as total_units'),
                DB::raw('SUM(c.amount) as total_paid_amount')
            )
            ->get();

        if ($rows->isEmpty()) {
            return collect();
        }

        // Preload every distinct group product once (small set, typically
        // share/saving/penalty), instead of querying per row.
        $groupProducts = KikobaGroupProduct::with('product')
            ->whereIn('id', $rows->pluck('group_product_id')->unique())
            ->get()
            ->keyBy('id');

        $summaries = collect();

        foreach ($rows as $row) {
            $groupProduct = $groupProducts->get($row->group_product_id);

            if (! $groupProduct) {
                continue;
            }

            $units = (int) $row->total_units;
            $unitValue = $groupProduct->effective_value;

            $summaries->push(
                KikobaMemberProductYearSummary::updateOrCreate(
                    [
                        'group_financial_year_id' => $groupFinancialYear->id,
                        'kikoba_group_member_id' => $row->group_member_id,
                        'kikoba_group_product_id' => $row->group_product_id,
                    ],
                    [
                        'kikoba_group_id' => $group->id,
                        'product_type' => $groupProduct->product->product_type,
                        'units' => $units,
                        'unit_value' => $unitValue,
                        'total_amount' => round($units * $unitValue, 2),
                        'total_paid_amount' => (float) $row->total_paid_amount,
                        'generated_at' => now(),
                    ]
                )
            );
        }

        return $summaries;
    }
}
