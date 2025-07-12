<?php

namespace App\Console\Commands;

use App\Models\Subscriptions;
use Illuminate\Console\Command;

class CheckCompanySubscription extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:check-company-subscription';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Check expired company subscription and notify the admin';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $subscriptions = Subscriptions::where('status', 1)
            ->where('end_date', '<', now())
            ->get();
        foreach ($subscriptions as $subscription) {
            $subscription->status = 2; // Mark as expired
            $subscription->save();
            // Notify the admin or take necessary actions
            $this->info("Subscription for company ID {$subscription->company_id} has expired.");
        }
    }
}
