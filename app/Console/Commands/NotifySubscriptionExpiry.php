<?php

namespace App\Console\Commands;

use App\Models\Subscription;
use App\Models\SubscriptionExpiryNotification;
use App\Services\NotificationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class NotifySubscriptionExpiry extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:notify-subscription-expiry
                            {--dry-run : Report what would be sent without sending or recording anything}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'SMS each company whose subscription ends in exactly 14, 7, 3 or 0 days';

    /**
     * Days-remaining milestones to notify on.
     */
    private const MILESTONES = [14, 7, 3, 0];

    protected $stats = [
        'checked' => 0,
        'sent' => 0,
        'skipped_duplicate' => 0,
        'skipped_superseded' => 0,
        'failed' => 0,
    ];

    public function handle(NotificationService $notificationService)
    {
        $this->info('==========================================');
        $this->info('Subscription Expiry Notifier Started');
        $this->info('==========================================');
        $this->newLine();

        $dryRun = (bool) $this->option('dry-run');
        if ($dryRun) {
            $this->warn('DRY RUN MODE - no SMS will be sent, nothing will be recorded');
            $this->newLine();
        }

        foreach (self::MILESTONES as $days) {
            $targetDate = now()->startOfDay()->addDays($days)->toDateString();
            $this->info("Checking subscriptions ending on {$targetDate} ({$days} days remaining)...");

            // status is checked against both active and expired: a
            // subscription that expires "today" may already have been
            // flipped to 'expired' by the 00:05 app:check-company-subscription
            // job that runs before this one at 08:00.
            $subscriptions = Subscription::whereIn('status', ['active', 'expired'])
                ->whereDate('end_date', $targetDate)
                ->with('company')
                ->get();

            foreach ($subscriptions as $subscription) {
                $this->stats['checked']++;

                if (! $subscription->company) {
                    $this->line("   └─ Subscription #{$subscription->id} has no company, skipping");
                    continue;
                }

                $company = $subscription->company;

                if ($this->isSuperseded($subscription)) {
                    $this->line("   └─ {$company->company_name}: subscription #{$subscription->id} superseded by a newer one, skipping");
                    $this->stats['skipped_superseded']++;
                    continue;
                }

                if (SubscriptionExpiryNotification::where('subscription_id', $subscription->id)->where('days_remaining', $days)->exists()) {
                    $this->line("   └─ {$company->company_name}: already notified for {$days} days remaining, skipping");
                    $this->stats['skipped_duplicate']++;
                    continue;
                }

                $this->line("   └─ {$company->company_name} ({$company->company_phone}): {$days} days remaining");

                if ($dryRun) {
                    continue;
                }

                try {
                    $sent = $notificationService->sendSubscriptionExpirySMS($company, $days, $subscription->end_date->format('d/m/Y'));

                    if ($sent) {
                        SubscriptionExpiryNotification::create([
                            'company_id' => $company->id,
                            'subscription_id' => $subscription->id,
                            'days_remaining' => $days,
                            'sent_at' => now(),
                        ]);
                        $this->stats['sent']++;
                    } else {
                        $this->stats['failed']++;
                        Log::error('Subscription expiry SMS failed to send', [
                            'company_id' => $company->id,
                            'subscription_id' => $subscription->id,
                            'days_remaining' => $days,
                        ]);
                    }
                } catch (\Exception $e) {
                    $this->stats['failed']++;
                    Log::error('Subscription expiry notification errored', [
                        'company_id' => $company->id,
                        'subscription_id' => $subscription->id,
                        'days_remaining' => $days,
                        'error' => $e->getMessage(),
                    ]);
                    $this->error("   └─ Failed: {$e->getMessage()}");
                }
            }

            $this->newLine();
        }

        $this->table(['Metric', 'Count'], collect($this->stats)->map(fn ($v, $k) => [$k, $v])->values());

        return 0;
    }

    /**
     * True when a newer subscription row exists for the same company —
     * this one is a stale/replaced record and shouldn't trigger a
     * reminder even if its end_date happens to land on a milestone date.
     */
    private function isSuperseded(Subscription $subscription): bool
    {
        return Subscription::where('company_id', $subscription->company_id)
            ->where(function ($q) use ($subscription) {
                $q->where('end_date', '>', $subscription->end_date)
                    ->orWhere(function ($q2) use ($subscription) {
                        $q2->where('end_date', $subscription->end_date)
                            ->where('id', '>', $subscription->id);
                    });
            })
            ->exists();
    }
}
