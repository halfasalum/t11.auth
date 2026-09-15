<?php

namespace App\Console\Commands;

use App\Http\Controllers\Api\V2\WhatsAppController;
use App\Models\BranchUser;
use App\Models\CustomersZone;
use App\Models\LoanPaymentSchedules;
use App\Models\PaymentSubmissions;
use App\Models\role_permissions;
use App\Models\Subscription;
use App\Models\User;
use App\Models\users_roles;
use App\Models\ZoneUser;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class DailyReport extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:daily-report';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info("Daily Report Date : " . date('Y-m-d'));
        $subsrciptions = Subscription::where('status', 'active')
            ->get();

        foreach ($subsrciptions as $subsrciption) {
            $company = $subsrciption->company_id;
            $today = date('Y-m-d');
            $yesterday = date('Y-m-d', strtotime('-1 day'));
            $companyUsers = User::where('user_company', $company)->get();

            foreach ($companyUsers as $user) {
                $this->generateNotification($user, $today, $yesterday);
            }
        }
    }

    protected function generateNotification($user, $today, $yesterday)
    {
        if (!$user) {
            return;
        }

        $userId = $user->id;

        // Load roles and permissions
        $userRoleIds = users_roles::where('user_id', $userId)
            ->where('user_role_status', 1)
            ->pluck('role_id');

        $userPermissions = role_permissions::whereIn('role_id', $userRoleIds)
            ->where('permission_status', 1)
            ->select('permission_id')        // Explicit select
            ->distinct()
            ->pluck('permission_id')
            ->toArray();
        $this->info("Name : " . $user->name);
        $this->info("User Permissions: " . json_encode($userPermissions));

        // Load zones and branches
        $userZoneIds = ZoneUser::where('user_id', $userId)
            ->where('status', 1)
            ->pluck('zone_id');

        $userBranchIds = BranchUser::where('user_id', $userId)
            ->where('status', 1)
            ->pluck('branch_id');

        // Use constants instead of magic numbers
        if (in_array(21, $userPermissions)) {           // e.g. 21
            // Send CEO notification
            $this->info("Sending report to CEO: $user->name");
            $this->sendCeoNotification($user, $today, $yesterday);
        }

        if ($userZoneIds->isNotEmpty()) {
            // Send Zone Manager notification
            //$this->sendZoneManagerNotification($user, $userZoneIds, $today, $yesterday);
            $this->info("Sending report to Zone Manager: $user->name");
            $this->sendZoneNotification($user, $today, $yesterday);
        }

        if ($userBranchIds->isNotEmpty()) {
            // Send Branch Manager notification
            //$this->sendBranchManagerNotification($user, $userBranchIds, $today, $yesterday);
            $this->info("Sending report to Branch Manager: $user->name");
            $this->sendBranchNotification($user, $today, $yesterday);
        }
    }

    protected function sendZoneNotification($user, $today, $yesterday)
    {
        $whatsappBot = new WhatsAppController();
        $userCompany = $user->user_company;
        $userZoneIds = ZoneUser::where('user_id', $user->id)
            ->where('status', 1)
            ->get()->pluck('zone_id');

        // Yesterday Schedules
        $yesterdaySchedules = LoanPaymentSchedules::where('company', $userCompany)
            ->where('payment_due_date', $yesterday)
            ->where('status', 1)
            ->whereIn('zone', $userZoneIds)
            ->get();

        // Yesterday Paid (Corrected relationship - adjust foreign key if needed)
        $yesterdayPaid = PaymentSubmissions::whereIn('schedule_id', $yesterdaySchedules->pluck('id'))
            ->whereNotIn('submission_status', [3, 9])
            ->get();

        // Yesterday Unpaid
        $yesterdayUnpaid = $yesterdaySchedules->whereNotIn('id', $yesterdayPaid->pluck('schedule_id'));

        // Today Schedules
        $todaySchedules = LoanPaymentSchedules::where('company', $userCompany)
            ->where('payment_due_date', $today)
            ->whereIn('zone', $userZoneIds)
            ->where('status', 1)
            ->get();

        // Calculations
        $yesterdayCount     = $yesterdaySchedules->count();
        $yesterdayTarget    = $yesterdaySchedules->sum('payment_total_amount');
        $yesterdayCollected = $yesterdayPaid->sum('amount');
        $yesterdayBalance   = $yesterdayUnpaid->sum('payment_total_amount');

        $todayCount  = $todaySchedules->count();
        $todayTarget = $todaySchedules->sum('payment_total_amount');

        // Send WhatsApp Report
        if ($yesterdayCount > 0 || $todayCount > 0) {
            $whatsappNumber = $user->whatsapp_number;

            if (!empty($whatsappNumber)) {
                try {
                    $whatsappBot->sendTemplate($whatsappNumber, 'daily_report', [
                        $yesterday,
                        $yesterdayCount,
                        number_format($yesterdayTarget),
                        number_format($yesterdayCollected),
                        number_format($yesterdayBalance),
                        $today,
                        $todayCount,
                        number_format($todayTarget),
                    ]);

                    $this->info("✅ Full daily report sent to Zone Officer: {$user->name}");
                } catch (\Exception $e) {
                    $this->error("❌ Failed to send WhatsApp report: " . $e->getMessage());
                }
            }
        }
    }
    protected function sendBranchNotification($user, $today, $yesterday)
    {
        $whatsappBot = new WhatsAppController();
        $userCompany = $user->user_company;
        $userBranchId = BranchUser::where('user_id', $user->id)
            ->where('status', 1)
            ->get()
            ->pluck('branch_id');

        // Yesterday Schedules
        $yesterdaySchedules = LoanPaymentSchedules::where('company', $userCompany)
            ->where('payment_due_date', $yesterday)
            ->where('status', 1)
            ->whereIn('branch', $userBranchId)
            ->get();

        // Yesterday Paid (Corrected relationship - adjust foreign key if needed)
        $yesterdayPaid = PaymentSubmissions::whereIn('schedule_id', $yesterdaySchedules->pluck('id'))
            ->whereNotIn('submission_status', [3, 9])
            ->get();

        // Yesterday Unpaid
        $yesterdayUnpaid = $yesterdaySchedules->whereNotIn('id', $yesterdayPaid->pluck('schedule_id'));

        // Today Schedules
        $todaySchedules = LoanPaymentSchedules::where('company', $userCompany)
            ->where('payment_due_date', $today)
            ->where('status', 1)
            ->get();

        // Calculations
        $yesterdayCount     = $yesterdaySchedules->count();
        $yesterdayTarget    = $yesterdaySchedules->sum('payment_total_amount');
        $yesterdayCollected = $yesterdayPaid->sum('amount');
        $yesterdayBalance   = $yesterdayUnpaid->sum('payment_total_amount');

        $todayCount  = $todaySchedules->count();
        $todayTarget = $todaySchedules->sum('payment_total_amount');

        // Send WhatsApp Report
        if ($yesterdayCount > 0 || $todayCount > 0) {
            $whatsappNumber = $user->whatsapp_number;

            if (!empty($whatsappNumber)) {
                try {
                    $whatsappBot->sendTemplate($whatsappNumber, 'daily_report', [
                        $yesterday,
                        $yesterdayCount,
                        number_format($yesterdayTarget),
                        number_format($yesterdayCollected),
                        number_format($yesterdayBalance),
                        $today,
                        $todayCount,
                        number_format($todayTarget),
                    ]);

                    $this->info("✅ Full daily report sent to Branch Manager: {$user->name}");
                } catch (\Exception $e) {
                    $this->error("❌ Failed to send WhatsApp report: " . $e->getMessage());
                }
            }
        }
    }

    protected function sendCeoNotification($user, $today, $yesterday)
    {
        $whatsappBot = new WhatsAppController();
        $userCompany = $user->user_company;

        // Yesterday Schedules
        $yesterdaySchedules = LoanPaymentSchedules::where('company', $userCompany)
            ->where('payment_due_date', $yesterday)
            ->where('status', 1)
            ->get();

        // Yesterday Paid (Corrected relationship - adjust foreign key if needed)
        $yesterdayPaid = PaymentSubmissions::whereIn('schedule_id', $yesterdaySchedules->pluck('id'))
            ->whereNotIn('submission_status', [3, 9])
            ->get();

        // Yesterday Unpaid
        $yesterdayUnpaid = $yesterdaySchedules->whereNotIn('id', $yesterdayPaid->pluck('schedule_id'));

        // Today Schedules
        $todaySchedules = LoanPaymentSchedules::where('company', $userCompany)
            ->where('payment_due_date', $today)
            ->where('status', 1)
            ->get();

        // Calculations
        $yesterdayCount     = $yesterdaySchedules->count();
        $yesterdayTarget    = $yesterdaySchedules->sum('payment_total_amount');
        $yesterdayCollected = $yesterdayPaid->sum('amount');
        $yesterdayBalance   = $yesterdayUnpaid->sum('payment_total_amount');

        $todayCount  = $todaySchedules->count();
        $todayTarget = $todaySchedules->sum('payment_total_amount');

        // Send WhatsApp Report
        if ($yesterdayCount > 0 || $todayCount > 0) {
            $whatsappNumber = $user->whatsapp_number;

            if (!empty($whatsappNumber)) {
                try {
                    $whatsappBot->sendTemplate($whatsappNumber, 'daily_report', [
                        $yesterday,
                        $yesterdayCount,
                        number_format($yesterdayTarget),
                        number_format($yesterdayCollected),
                        number_format($yesterdayBalance),
                        $today,
                        $todayCount,
                        number_format($todayTarget),
                    ]);

                    $this->info("✅ Full daily report sent to CEO: {$user->name}");
                } catch (\Exception $e) {
                    $this->error("❌ Failed to send WhatsApp report: " . $e->getMessage());
                }
            }
        }
    }
}
