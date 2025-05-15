<?php

namespace App\Http\Controllers;

use App\Models\CustomersZone;
use App\Models\LoanPayments;
use App\Models\LoanPaymentSchedules;
use App\Models\Loans;
use App\Models\PaymentSubmissions;
use App\Models\Zone;
use Illuminate\Http\Request;
use Tymon\JWTAuth\Facades\JWTAuth;

class Dashboard extends Controller
{
    public function officer_dashboard()
    {
        $user = JWTAuth::parseToken()->getPayload();
        $user_company = $user->get('company');
        $user_id = $user->get('user_id');
        $user_zones = $user->get('zonesId');
        $user_branch = $user->get('branchesId');
        $customersData = $this->customers($user_zones, $user_branch);
        $loansData = $this->loans($user_zones, $user_branch);
        $trends    = $this->collectionTrend($user_zones);
        $todayGoal    = $this->todayTarget($user_zones);
        $stats      = [
            'all_customers' => $customersData['all_customers'],
            'active_customers' => $customersData['active_customers'],
            'active_loans' => $loansData['active_loans'],
            'completed_loans' => $loansData['completed_loans'],
            'today_target' => number_format($todayGoal['target']),
            'today_collected' => number_format($todayGoal['collected']),
        ];

        return response()->json([
            'stats' => $stats,
            'trends' => $trends
        ]);
    }
    public function branch_dashboard()
    {
        $user = JWTAuth::parseToken()->getPayload();
        $user_company = $user->get('company');
        $user_id = $user->get('user_id');
        $user_zones = $user->get('zonesId');
        $user_branch = $user->get('branchesId');
        $customersData = $this->customers($user_zones, $user_branch);
        $loansData = $this->loans($user_zones, $user_branch);
        $trends    = $this->collectionTrend($user_zones,$user_branch);
        $todayGoal    = $this->todayTarget($user_zones);
        $stats      = [
            'all_customers' => $customersData['all_customers'],
            'active_customers' => $customersData['active_customers'],
            'active_loans' => $loansData['active_loans'],
            'completed_loans' => $loansData['completed_loans'],
            'today_target' => number_format($todayGoal['target']),
            'today_collected' => number_format($todayGoal['collected']),
        ];

        return response()->json([
            'stats' => $stats,
            'trends' => $trends
        ]);
    }
    public function manager_dashboard()
    {
        $user = JWTAuth::parseToken()->getPayload();
        $user_company = $user->get('company');
        $user_id = $user->get('user_id');
        $user_zones = $user->get('zonesId');
        $user_branch = $user->get('branchesId');
        $customersData = $this->customers();
        $loansData = $this->loans($user_zones, $user_branch);
        $trends    = $this->collectionTrend($user_zones,$user_branch);
        $todayGoal    = $this->todayTarget($user_zones);
        $finace_kpi = $this->finace_kpi();
        $stats      = [
            'all_customers' => $customersData['all_customers'],
            'active_customers' => $customersData['active_customers'],
            'active_loans' => $loansData['active_loans'],
            'completed_loans' => $loansData['completed_loans'],
            'today_target' => number_format($todayGoal['target']),
            'today_collected' => number_format($todayGoal['collected']),
            'total_loan_portfolio_value' => number_format($finace_kpi['total_loan_portfolio_value']),
            'average_loan_amount' => number_format($finace_kpi['average_loan_amount']),
        ];

        return response()->json([
            'stats' => $stats,
            'trends' => $trends
        ]);
    }

    public function finace_kpi(){
        $user = JWTAuth::parseToken()->getPayload();
        $user_company = $user->get('company');
        $user_id = $user->get('user_id');
        $user_zones = $user->get('zonesId');
        $user_branch = $user->get('branchesId');
        $total_loan_portfolio_value = Loans::where('company', $user_company)
        ->where('status', 5)
        ->selectRaw('SUM(principal_amount - principal_paid) as total')
        ->value('total');
        $loans = Loans::where('company', $user_company)
        ->where('status', 5)
        ->get();
        $average_loan_amount = $total_loan_portfolio_value / sizeof($loans);
        return ['total_loan_portfolio_value' => $total_loan_portfolio_value, 'average_loan_amount' => $average_loan_amount];
    }

    public function customers($zones = [], $branches = [])
    {
        $user = JWTAuth::parseToken()->getPayload();
        $user_company = $user->get('company');
        $all = 0;
        $active = 0;
        $controls = $user->get('controls');
        if (in_array(21, $controls)) {
            $customersCount = CustomersZone::where('company_id', $user_company)
                ->whereNotIn('status', [3, 9])
                ->selectRaw('status, COUNT(*) as count')
                ->groupBy('status')
                ->pluck('count', 'status');
            $all = $customersCount->sum();
            $activeCustomersCount = Loans::where('company', $user_company)
                ->where('status', 5)
                ->distinct('customer')
                ->count('customer');
            $active = $activeCustomersCount ?? 0;
        } elseif (in_array(20, $controls)) {
            if (!empty($branches)) {
                $customersCount = CustomersZone::whereIn('branch_id', $zones)
                    ->selectRaw('status, COUNT(*) as count')
                    ->groupBy('status')
                    ->pluck('count', 'status');

                // Total customers (sum of all counts)
                $all = $customersCount->sum();


                $activeCustomersCount = Loans::whereIn('zone', $zones)
                    ->where('status', 5)
                    ->distinct('customer')
                    ->count('customer');
                $active = $activeCustomersCount ?? 0;
            }
        } elseif (in_array(19, $controls)) {
            if (!empty($zones)) {
                $customersCount = CustomersZone::whereIn('zone_id', $zones)
                    ->selectRaw('status, COUNT(*) as count')
                    ->groupBy('status')
                    ->pluck('count', 'status');

                // Total customers (sum of all counts)
                $all = $customersCount->sum();


                $activeCustomersCount = Loans::whereIn('zone', $zones)
                    ->where('status', 5)
                    ->distinct('customer')
                    ->count('customer');
                $active = $activeCustomersCount ?? 0;
            }
        }

        return ["all_customers" => $all, "active_customers" => $active];
    }

    public function loans($zones = [], $branches = [])
    {
        $user = JWTAuth::parseToken()->getPayload();
        $user_company = $user->get('company');
        $controls = $user->get('controls');
        $active = 0;
        $completed = 0;
        if (in_array(21, $controls)) {
            $loanCounts = Loans::where('company', $user_company)
                ->whereIn('status', [5, 6])
                ->selectRaw('status, COUNT(*) as count')
                ->groupBy('status')
                ->pluck('count', 'status');

            $active = $loanCounts[5] ?? 0;
        } elseif (in_array(20, $controls)) {
            if (!empty($branches)) {
                $zones = Zone::whereIn("branch", $branches)
                    ->get();
                $zoneIds = $zones->pluck('id');
                $loanCounts = Loans::whereIn('zone', $zoneIds)
                    ->whereIn('status', [5, 6])
                    ->selectRaw('status, COUNT(*) as count')
                    ->groupBy('status')
                    ->pluck('count', 'status');

                $active = $loanCounts[5] ?? 0;
                $completed = $loanCounts[6] ?? 0;
            }
        } elseif (in_array(19, $controls)) {
            if (!empty($zones)) {
                $loanCounts = Loans::whereIn('zone', $zones)
                    ->whereIn('status', [5, 6])
                    ->selectRaw('status, COUNT(*) as count')
                    ->groupBy('status')
                    ->pluck('count', 'status');

                $active = $loanCounts[5] ?? 0;
                $completed = $loanCounts[6] ?? 0;
            }
        }

        return [
            "active_loans" => $active,
            "completed_loans" => $completed
        ];
    }

    public function collectionTrend($zones = [], $branches = [])
    {
        $user = JWTAuth::parseToken()->getPayload();
        $user_company = $user->get('company');
        $controls = $user->get('controls');
        $results = [];
        $today = date("Y-m-d");
        if (in_array(21, $controls)) {
            $trends = LoanPaymentSchedules::selectRaw('payment_due_date, SUM(payment_total_amount) as total_due_amount')
                ->where('payment_due_date', '<=', $today)
                ->where('company', $user_company)
                ->groupBy('payment_due_date')
                ->orderBy('payment_due_date', 'desc')
                ->limit(7)
                ->get()
                ->sortBy('payment_due_date')
                ->values();

            $dates = $trends->pluck('payment_due_date')->toArray();

            $payments = LoanPayments::selectRaw('payment_date, SUM(amount_paid) as total_paid')
                ->whereIn('payment_date', $dates)
                ->where('company', $user_company)
                ->groupBy('payment_date')
                ->get()
                ->keyBy('payment_date');

            $results = $trends->map(function ($item) use ($payments) {
                $paymentDate = $item->payment_due_date;
                return [
                    'date' => $paymentDate,
                    'target' => $item->total_due_amount,
                    'collected' => $payments[$paymentDate]->total_paid ?? 0,
                ];
            });
        } elseif (in_array(20, $controls)) {
            if (!empty($branches)) {
                $zones = Zone::whereIn("branch", $branches)
                    ->get();
                $zoneIds = $zones->pluck('id');
                $trends = LoanPaymentSchedules::selectRaw('payment_due_date, SUM(payment_total_amount) as total_due_amount')
                    ->where('payment_due_date', '<=', $today)
                    ->whereIn('zone', $zoneIds)
                    ->groupBy('payment_due_date')
                    ->orderBy('payment_due_date', 'desc')
                    ->limit(7)
                    ->get()
                    ->sortBy('payment_due_date')
                    ->values();

                $dates = $trends->pluck('payment_due_date')->toArray();

                $payments = LoanPayments::selectRaw('payment_date, SUM(amount_paid) as total_paid')
                    ->whereIn('payment_date', $dates)
                    ->whereIn('zone', $zoneIds)
                    ->groupBy('payment_date')
                    ->get()
                    ->keyBy('payment_date');

                $results = $trends->map(function ($item) use ($payments) {
                    $paymentDate = $item->payment_due_date;
                    return [
                        'date' => $paymentDate,
                        'target' => $item->total_due_amount,
                        'collected' => $payments[$paymentDate]->total_paid ?? 0,
                    ];
                });
            }
        } elseif (in_array(19, $controls)) {
            if (!empty($zones)) {
                $trends = LoanPaymentSchedules::selectRaw('payment_due_date, SUM(payment_total_amount) as total_due_amount')
                    ->where('payment_due_date', '<=', $today)
                    ->whereIn('zone', $zones)
                    ->groupBy('payment_due_date')
                    ->orderBy('payment_due_date', 'desc')
                    ->limit(7)
                    ->get()
                    ->sortBy('payment_due_date')
                    ->values();

                $dates = $trends->pluck('payment_due_date')->toArray();

                $payments = LoanPayments::selectRaw('payment_date, SUM(amount_paid) as total_paid')
                    ->whereIn('payment_date', $dates)
                    ->whereIn('zone', $zones)
                    ->groupBy('payment_date')
                    ->get()
                    ->keyBy('payment_date');

                $results = $trends->map(function ($item) use ($payments) {
                    $paymentDate = $item->payment_due_date;
                    return [
                        'date' => $paymentDate,
                        'target' => $item->total_due_amount,
                        'collected' => $payments[$paymentDate]->total_paid ?? 0,
                    ];
                });
            }
        }


        return $results;
    }

    public function todayTarget($zones = [], $branches = [])
    {
        $user = JWTAuth::parseToken()->getPayload();
        $user_company = $user->get('company');
        $today = date("Y-m-d");

        // Get today's target
        $target = LoanPaymentSchedules::where(['payment_due_date'=>$today,'company'=>$user_company])
            ->when(!empty($zones), function ($query) use ($zones) {
                $query->whereIn('zone', $zones);
            })
            ->sum('payment_total_amount');

        // Get today's collected amount
        $collected = PaymentSubmissions::where(['payment_due_date'=>$today,'payment_submissions.company'=>$user_company])
        ->join('loan_payment_schedule', 'payment_submissions.schedule_id', '=', 'loan_payment_schedule.id')
        ->whereIn('payment_submissions.submission_status', [4,8,11])
            ->when(!empty($zones), function ($query) use ($zones) {
                $query->whereIn('payment_submissions.zone', $zones);
            })
            ->sum('amount');

        return [
            'date' => $today,
            'target' => $target,
            'collected' => $collected,
        ];

        return $results;
    }
}
