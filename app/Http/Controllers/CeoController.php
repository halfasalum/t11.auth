<?php

namespace App\Http\Controllers;

use App\Models\Customers;
use App\Models\CustomersZone;
use App\Models\LoanPaymentSchedules;
use App\Models\Loans;
use App\Models\PaymentSubmissions;
use App\Models\Zone;
use Tymon\JWTAuth\Facades\JWTAuth;
use Carbon\Carbon;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class CeoController extends Controller
{
    public function targetsData(Request $request)
    {
        $user = JWTAuth::parseToken()->getPayload();
        $user_company = $user->get('company');
        $today = date("Y-m-d");
        $zones = $user->get('zonesId');
        $yesterday = date("Y-m-d", strtotime("$today -1 day"));
        $collected_yesterday = 0;
        $target = 0;
        $target_yesterady = 0;
        $collected = 0;
        $controls = $user->get('controls');
        if (!in_array(21, $controls)) {
            return [
                'date' => $today,
                'target' => $target,
                'collected' => $collected,
                'target_yesterday' => $target_yesterady,
                'collected_yesterday' => $collected_yesterday,
            ];
        }

        // Get today's target
        $target = LoanPaymentSchedules::where(['loan_payment_schedule.payment_due_date' => $today, 'loan_payment_schedule.company' => $user_company, 'loan_payment_schedule.status' => 1])
            ->join('loans', 'loans.loan_number', '=', 'loan_payment_schedule.loan_number')
            ->whereIn('loans.status', [5, 12])
            /* ->when(!empty($zones), function ($query) use ($zones) {
                $query->whereIn('loan_payment_schedule.zone', $zones);
            }) */
            ->sum('payment_total_amount');

        $target_yesterady = LoanPaymentSchedules::where(['loan_payment_schedule.payment_due_date' => $yesterday, 'loan_payment_schedule.company' => $user_company, 'loan_payment_schedule.status' => 1])
            ->join('loans', 'loans.loan_number', '=', 'loan_payment_schedule.loan_number')
            ->whereIn('loans.status', [5, 12])
            /* ->when(!empty($zones), function ($query) use ($zones) {
                $query->whereIn('loan_payment_schedule.zone', $zones);
            }) */
            ->sum('payment_total_amount');

        $collected = PaymentSubmissions::where(['payment_due_date' => $today, 'payment_submissions.company' => $user_company])
            ->join('loan_payment_schedule', 'payment_submissions.schedule_id', '=', 'loan_payment_schedule.id')
            ->whereIn('payment_submissions.submission_status', [4, 8, 11])
            /* ->when(!empty($zones), function ($query) use ($zones) {
                $query->whereIn('payment_submissions.zone', $zones);
            }) */
            ->sum('amount');

        $collected_yesterday = PaymentSubmissions::where(['payment_due_date' => $yesterday, 'payment_submissions.company' => $user_company])
            ->join('loan_payment_schedule', 'payment_submissions.schedule_id', '=', 'loan_payment_schedule.id')
            ->whereIn('payment_submissions.submission_status', [4, 8, 11])
           /*  ->when(!empty($zones), function ($query) use ($zones) {
                $query->whereIn('payment_submissions.zone', $zones);
            }) */
            ->sum('amount');

        return [
            'date' => $today,
            'target' => $target,
            'collected' => $collected,
            'target_yesterday' => $target_yesterady,
            'collected_yesterday' => $collected_yesterday,
        ];
    }

    public function ceoStats()
    {
        $user = JWTAuth::parseToken()->getPayload();
        $user_company = $user->get('company');
        $zones = $user->get('zonesId');
        $controls = $user->get('controls');

        // Early return if no zones
        if (!in_array(21, $controls)) {
            return [
                'all_customers' => 0,
                'active_customers' => 0,
                'active_loans' => 0,
                'completed_loans' => 0,
            ];
        }

        // Convert zones to array if it's not already
        $zones = is_array($zones) ? $zones : [$zones];

        // Use database transactions or parallel queries if supported
        // Run queries in parallel (if using Laravel 8+ with job batching or parallel collection)
        $results = $this->fetchStatisticsInParallel($user_company);

        // Alternative: Single optimized query approach
        // $results = $this->fetchStatisticsWithSingleQuery($zones);

        return $results;
    }

    /**
     * Fetch all statistics in parallel using Laravel's support
     */
    private function fetchStatisticsInParallel($company)
    {
        $allCustomers = 0;
        $activeCustomers = 0;
        $activeLoans = 0;
        $completedLoans = 0;

        // Use DB facade for raw queries when needed
        $customerStats = CustomersZone::where('company_id', $company)
            ->selectRaw('status, COUNT(*) as count')
            ->join('customers', 'customers.id', '=', 'customers_zones.customer_id')
            ->whereIn('customers_zones.status', [1, 2, 4])
            ->groupBy('status')
            ->get();

        $allCustomers = $customerStats->sum('count');

        // Use a single query for loan-related statistics
        $loanStats = Loans::where('company', $company)
            ->selectRaw('
            COUNT(DISTINCT CASE WHEN status IN (5, 12) THEN customer END) as active_customers_count,
            SUM(CASE WHEN status = 5 THEN 1 ELSE 0 END) as status_5_count,
            SUM(CASE WHEN status = 6 THEN 1 ELSE 0 END) as status_6_count,
            SUM(CASE WHEN status = 12 THEN 1 ELSE 0 END) as status_12_count
        ')
            ->first();

        $activeCustomers = $loanStats->active_customers_count ?? 0;
        $activeLoans = ($loanStats->status_5_count ?? 0) + ($loanStats->status_12_count ?? 0);
        $completedLoans = $loanStats->status_6_count ?? 0;

        return response()->json([
            'all_customers' => $allCustomers,
            'active_customers' => $activeCustomers,
            'active_loans' => $activeLoans,
            'completed_loans' => $completedLoans,
        ]);
    }



    /**
     * With caching for frequently accessed data
     */
    public function officerStatsWithCache()
    {
        $user = JWTAuth::parseToken()->getPayload();
        $zones = $user->get('zonesId');
        $userId = $user->get('id');

        $cacheKey = "officer_stats_{$userId}";
        $cacheDuration = now()->addMinutes(5); // Cache for 15 minutes

        return Cache::remember($cacheKey, $cacheDuration, function () use ($zones) {
            return $this->fetchStatisticsInParallel($zones);
        });
    }

    public function fetchYesterdayTargets() {}
}
