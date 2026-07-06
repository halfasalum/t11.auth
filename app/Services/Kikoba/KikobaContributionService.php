<?php

namespace App\Services\Kikoba;

use App\Models\KikobaContribution;
use App\Models\KikobaContributionSchedule;
use App\Models\KikobaGroupMemberProduct;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class KikobaContributionService
{
    /**
     * Record a payment. If no schedule_id is given, it is auto-allocated to
     * the earliest outstanding schedule for that member/product. Any excess
     * beyond one schedule's balance overflows into the next outstanding one.
     */
    public function recordPayment(array $data): KikobaContribution
    {
        return DB::transaction(function () use ($data) {
            $memberProduct = KikobaGroupMemberProduct::findOrFail($data['kikoba_group_member_product_id']);
            $amount = (float) $data['amount'];

            if ($amount <= 0) {
                throw new InvalidArgumentException('Contribution amount must be greater than zero.');
            }

            $scheduleId = $data['kikoba_contribution_schedule_id'] ?? null;

            $contribution = KikobaContribution::create([
                'kikoba_group_member_product_id' => $memberProduct->id,
                'kikoba_contribution_schedule_id' => $scheduleId,
                'amount' => $amount,
                'paid_date' => $data['paid_date'] ?? now()->toDateString(),
                'reference' => $data['reference'] ?? null,
                'payment_method' => $data['payment_method'] ?? null,
                'received_by' => $data['received_by'] ?? null,
                'notes' => $data['notes'] ?? null,
            ]);

            if ($scheduleId) {
                $this->applyToSchedule($scheduleId, $amount);
            } else {
                $this->autoAllocate($memberProduct, $amount);
            }

            return $contribution->fresh();
        });
    }

    protected function autoAllocate(KikobaGroupMemberProduct $memberProduct, float $amount): void
    {
        $remaining = $amount;

        $outstanding = $memberProduct->schedules()
            ->whereIn('status', ['pending', 'partial', 'overdue'])
            ->orderBy('due_date')
            ->get();

        foreach ($outstanding as $schedule) {
            if ($remaining <= 0) {
                break;
            }

            $balance = (float) $schedule->expected_amount - (float) $schedule->paid_amount;
            $portion = min($balance, $remaining);

            $this->applyToSchedule($schedule->id, $portion);
            $remaining -= $portion;
        }

        // Any leftover amount (member overpaid beyond all outstanding schedules)
        // is simply recorded on the contribution without a schedule link.
    }

    protected function applyToSchedule(int $scheduleId, float $amount): void
    {
        /** @var KikobaContributionSchedule|null $schedule */
        $schedule = KikobaContributionSchedule::lockForUpdate()->find($scheduleId);

        if (! $schedule) {
            return;
        }

        $schedule->paid_amount = (float) $schedule->paid_amount + $amount;

        if ($schedule->paid_amount >= $schedule->expected_amount) {
            $schedule->status = 'paid';
        } elseif ($schedule->paid_amount > 0) {
            $schedule->status = 'partial';
        }

        $schedule->save();
    }
}
