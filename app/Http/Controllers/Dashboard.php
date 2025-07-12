<?php

namespace App\Http\Controllers;

use App\Models\BranchModel;
use App\Models\CustomersZone;
use App\Models\LoanPayments;
use App\Models\LoanPaymentSchedules;
use App\Models\Loans;
use App\Models\PaymentSubmissions;
use App\Models\Zone;
use App\Models\Customers;
use App\Models\Expenses;
use App\Models\LoanSchedules;
use Illuminate\Http\Request;
use Tymon\JWTAuth\Facades\JWTAuth;
use Carbon\Carbon;
use Illuminate\Validation\ValidationException;

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
        $trends    = $this->collectionTrend($user_zones, $user_branch);
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
        $trends    = $this->collectionTrend($user_zones, $user_branch);
        $todayGoal    = $this->todayTarget($user_zones);
        $finace_kpi = $this->finace_kpi();
        $operational_kpi = $this->operational_kpi();
        $profit_loss    = $this->generateCompanyProfitLossReport();
        $funds          = BranchModel::where('company', $user_company)
            ->where('status', '!=', 3)
            ->select('id', 'branch_name', 'balance')
            ->get();
        $stats      = [
            'all_customers' => $customersData['all_customers'],
            'active_customers' => $customersData['active_customers'],
            'active_loans' => $loansData['active_loans'],
            'completed_loans' => $loansData['completed_loans'],
            'today_target' => ($todayGoal['target']),
            'today_collected' => ($todayGoal['collected']),
            'total_loan_portfolio_value' => ($finace_kpi['total_loan_portfolio_value']),
            'average_loan_amount' => ($finace_kpi['average_loan_amount']),
            'collection_efficiency' => $finace_kpi['collection_efficiency'],
            'approval_rate' => $operational_kpi['approval_rate'],
            'income' => $profit_loss['totalPaidInterest'],
            'expense' => $profit_loss['expense'],
            'funds' => $funds
        ];

        return response()->json([
            'stats' => $stats,
            'trends' => $trends
        ]);
    }

    public function finace_kpi()
    {
        $user = JWTAuth::parseToken()->getPayload();
        $user_company = $user->get('company');
        $user_id = $user->get('user_id');
        $user_zones = $user->get('zonesId');
        $user_branch = $user->get('branchesId');
        $average_loan_amount = 0;
        $total_loan_portfolio_value = Loans::where('company', $user_company)
            ->where('status', 5)
            ->selectRaw('SUM(principal_amount - principal_paid) as total')
            ->value('total');
        $loans = Loans::where('company', $user_company)
            ->where('status', 5)
            ->get();

            if(sizeof($loans) <= 0){
                $total_loan_portfolio_value = 0;
            }else{
                $average_loan_amount = $total_loan_portfolio_value / sizeof($loans);
            }
        

        // Define constants for statuses (replace 11 with a meaningful constant)


        // Get current date (consider timezone configuration in Laravel)
        $currentDate = Carbon::today()->toDateString();

        // Count due payments
        $duePaymentsCount = LoanPaymentSchedules::where('company', $user_company)
            ->where('payment_due_date', '<=', $currentDate)
            ->count();

        // Count received payments
        $receivedPaymentsCount = PaymentSubmissions::where('payment_submissions.company', $user_company)
            ->join('loan_payment_schedule', 'payment_submissions.schedule_id', '=', 'loan_payment_schedule.id')
            ->where('payment_due_date', '<=', $currentDate)
            ->where('submission_status', 11)
            ->count();

        // Calculate collections efficiency
        $collectionsEfficiency = 0;
        if ($duePaymentsCount > 0) {
            $collectionsEfficiency = ($receivedPaymentsCount / $duePaymentsCount) * 100;
            // Round to 2 decimal places for readability
            $collectionsEfficiency = round($collectionsEfficiency, 2);
        }
        return ['total_loan_portfolio_value' => $total_loan_portfolio_value, 'average_loan_amount' => $average_loan_amount, 'collection_efficiency' => $collectionsEfficiency];
    }

    private const STATUS_APPROVED = [5, 6]; // Approved statuses
    private const STATUS_EXCLUDED = [3];
    public function operational_kpi()
    {
        $user = JWTAuth::parseToken()->getPayload();
        $user_company = $user->get('company');

        // Use a single query to get both counts
        $stats = Loans::where('company', $user_company)
            ->selectRaw('
            COUNT(*) as total_loans,
            SUM(CASE WHEN status IN (' . implode(',', self::STATUS_APPROVED) . ') THEN 1 ELSE 0 END) as approved_loans
        ')
            ->whereNotIn('status', self::STATUS_EXCLUDED)
            ->first();

        // Initialize approval rate
        $approval_rate = 0;

        // Calculate approval rate if total_loans is not zero
        if ($stats->total_loans > 0) {
            $approval_rate = ($stats->approved_loans / $stats->total_loans) * 100;
            // Round to 2 decimal places for readability
            $approval_rate = round($approval_rate, 2);
        }

        return ['approval_rate' => $approval_rate];
    }

    public function generateCompanyProfitLossReport()
    {
        // Extract user details from JWT
        $user = JWTAuth::parseToken()->getPayload();
        $user_zones = $user->get('zonesId') ?? [];
        $user_branches = $user->get('branchesId') ?? [];
        $user_company = $user->get('company');
        $user_id = $user->get('user_id');
        $f_start_date = $user->get('f_start_date');
        $f_end_date = $user->get('f_end_date');
        try {
            $start_date =  $f_start_date;
            $end_date =  $f_end_date;
            $expenses = Expenses::where('company_id', $user_company)
                ->where('expense_date', '>=', $start_date)
                ->where('expense_date', '<=', $end_date)
                ->get();
            $totalExpesne = $expenses->sum('amount');
            $data = [];
            $loans = Loans::where(['company' => $user_company])
                ->whereIn('status', [5, 6, 7, 12])
                ->where('start_date', '>=', $start_date)
                ->where('start_date', '<=', $end_date)
                ->get();
            $totalLoanPrincipal = 0;
            $totalLoanInterest  = 0;
            $totalPaidPrincipal = 0;
            $totalPaidInterest  = 0;
            $totalLoanPenalty  = 0;
            if (sizeof($loans) > 0) {
                foreach ($loans as $loan) {
                    $totalLoanPrincipal += $loan->principal_amount;
                    $totalLoanInterest  += $loan->interest_amount;
                    $totalLoanPenalty   += $loan->penalty_amount;
                    $payaments = LoanSchedules::where('loan_payment_schedule.loan_number', $loan->loan_number)
                        ->join('payment_submissions', 'payment_submissions.schedule_id', '=', 'loan_payment_schedule.id')
                        ->where('submission_status', 11)
                        ->where('loan_payment_schedule.status', 1)
                        ->where('payment_due_date', '>=', $start_date)
                        ->where('payment_due_date', '<=', $end_date)
                        ->where('loan_payment_schedule.company', $user_company)
                        ->get();
                    if (sizeof($payaments) > 0) {
                        foreach ($payaments as $payament) {
                            $totalPaidPrincipal += $payament->paid_principal;
                            $totalPaidInterest  += $payament->paid_interest;
                        }
                    }
                }
            }
            $data = [
                'totalLoans'            => $loans->count(),
                'totalLoanPrincipal'    => $totalLoanPrincipal,
                'totalLoanInterest'     => $totalLoanInterest,
                'totalPaidPrincipal'    => $totalPaidPrincipal,
                'totalPaidInterest'     => $totalPaidInterest,
                'expense'               => $totalExpesne,
                'totalLoanPenalty'      => $totalLoanPenalty,
                'netProfit'             => $totalPaidInterest - $totalExpesne,
                'percentage_principal_returned' => $totalLoanPrincipal <= 0 ? 0 : number_format(($totalPaidPrincipal / $totalLoanPrincipal) * 100, 2) . '%',
                'percentage_interest_returned' => $totalLoanInterest <= 0 ? : number_format(($totalPaidInterest / $totalLoanInterest) * 100, 2) . '%'
            ];



            // Step 7: Handle output
           
                return $data;
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'errors' => $e->errors(),
            ], 422);
        }
    }

    public function customers($zones = [], $branches = [])
    {
        $user = JWTAuth::parseToken()->getPayload();
        $user_company = $user->get('company');
        $all = 0;
        $active = 0;
        $controls = $user->get('controls');
        if (in_array(21, $controls)) {
            $customersCount = Customers::where('company_id', $user_company)
                ->join('customers_zones', 'customers_zones.customer_id', '=', 'customers.id')
                ->whereIn('customers_zones.status', [1, 2, 4])
                //->selectRaw('status, COUNT(*) as count')
                //->groupBy('status')
                //->pluck('count', 'status');
                ->distinct('customer_id')
                ->count('customer_id');
            $all = $customersCount;
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
                $completed = $loanCounts[6] ?? 0;
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
        $target = LoanPaymentSchedules::where(['payment_due_date' => $today, 'company' => $user_company])
            ->when(!empty($zones), function ($query) use ($zones) {
                $query->whereIn('zone', $zones);
            })
            ->sum('payment_total_amount');

        // Get today's collected amount
        $collected = PaymentSubmissions::where(['payment_due_date' => $today, 'payment_submissions.company' => $user_company])
            ->join('loan_payment_schedule', 'payment_submissions.schedule_id', '=', 'loan_payment_schedule.id')
            ->whereIn('payment_submissions.submission_status', [4, 8, 11])
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
