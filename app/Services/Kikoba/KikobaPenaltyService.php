<?php

namespace App\Services\Kikoba;

use App\Models\KikobaContributionSchedule;
use App\Models\KikobaPenalty;
use Illuminate\Support\Facades\DB;

class KikobaPenaltyService
{
    /**
     * Scan for overdue, unpaid/partial schedules that have not yet had a
     * penalty applied, mark them overdue, and raise a penalty using the
     * group's active penalty-type product. Intended to run on a daily
     * scheduled command. Returns the number of penalties created.
     */
    public function detectAndApplyPenalties(?int $companyId = null): int
    {
        $count = 0;

        $query = KikobaContributionSchedule::query()
            ->whereIn('status', ['pending', 'partial'])
            ->where('penalty_applied', false)
            ->whereDate('due_date', '<', now()->toDateString());

        if ($companyId) {
            $query->whereHas(
                'memberProduct.groupMember.group',
                fn ($q) => $q->where('company_id', $companyId)
            );
        }

        $query->with('memberProduct.groupMember.group.groupProducts.product')
            ->chunkById(200, function ($schedules) use (&$count) {
                foreach ($schedules as $schedule) {
                    DB::transaction(function () use ($schedule, &$count) {
                        $schedule = KikobaContributionSchedule::lockForUpdate()->find($schedule->id);

                        if (! $schedule || $schedule->penalty_applied) {
                            return;
                        }

                        $groupMember = $schedule->memberProduct->groupMember;
                        $group = $groupMember->group;

                        $penaltyGroupProduct = $group->groupProducts()
                            ->where('status', 'active')
                            ->whereHas('product', fn ($q) => $q->where('product_type', 'penalty'))
                            ->first();

                        $schedule->status = 'overdue';

                        if ($penaltyGroupProduct) {
                            KikobaPenalty::create([
                                'kikoba_contribution_schedule_id' => $schedule->id,
                                'kikoba_group_member_id' => $groupMember->id,
                                'kikoba_group_product_id' => $penaltyGroupProduct->id,
                                'amount' => $penaltyGroupProduct->effective_value,
                                'issued_date' => now()->toDateString(),
                                'status' => 'pending',
                                'reason' => 'Missed contribution due on '.$schedule->due_date->toDateString(),
                            ]);

                            $schedule->penalty_applied = true;
                            $count++;
                        }

                        $schedule->save();
                    });
                }
            });

        return $count;
    }
}
