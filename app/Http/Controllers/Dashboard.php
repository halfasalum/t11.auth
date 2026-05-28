<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Api\V2\BaseController;
use App\Models\Accounts;
use App\Models\BranchModel;
use App\Models\Company;
use App\Models\CustomersZone;
use App\Models\LoanPayments;
use App\Models\LoanPaymentSchedules;
use App\Models\Loans;
use App\Models\LoansProducts;
use App\Models\PaymentSubmissions;
use App\Models\Zone;
use App\Models\Customers;
use App\Models\Expenses;
use App\Models\LoanSchedules;
use App\Models\role_permissions;
use App\Models\User;
use App\Models\UserLog;
use App\Models\users_roles;
use App\Models\ZoneUser;
use Illuminate\Http\Request;
use Tymon\JWTAuth\Facades\JWTAuth;
use Carbon\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class Dashboard extends BaseController
{
    /**
     * LOAN OFFICER DASHBOARD
     * Permission: 19
     */
    public function officer_dashboard()
    {
        try {
            $user_company = $this->getCompanyId();
            $user_id = $this->getUserId();
            $user_zones = $this->getUserZones();
            $user_branches = $this->getUserBranches();

            // Get zone IDs for filtering
            $zoneIds = $user_zones;

            // Get officer's customers
            $customersData = $this->getOfficerCustomers($zoneIds, $user_company);

            // Get officer's loans
            $loansData = $this->getOfficerLoans($zoneIds, $user_company);

            // Get today's collections
            $todayGoal = $this->getTodayCollection($zoneIds, [], $user_company);

            // Get collection trend (last 7 days)
            $collectionTrend = $this->getCollectionTrend($zoneIds, [], $user_company);

            // Get upcoming payments (next 7 days)
            $upcomingPayments = $this->getUpcomingPayments($zoneIds, $user_company);

            // Get recent customers
            $recentCustomers = $this->getRecentCustomers($zoneIds, $user_company, 10);

            // Get pending loan applications
            $pendingLoans = $this->getPendingLoans($zoneIds, $user_company);

            // Get collection efficiency
            $collectionEfficiency = $this->getCollectionEfficiency($zoneIds, $user_company);

            // Get payment method breakdown
            $paymentMethods = $this->getPaymentMethodBreakdown($zoneIds, $user_company);

            // Get top customers by loan amount
            $topCustomers = $this->getTopCustomersByLoan($zoneIds, $user_company, 5);

            // Get officer's performance metrics
            $officerPerformance = $this->getOfficerPerformanceMetrics($user_id, $user_zones, $user_company);

            return response()->json([
                'success' => true,
                'data' => [
                    'stats' => [
                        'total_customers' => $customersData['total'],
                        'active_customers' => $customersData['active'],
                        'active_loans' => $loansData['active'],
                        'completed_loans' => $loansData['completed'],
                        'today_target' => (float) $todayGoal['target'],
                        'today_collected' => (float) $todayGoal['collected'],
                        'today_efficiency' => $todayGoal['target'] > 0 ? round(($todayGoal['collected'] / $todayGoal['target']) * 100, 2) : 0,
                        'collection_efficiency' => $collectionEfficiency,
                        'pending_approvals' => $pendingLoans['count'],
                        'total_outstanding' => $loansData['outstanding'],
                        'overdue_amount' => $loansData['overdue_amount'],
                        'overdue_customers' => $loansData['overdue_count'],
                    ],
                    'today_payments' => $this->getTodayPaymentsList($zoneIds, $user_company),
                    'overdue_payments' => $this->getOverduePaymentsList($zoneIds, $user_company),
                    'pending_loans' => $pendingLoans['loans'],
                    'upcoming_payments' => $upcomingPayments,
                    'recent_customers' => $recentCustomers,
                    'collection_trend' => $collectionTrend,
                    'payment_methods' => $paymentMethods,
                    'top_customers' => $topCustomers,
                    'officer_performance' => $officerPerformance,
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Officer dashboard error: ' . $e->getMessage());
            return $this->errorResponse('Failed to load officer dashboard: ' . $e->getMessage(), 500);
        }
    }

    /**
     * BRANCH INCHARGE DASHBOARD
     * Permission: 20
     */
    public function branch_dashboard()
    {
        try {
            $user_company = $this->getCompanyId();
            $user_id = $this->getUserId();
            $user_zones = $this->getUserZones();
            $user_branches = $this->getUserBranches();

            // Get branch IDs
            $branchIds = $user_branches;

            // Get zones under these branches
            $zoneIds = Zone::whereIn('branch', $branchIds)->pluck('id')->toArray();

            // Get branch customers
            $customersData = $this->getBranchCustomers($branchIds, $zoneIds, $user_company);

            // Get branch loans
            $loansData = $this->getBranchLoans($branchIds, $zoneIds, $user_company);

            // Get today's collection
            $todayGoal = $this->getTodayCollection($zoneIds, $branchIds, $user_company);

            // Get collection trend
            $collectionTrend = $this->getCollectionTrend($zoneIds, $branchIds, $user_company);

            // Get officer performance
            $officerPerformance = $this->getBranchOfficerPerformance($branchIds, $zoneIds, $user_company);

            // Get zone breakdown
            $zonePerformance = $this->getZonePerformance($zoneIds, $user_company);

            // Get pending approvals
            $pendingApprovals = $this->getPendingApprovals($user_company, $branchIds, $zoneIds);

            // Get PAR (Portfolio at Risk)
            $parData = $this->getPortfolioAtRisk($branchIds, $zoneIds, $user_company);

            // Get branch financials
            $branchFinancials = $this->getBranchFinancials($branchIds, $user_company);

            // Get product performance
            $productPerformance = $this->getProductPerformance($branchIds, $zoneIds, $user_company);

            // Get collection by payment method
            $paymentMethods = $this->getPaymentMethodBreakdown($zoneIds, $user_company);

            return response()->json([
                'success' => true,
                'data' => [
                    'stats' => [
                        'total_customers' => $customersData['total'],
                        'active_customers' => $customersData['active'],
                        'active_loans' => $loansData['active'],
                        'completed_loans' => $loansData['completed'],
                        'total_portfolio' => (float) $loansData['total_portfolio'],
                        'today_target' => (float) $todayGoal['target'],
                        'today_collected' => (float) $todayGoal['collected'],
                        'today_efficiency' => $todayGoal['target'] > 0 ? round(($todayGoal['collected'] / $todayGoal['target']) * 100, 2) : 0,
                        'par_30' => (float) $parData['par_30'],
                        'par_60' => (float) $parData['par_60'],
                        'par_90' => (float) $parData['par_90'],
                        'par_percentage' => $parData['percentage'],
                        'collection_efficiency' => $loansData['collection_efficiency'],
                        'default_rate' => $loansData['default_rate'],
                    ],
                    'officer_performance' => $officerPerformance,
                    'zone_performance' => $zonePerformance,
                    'pending_approvals' => $pendingApprovals,
                    'collection_trend' => $collectionTrend,
                    'branch_financials' => $branchFinancials,
                    'product_performance' => $productPerformance,
                    'payment_methods' => $paymentMethods,
                    'portfolio_at_risk' => $parData['breakdown'],
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Branch dashboard error: ' . $e->getMessage());
            return $this->errorResponse('Failed to load branch dashboard: ' . $e->getMessage(), 500);
        }
    }

    /**
     * MANAGER DASHBOARD
     * Permission: 21
     */
    public function manager_dashboard()
    {
        try {
            $user_company = $this->getCompanyId();
            $user_id = $this->getUserId();

            // Get company-wide data
            $customersData = $this->getCompanyCustomers($user_company);
            $loansData = $this->getCompanyLoans($user_company);
            $todayGoal = $this->getTodayCollection([], [], $user_company);

            // Financial KPIs
            $financialKPI = $this->getFinancialKPI($user_company);

            // Operational KPIs
            $operationalKPI = $this->getOperationalKPI($user_company);

            // Profit & Loss
            $profitLoss = $this->getProfitLoss($user_company);

            // Branch performance
            $branchPerformance = $this->getBranchPerformanceComparison($user_company);

            // Zone performance
            $zoneIds = Zone::where('company', $user_company)->pluck('id')->toArray();
            $zonePerformance = $this->getZonePerformance($zoneIds, $user_company);

            // Product performance
            $productPerformance = $this->getProductPerformanceComparison($user_company);

            // Collection trend
            $collectionTrend = $this->getCollectionTrend([], [], $user_company);

            // PAR trend
            $parTrend = $this->getParTrend($user_company);

            // Customer acquisition trend
            $customerAcquisition = $this->getCustomerAcquisitionTrend($user_company);

            // Risk analytics
            $riskAnalytics = $this->getRiskAnalytics($user_company);

            // Top customers
            $topCustomers = $this->getTopCompanyCustomers($user_company, 10);

            // Pending approvals
            $pendingApprovals = $this->getManagerPendingApprovals($user_company);

            // Cash flow
            $cashFlow = $this->getCashFlow($user_company);

            // Staff performance
            $staffPerformance = $this->getStaffPerformance($user_company);

            // Format Efficiency Response
            $efficiencyData = [
                'today' => [
                    'branches' => [],
                    'zones' => []
                ],
                'yesterday' => [
                    'branches' => [],
                    'zones' => []
                ]
            ];

            $todayDate = Carbon::today()->toDateString();
            $yesterdayDate = Carbon::yesterday()->toDateString();

            foreach ($branchPerformance as $branch) {
                $efficiencyData['today']['branches'][] = [
                    'name' => $branch['branch_name'],
                    'date' => $todayDate,
                    'target' => $branch['today_target'],
                    'collected' => $branch['today_collected'],
                    'efficiency' => $branch['today_efficiency']
                ];
                $efficiencyData['yesterday']['branches'][] = [
                    'name' => $branch['branch_name'],
                    'date' => $yesterdayDate,
                    'target' => $branch['yesterday_target'],
                    'collected' => $branch['yesterday_collected'],
                    'efficiency' => $branch['yesterday_efficiency']
                ];
            }

            foreach ($zonePerformance as $zone) {
                $efficiencyData['today']['zones'][] = [
                    'name' => $zone['zone_name'],
                    'date' => $todayDate,
                    'target' => $zone['today_target'],
                    'collected' => $zone['today_collected'],
                    'efficiency' => $zone['today_efficiency']
                ];
                $efficiencyData['yesterday']['zones'][] = [
                    'name' => $zone['zone_name'],
                    'date' => $yesterdayDate,
                    'target' => $zone['yesterday_target'],
                    'collected' => $zone['yesterday_collected'],
                    'efficiency' => $zone['yesterday_efficiency']
                ];
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'stats' => [
                        'total_customers' => $customersData['total'],
                        'active_customers' => $customersData['active'],
                        'total_loans' => $loansData['total'],
                        'active_loans' => $loansData['active'],
                        'completed_loans' => $loansData['completed'],
                        'defaulted_loans' => $loansData['defaulted'],
                        'total_portfolio' => (float) $loansData['total_portfolio'],
                        'today_target' => (float) $todayGoal['target'],
                        'today_collected' => (float) $todayGoal['collected'],
                        'today_efficiency' => $todayGoal['target'] > 0 ? round(($todayGoal['collected'] / $todayGoal['target']) * 100, 2) : 0,
                        'yesterday_target' => (float) ($todayGoal['target_yesterday'] ?? 0),
                        'yesterday_collected' => (float) ($todayGoal['collected_yesterday'] ?? 0),
                        'yesterday_efficiency' => (!empty($todayGoal['target_yesterday']) && $todayGoal['target_yesterday'] > 0) ? round(($todayGoal['collected_yesterday'] / $todayGoal['target_yesterday']) * 100, 2) : 0,
                        'collection_efficiency' => $financialKPI['collection_efficiency'],
                        'average_loan_size' => (float) $financialKPI['average_loan_size'],
                        'approval_rate' => $operationalKPI['approval_rate'],
                        'average_approval_time' => $operationalKPI['average_approval_time'],
                        'par_30' => (float) $financialKPI['par_30'],
                        'par_60' => (float) $financialKPI['par_60'],
                        'par_90' => (float) $financialKPI['par_90'],
                        'default_rate' => $loansData['default_rate'],
                    ],
                    'financial_kpi' => $financialKPI,
                    'operational_kpi' => $operationalKPI,
                    'profit_loss' => $profitLoss,
                    'branch_performance' => $branchPerformance,
                    'zone_performance' => $zonePerformance,
                    'efficiency' => $efficiencyData,
                    'product_performance' => $productPerformance,
                    'collection_trend' => $collectionTrend,
                    'par_trend' => $parTrend,
                    'customer_acquisition' => $customerAcquisition,
                    'risk_analytics' => $riskAnalytics,
                    'top_customers' => $topCustomers,
                    'pending_approvals' => $pendingApprovals,
                    'cash_flow' => $cashFlow,
                    'staff_performance' => $staffPerformance,
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Manager dashboard error: ' . $e->getMessage());
            return $this->errorResponse('Failed to load manager dashboard: ' . $e->getMessage(), 500);
        }
    }

    /**
     * SUPER ADMIN DASHBOARD
     * Permission: 22
     */
    public function admin_dashboard(Request $request)
    {
        try {

            $commands = [
                'config:clear',
                'cache:clear',
                'route:clear',
                'view:clear',
                'optimize:clear',

                // Production caches
                'config:cache',
                'route:cache',
                'view:cache',
            ];

            $results = [];

            foreach ($commands as $command) {
                Artisan::call($command);

                $results[] = [
                    'command' => $command,
                    'output' => Artisan::output()
                ];
            }

            $companyId = $request->get('company_id');

            // Multi-company overview
            $companiesOverview = $this->getCompaniesOverview();

            // Consolidated financials
            $consolidatedFinancials = $this->getConsolidatedFinancials();

            // System health
            $systemHealth = $this->getSystemHealth();

            // User activity
            $userActivity = $this->getUserActivity();

            // Subscription summary
            $subscriptionSummary = $this->getSubscriptionSummary();

            // Platform analytics
            $platformAnalytics = $this->getPlatformAnalytics();

            // Recent activities
            $recentActivities = $this->getRecentActivities();

            return response()->json([
                'success' => true,
                'data' => [
                    'companies_overview' => $companiesOverview,
                    'consolidated_financials' => $consolidatedFinancials,
                    'system_health' => $systemHealth,
                    'user_activity' => $userActivity,
                    'subscription_summary' => $subscriptionSummary,
                    'platform_analytics' => $platformAnalytics,
                    'recent_activities' => $recentActivities,
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Admin dashboard error: ' . $e->getMessage());
            return $this->errorResponse('Failed to load admin dashboard: ' . $e->getMessage(), 500);
        }
    }

    // ============================================
    // HELPER METHODS
    // ============================================

    /**
     * Get officer's customers
     */
    private function getOfficerCustomers($zoneIds, $companyId)
    {
        $total = 0;
        $active = 0;

        if (!empty($zoneIds)) {
            $customerZoneQuery = CustomersZone::whereIn('zone_id', $zoneIds)
                ->where('company_id', $companyId);

            $total = $customerZoneQuery->count();
            $active = $customerZoneQuery->where('status', 1)->count();
        }

        return ['total' => $total, 'active' => $active];
    }

    /**
     * Get officer's loans
     */
    private function getOfficerLoans($zoneIds, $companyId)
    {
        $active = 0;
        $completed = 0;
        $outstanding = 0;
        $overdueAmount = 0;
        $overdueCount = 0;

        if (!empty($zoneIds)) {
            $loans = Loans::whereIn('zone', $zoneIds)
                ->where('company', $companyId)
                ->get();

            $active = $loans->whereIn('status', [Loans::STATUS_ACTIVE, Loans::STATUS_OVERDUE])->count();
            $completed = $loans->where('status', Loans::STATUS_COMPLETED)->count();
            $outstanding = $loans->whereIn('status', [Loans::STATUS_ACTIVE, Loans::STATUS_OVERDUE])->sum(function ($loan) {
                return ($loan->total_loan) - $loan->loan_paid;
            });

            // Calculate overdue amounts
            $overdueLoans = $loans->where('status', Loans::STATUS_OVERDUE);
            $overdueAmount = $overdueLoans->sum(function ($loan) {
                return ($loan->total_loan) - $loan->loan_paid;
            });
            $overdueCount = $overdueLoans->count();
        }

        return [
            'active' => $active,
            'completed' => $completed,
            'outstanding' => $outstanding,
            'overdue_amount' => $overdueAmount,
            'overdue_count' => $overdueCount,
        ];
    }

    /**
     * Get today's collection
     */
    private function getTodayCollection($zoneIds, $branchIds, $companyId)
    {
        $today = Carbon::today()->toDateString();
        $yesterday = Carbon::yesterday()->toDateString();

        $targetQuery = LoanPaymentSchedules::whereIn('payment_due_date', [$today, $yesterday])
            ->where('company', $companyId)
            ->where('status', 1)
            ->whereHas('loan', function ($q) {
                $q->whereIn('status', [Loans::STATUS_ACTIVE, Loans::STATUS_OVERDUE]);
            });

        if (!empty($branchIds)) {
            $targetQuery->whereIn('branch', $branchIds);
        } elseif (!empty($zoneIds)) {
            $targetQuery->whereIn('zone', $zoneIds);
        }

        $targets = $targetQuery->selectRaw('payment_due_date, SUM(payment_total_amount) as total')
            ->groupBy('payment_due_date')
            ->pluck('total', 'payment_due_date');

        $collectedQuery = PaymentSubmissions::whereIn('loan_payment_schedule.payment_due_date', [$today, $yesterday])
            ->where('payment_submissions.company', $companyId)
            ->where('submission_status', 11)
            ->join('loan_payment_schedule', 'payment_submissions.schedule_id', '=', 'loan_payment_schedule.id');

        if (!empty($branchIds)) {
            $collectedQuery->whereIn('payment_submissions.branch', $branchIds);
        } elseif (!empty($zoneIds)) {
            $collectedQuery->whereIn('payment_submissions.zone', $zoneIds);
        }

        $collections = $collectedQuery->selectRaw('loan_payment_schedule.payment_due_date, SUM(payment_submissions.amount) as total')
            ->groupBy('loan_payment_schedule.payment_due_date')
            ->pluck('total', 'loan_payment_schedule.payment_due_date');

        return [
            'target' => $targets[$today] ?? 0,
            'collected' => $collections[$today] ?? 0,
            'target_yesterday' => $targets[$yesterday] ?? 0,
            'collected_yesterday' => $collections[$yesterday] ?? 0,
        ];
    }

    /**
     * Get collection trend (last 7 days)
     */
    private function getCollectionTrend($zoneIds, $branchIds, $companyId)
    {
        $trend = [];
        $today = Carbon::today();

        for ($i = 6; $i >= 0; $i--) {
            $date = $today->copy()->subDays($i)->toDateString();

            $targetQuery = LoanPaymentSchedules::where('payment_due_date', $date)
                ->where('company', $companyId)
                ->where('status', 1)
                ->whereHas('loan', function ($q) {
                    $q->whereIn('status', [Loans::STATUS_ACTIVE, Loans::STATUS_OVERDUE]);
                });

            if (!empty($branchIds)) {
                $targetQuery->whereIn('branch', $branchIds);
            } elseif (!empty($zoneIds)) {
                $targetQuery->whereIn('zone', $zoneIds);
            }

            $target = $targetQuery->sum('payment_total_amount');

            $collectedQuery = PaymentSubmissions::join('loan_payment_schedule', 'loan_payment_schedule.id', '=', 'payment_submissions.schedule_id')
                ->whereDate('payment_due_date', $date)
                ->where('payment_submissions.company', $companyId)
                ->where('submission_status', 11);

            if (!empty($branchIds)) {
                $collectedQuery->whereIn('loan_payment_schedule.branch', $branchIds);
            } elseif (!empty($zoneIds)) {
                $collectedQuery->whereIn('loan_payment_schedule.zone', $zoneIds);
            }

            $collected = $collectedQuery->sum('amount');

            $trend[] = [
                'date' => $date,
                'formatted_date' => Carbon::parse($date)->format('D, M d'),
                'target' => (float) $target,
                'collected' => (float) $collected,
                'efficiency' => $target > 0 ? round(($collected / $target) * 100, 2) : 0,
            ];
        }

        return $trend;
    }

    /**
     * Get today's payments list
     */
    private function getTodayPaymentsList($zoneIds, $companyId)
    {
        $today = Carbon::today()->toDateString();

        $schedules = LoanPaymentSchedules::where('payment_due_date', $today)
            ->where('company', $companyId)
            ->where('status', 1)
            ->whereHas('loan', function ($q) {
                $q->whereIn('status', [Loans::STATUS_ACTIVE, Loans::STATUS_OVERDUE]);
            });

        if (!empty($zoneIds)) {
            $schedules->whereIn('zone', $zoneIds);
        }

        return $schedules->with(['loan.loan_customer'])
            ->get()
            ->map(function ($schedule) {
                $customer = $schedule->loan->loan_customer;
                return [
                    'id' => $schedule->id,
                    'loan_number' => $schedule->loan_number,
                    'customer_name' => $customer->fullname ?? 'Unknown',
                    'customer_phone' => $customer->phone ?? '',
                    'due_amount' => (float) $schedule->payment_total_amount,
                    'status' => 'pending',
                ];
            });
    }

    /**
     * Get overdue payments list
     */
    private function getOverduePaymentsList($zoneIds, $companyId)
    {
        $today = Carbon::today()->toDateString();

        $schedules = LoanPaymentSchedules::where('payment_due_date', '<', $today)
            ->where('company', $companyId)
            ->where('status', 1)
            ->where('is_submitted', false)
            ->whereHas('loan', function ($q) {
                $q->whereIn('status', [Loans::STATUS_ACTIVE, Loans::STATUS_OVERDUE]);
            });

        if (!empty($zoneIds)) {
            $schedules->whereIn('zone', $zoneIds);
        }

        return $schedules->with(['loan.loan_customer'])
            ->take(20)
            ->get()
            ->map(function ($schedule) {
                $customer = $schedule->loan->loan_customer;
                $daysOverdue =  (int) Carbon::parse($schedule->payment_due_date)
                    ->diffInDays(now());
                return [
                    'id' => $schedule->id,
                    'loan_number' => $schedule->loan_number,
                    'customer_name' => $customer->fullname ?? 'Unknown',
                    'customer_phone' => $customer->phone ?? '',
                    'due_amount' => (float) $schedule->payment_total_amount,
                    'days_overdue' => $daysOverdue,
                    'status' => 'overdue',
                ];
            });
    }

    /**
     * Get upcoming payments
     */
    private function getUpcomingPayments($zoneIds, $companyId)
    {
        $startDate = Carbon::tomorrow()->toDateString();
        $endDate = Carbon::tomorrow()->addDays(7)->toDateString();

        $schedules = LoanPaymentSchedules::whereBetween('payment_due_date', [$startDate, $endDate])
            ->where('company', $companyId)
            ->where('status', 1)
            ->whereHas('loan', function ($q) {
                $q->whereIn('status', [Loans::STATUS_ACTIVE, Loans::STATUS_OVERDUE]);
            });

        if (!empty($zoneIds)) {
            $schedules->whereIn('zone', $zoneIds);
        }

        return $schedules->with(['loan.loan_customer'])
            ->orderBy('payment_due_date')
            ->take(20)
            ->get()
            ->map(function ($schedule) {
                $customer = $schedule->loan->loan_customer;
                return [
                    'id' => $schedule->id,
                    'loan_number' => $schedule->loan_number,
                    'customer_name' => $customer->fullname ?? 'Unknown',
                    'due_date' => $schedule->payment_due_date,
                    'formatted_date' => Carbon::parse($schedule->payment_due_date)->format('D, M d'),
                    'due_amount' => (float) $schedule->payment_total_amount,
                ];
            });
    }

    /**
     * Get pending loans
     */
    private function getPendingLoans($zoneIds, $companyId)
    {
        $loans = Loans::where('company', $companyId)
            ->where('status', Loans::STATUS_SUBMITTED);

        if (!empty($zoneIds)) {
            $loans->whereIn('zone', $zoneIds);
        }

        $pendingLoans = $loans->with(['loan_customer', 'loan_product'])
            ->orderBy('created_at', 'desc')
            ->take(10)
            ->get()
            ->map(function ($loan) {
                return [
                    'id' => $loan->id,
                    'loan_number' => $loan->loan_number,
                    'customer_name' => $loan->loan_customer->fullname ?? 'Unknown',
                    'principal_amount' => (float) $loan->principal_amount,
                    'created_at' => $loan->created_at,
                    'formatted_date' => Carbon::parse($loan->created_at)->format('d M Y'),
                    'product' => $loan->loan_product->product_name ?? 'N/A',
                ];
            });

        return [
            'count' => $loans->count(),
            'loans' => $pendingLoans,
        ];
    }

    /**
     * Get recent customers
     */
    private function getRecentCustomers($zoneIds, $companyId, $limit = 10)
    {
        $customerIds = CustomersZone::where('company_id', $companyId)
            ->when(!empty($zoneIds), function ($q) use ($zoneIds) {
                $q->whereIn('zone_id', $zoneIds);
            })
            ->pluck('customer_id');

        return Customers::whereIn('id', $customerIds)
            ->orderBy('created_at', 'desc')
            ->take($limit)
            ->get()
            ->map(function ($customer) {
                return [
                    'id' => $customer->id,
                    'fullname' => $customer->fullname,
                    'phone' => $customer->phone,
                    'created_at' => $customer->created_at,
                    'formatted_date' => Carbon::parse($customer->created_at)->format('d M Y'),
                ];
            });
    }

    /**
     * Get collection efficiency
     */
    private function getCollectionEfficiency($zoneIds, $companyId)
    {
        $startOfMonth = Carbon::now()->startOfMonth();
        $today = Carbon::today();

        $targetQuery = LoanPaymentSchedules::whereBetween('payment_due_date', [$startOfMonth, $today])
            ->where('company', $companyId)
            ->where('status', 1)
            ->whereHas('loan', function ($q) {
                $q->whereIn('status', [Loans::STATUS_ACTIVE, Loans::STATUS_OVERDUE]);
            });

        if (!empty($zoneIds)) {
            $targetQuery->whereIn('zone', $zoneIds);
        }

        $target = $targetQuery->sum('payment_total_amount');

        $collectedQuery = PaymentSubmissions::join('loan_payment_schedule', 'loan_payment_schedule.id', '=', 'payment_submissions.schedule_id')
            ->whereBetween('payment_due_date', [$startOfMonth, $today])
            ->where('payment_submissions.company', $companyId)
            ->where('submission_status', 11);

        if (!empty($zoneIds)) {
            $collectedQuery->whereIn('loan_payment_schedule.zone', $zoneIds);
        }

        $collected = $collectedQuery->sum('amount');

        return $target > 0 ? round(($collected / $target) * 100, 2) : 0;
    }

    /**
     * Get payment method breakdown
     */
    private function getPaymentMethodBreakdown($zoneIds, $companyId)
    {
        $startOfMonth = Carbon::now()->startOfMonth();

        $payments = PaymentSubmissions::join('loan_payment_schedule', 'loan_payment_schedule.id', '=', 'payment_submissions.schedule_id')
            ->whereBetween('payment_due_date', [$startOfMonth, Carbon::now()])
            ->where('payment_submissions.company', $companyId)
            ->where('submission_status', 11);

        if (!empty($zoneIds)) {
            $payments->whereIn('loan_payment_schedule.zone', $zoneIds);
        }

        $payments = $payments->get();

        // Get account names for payment methods
        $accountIds = $payments->pluck('paid_account')->unique();
        $accounts = Accounts::whereIn('id', $accountIds)->get()->keyBy('id');

        $breakdown = [];
        foreach ($payments->groupBy('paid_account') as $accountId => $group) {
            $account = $accounts->get($accountId);
            $amount = $group->sum('amount');
            $breakdown[] = [
                'method' => $account ? $account->account_name : 'Unknown',
                'amount' => (float) $amount,
                'count' => $group->count(),
            ];
        }

        // Sort by amount descending
        usort($breakdown, function ($a, $b) {
            return $b['amount'] <=> $a['amount'];
        });

        return $breakdown;
    }

    /**
     * Get top customers by loan amount
     */
    private function getTopCustomersByLoan($zoneIds, $companyId, $limit = 5)
    {
        $loans = Loans::where('company', $companyId)
            ->whereIn('status', [Loans::STATUS_ACTIVE, Loans::STATUS_OVERDUE]);

        if (!empty($zoneIds)) {
            $loans->whereIn('zone', $zoneIds);
        }

        return $loans->with(['loan_customer'])
            ->select('customer', DB::raw('SUM(principal_amount) as total_borrowed'))
            ->groupBy('customer')
            ->orderBy('total_borrowed', 'desc')
            ->take($limit)
            ->get()
            ->map(function ($loan) {
                return [
                    'customer_id' => $loan->customer,
                    'customer_name' => $loan->loan_customer->fullname ?? 'Unknown',
                    'total_borrowed' => (float) $loan->total_borrowed,
                    'loan_count' => Loans::where('customer', $loan->customer)
                        ->whereIn('status', [Loans::STATUS_ACTIVE, Loans::STATUS_OVERDUE])->count(),
                ];
            });
    }

    /**
     * Get officer performance metrics
     */
    private function getOfficerPerformanceMetrics($userId, $zoneIds, $companyId)
    {
        // Get officer's loans
        $loans = Loans::where('company', $companyId)
            ->whereIn('zone', $zoneIds)
            ->where('registered_by', $userId)
            ->get();

        $totalDisbursed = $loans->sum('principal_amount');
        $totalCollected = $loans->sum('loan_paid');
        $activeLoans = $loans->whereIn('status', [Loans::STATUS_ACTIVE, Loans::STATUS_OVERDUE])->count();
        $completedLoans = $loans->where('status', Loans::STATUS_COMPLETED)->count();

        // Get collection target for this officer
        $today = Carbon::today();
        $target = LoanPaymentSchedules::where('payment_due_date', $today)
            ->whereIn('zone', $zoneIds)
            ->where('company', $companyId)
            ->whereHas('loan', function ($q) use ($userId) {
                $q->where('registered_by', $userId);
            })
            ->sum('payment_total_amount');

        $collected = PaymentSubmissions::join('loan_payment_schedule', 'loan_payment_schedule.id', '=', 'payment_submissions.schedule_id')
            ->whereDate('payment_due_date', $today)
            ->whereIn('loan_payment_schedule.zone', $zoneIds)
            ->where('payment_submissions.company', $companyId)
            ->where('submission_status', 11)
            ->whereHas('loan', function ($q) use ($userId) {
                $q->where('registered_by', $userId);
            })
            ->sum('amount');

        return [
            'total_customers' => CustomersZone::whereIn('zone_id', $zoneIds)->count(),
            'total_disbursed' => (float) $totalDisbursed,
            'total_collected' => (float) $totalCollected,
            'active_loans' => $activeLoans,
            'completed_loans' => $completedLoans,
            'collection_rate' => $totalDisbursed > 0 ? round(($totalCollected / $totalDisbursed) * 100, 2) : 0,
            'today_target' => (float) $target,
            'today_collected' => (float) $collected,
            'today_efficiency' => $target > 0 ? round(($collected / $target) * 100, 2) : 0,
        ];
    }

    /**
     * Get branch customers
     */
    private function getBranchCustomers($branchIds, $zoneIds, $companyId)
    {
        $total = CustomersZone::where('company_id', $companyId)
            ->when(!empty($branchIds), function ($q) use ($branchIds) {
                $q->whereIn('branch_id', $branchIds);
            })
            ->count();

        $active = CustomersZone::where('company_id', $companyId)
            ->where('status', 1)
            ->when(!empty($branchIds), function ($q) use ($branchIds) {
                $q->whereIn('branch_id', $branchIds);
            })
            ->count();

        return ['total' => $total, 'active' => $active];
    }

    /**
     * Get branch loans
     */
    private function getBranchLoans($branchIds, $zoneIds, $companyId)
    {
        $loans = Loans::where('company', $companyId)
            ->when(!empty($zoneIds), function ($q) use ($zoneIds) {
                $q->whereIn('zone', $zoneIds);
            })
            ->get();

        $active = $loans->whereIn('status', [Loans::STATUS_ACTIVE, Loans::STATUS_OVERDUE])->count();
        $completed = $loans->where('status', Loans::STATUS_COMPLETED)->count();
        $totalPortfolio = $loans->whereIn('status', [Loans::STATUS_ACTIVE, Loans::STATUS_OVERDUE])->sum(function ($loan) {
            return ($loan->total_loan) - $loan->loan_paid;
        });

        // Calculate default rate
        $defaulted = $loans->where('status', Loans::STATUS_DEFAULTED)->count();
        $totalLoans = $loans->count();
        $defaultRate = $totalLoans > 0 ? round(($defaulted / $totalLoans) * 100, 2) : 0;

        // Calculate collection efficiency for current month
        $startOfMonth = Carbon::now()->startOfMonth();
        $target = LoanPaymentSchedules::whereBetween('payment_due_date', [$startOfMonth, Carbon::now()])
            ->where('company', $companyId)
            ->when(!empty($zoneIds), function ($q) use ($zoneIds) {
                $q->whereIn('zone', $zoneIds);
            })
            ->sum('payment_total_amount');

        $collected = PaymentSubmissions::join('loan_payment_schedule', 'loan_payment_schedule.id', '=', 'payment_submissions.schedule_id')
            ->whereBetween('payment_due_date', [$startOfMonth, Carbon::now()])
            ->where('payment_submissions.company', $companyId)
            ->where('submission_status', 11)
            ->when(!empty($zoneIds), function ($q) use ($zoneIds) {
                $q->whereIn('loan_payment_schedule.zone', $zoneIds);
            })
            ->sum('amount');

        $collectionEfficiency = $target > 0 ? round(($collected / $target) * 100, 2) : 0;

        return [
            'active' => $active,
            'completed' => $completed,
            'total_portfolio' => $totalPortfolio,
            'default_rate' => $defaultRate,
            'collection_efficiency' => $collectionEfficiency,
        ];
    }

    /**
     * Get branch officer performance
     */
    private function getBranchOfficerPerformance($branchIds, $zoneIds, $companyId)
    {
        // Get officers (users with permission 19) in this branch
        $officers = ZoneUser::with('zones')
            ->join('users', 'zone_users.user_id', '=', 'users.id')
            ->whereIn('zone_id', $zoneIds)
            ->where('zone_users.status', 1)
            ->get();

        $performance = [];
        foreach ($officers as $officer) {
            $officerZones = $officer->zones()->pluck('id')->toArray();

            // Get loans for this officer
            $loans = Loans::where('company', $companyId)
                ->whereIn('zone', $officerZones)
                ->where('registered_by', $officer->id)
                ->get();

            $totalDisbursed = $loans->sum('principal_amount');
            $totalCollected = $loans->sum('loan_paid');
            $activeLoans = $loans->whereIn('status', [Loans::STATUS_ACTIVE, Loans::STATUS_OVERDUE])->count();

            // Get today's collection
            $today = Carbon::today();
            $target = LoanPaymentSchedules::where('payment_due_date', $today)
                ->whereIn('zone', $officerZones)
                ->where('company', $companyId)
                ->sum('payment_total_amount');

            $collected = PaymentSubmissions::join('loan_payment_schedule', 'loan_payment_schedule.id', '=', 'payment_submissions.schedule_id')
                ->whereDate('payment_due_date', $today)
                ->whereIn('loan_payment_schedule.zone', $officerZones)
                ->where('payment_submissions.company', $companyId)
                ->where('submission_status', 11)
                ->sum('amount');

            $performance[] = [
                'officer_id' => $officer->id,
                'officer_name' => $officer->first_name . ' ' . $officer->last_name,
                'active_customers' => CustomersZone::whereIn('zone_id', $officerZones)->count(),
                'active_loans' => $activeLoans,
                'total_disbursed' => (float) $totalDisbursed,
                'total_collected' => (float) $totalCollected,
                'collection_rate' => $totalDisbursed > 0 ? round(($totalCollected / $totalDisbursed) * 100, 2) : 0,
                'today_target' => (float) $target,
                'today_collected' => (float) $collected,
                'today_efficiency' => $target > 0 ? round(($collected / $target) * 100, 2) : 0,
            ];
        }

        // Sort by collection rate descending
        usort($performance, function ($a, $b) {
            return $b['collection_rate'] <=> $a['collection_rate'];
        });

        return $performance;
    }

    /**
     * Get zone performance
     */
    private function getZonePerformance($zoneIds, $companyId)
    {
        $zones = Zone::whereIn('id', $zoneIds)->get();
        $performance = [];

        foreach ($zones as $zone) {
            $loans = Loans::where('company', $companyId)
                ->where('zone', $zone->id)
                ->get();

            $activeLoans = $loans->whereIn('status', [Loans::STATUS_ACTIVE, Loans::STATUS_OVERDUE])->count();
            $totalPortfolio = $loans->whereIn('status', [Loans::STATUS_ACTIVE, Loans::STATUS_OVERDUE])->sum(function ($loan) {
                return ($loan->total_loan) - $loan->loan_paid;
            });

            // Get collection rate for this zone
            $startOfMonth = Carbon::now()->startOfMonth();
            $target = LoanPaymentSchedules::whereBetween('payment_due_date', [$startOfMonth, Carbon::now()])
                ->where('zone', $zone->id)
                ->where('company', $companyId)
                ->sum('payment_total_amount');

            $collected = PaymentSubmissions::join('loan_payment_schedule', 'loan_payment_schedule.id', '=', 'payment_submissions.schedule_id')
                ->whereBetween('payment_due_date', [$startOfMonth, Carbon::now()])
                ->where('loan_payment_schedule.zone', $zone->id)
                ->where('payment_submissions.company', $companyId)
                ->where('submission_status', 11)
                ->sum('amount');

            $collectionRate = $target > 0 ? round(($collected / $target) * 100, 2) : 0;

            $todayGoal = $this->getTodayCollection([$zone->id], [], $companyId);

            $performance[] = [
                'zone_id' => $zone->id,
                'zone_name' => $zone->zone_name,
                'active_loans' => $activeLoans,
                'total_portfolio' => (float) $totalPortfolio,
                'collection_rate' => $collectionRate,
                'customers' => CustomersZone::where('zone_id', $zone->id)->count(),
                'today_target' => (float) ($todayGoal['target'] ?? 0),
                'today_collected' => (float) ($todayGoal['collected'] ?? 0),
                'today_efficiency' => !empty($todayGoal['target']) && $todayGoal['target'] > 0 ? round(($todayGoal['collected'] / $todayGoal['target']) * 100, 2) : 0,
                'yesterday_target' => (float) ($todayGoal['target_yesterday'] ?? 0),
                'yesterday_collected' => (float) ($todayGoal['collected_yesterday'] ?? 0),
                'yesterday_efficiency' => (!empty($todayGoal['target_yesterday']) && $todayGoal['target_yesterday'] > 0) ? round(($todayGoal['collected_yesterday'] / $todayGoal['target_yesterday']) * 100, 2) : 0,
            ];
        }

        // Sort by collection rate descending
        usort($performance, function ($a, $b) {
            return $b['collection_rate'] <=> $a['collection_rate'];
        });

        return $performance;
    }

    /**
     * Get pending approvals for branch
     */
    private function getPendingApprovals($companyId, $branchIds, $zoneIds)
    {
        // Pending loan applications
        $pendingLoans = Loans::where('company', $companyId)
            ->where('status', Loans::STATUS_SUBMITTED)
            ->when(!empty($zoneIds), function ($q) use ($zoneIds) {
                $q->whereIn('zone', $zoneIds);
            })
            ->count();

        // Pending customer registrations
        $pendingCustomers = CustomersZone::where('company_id', $companyId)
            ->where('status', 4) // Pending status
            ->when(!empty($branchIds), function ($q) use ($branchIds) {
                $q->whereIn('branch_id', $branchIds);
            })
            ->count();

        return [
            'pending_loans' => $pendingLoans,
            'pending_customers' => $pendingCustomers,
            'total_pending' => $pendingLoans + $pendingCustomers,
        ];
    }

    /**
     * Get Portfolio at Risk (PAR)
     */
    private function getPortfolioAtRisk($branchIds, $zoneIds, $companyId)
    {
        $loans = Loans::where('company', $companyId)
            ->whereIn('status', [Loans::STATUS_ACTIVE, Loans::STATUS_OVERDUE])
            ->when(!empty($zoneIds), function ($q) use ($zoneIds) {
                $q->whereIn('zone', $zoneIds);
            })
            ->get();

        $par30 = 0;
        $par60 = 0;
        $par90 = 0;
        $breakdown = [];

        foreach ($loans as $loan) {
            if ($loan->end_date) {
                $daysOverdue = Carbon::parse($loan->end_date)->diffInDays(now());
                $outstanding = ($loan->total_loan) - $loan->loan_paid;

                if ($daysOverdue > 30 && $daysOverdue <= 60) {
                    $par30 += $outstanding;
                    $breakdown['30-60'][] = $loan;
                } elseif ($daysOverdue > 60 && $daysOverdue <= 90) {
                    $par60 += $outstanding;
                    $breakdown['61-90'][] = $loan;
                } elseif ($daysOverdue > 90) {
                    $par90 += $outstanding;
                    $breakdown['90+'][] = $loan;
                }
            }
        }

        $totalPortfolio = $loans->sum(function ($loan) {
            return ($loan->total_loan) - $loan->loan_paid;
        });

        $percentage = $totalPortfolio > 0 ? round((($par30 + $par60 + $par90) / $totalPortfolio) * 100, 2) : 0;

        return [
            'par_30' => $par30,
            'par_60' => $par60,
            'par_90' => $par90,
            'percentage' => $percentage,
            'breakdown' => $breakdown,
        ];
    }

    /**
     * Get branch financials
     */
    private function getBranchFinancials($branchIds, $companyId)
    {
        $branches = BranchModel::whereIn('id', $branchIds)
            ->where('company', $companyId)
            ->get();

        $financials = [];
        foreach ($branches as $branch) {
            // Get zones under this branch
            $zoneIds = Zone::where('branch', $branch->id)->pluck('id')->toArray();

            $loans = Loans::where('company', $companyId)
                ->whereIn('zone', $zoneIds)
                ->get();

            $totalDisbursed = $loans->sum('principal_amount');
            $totalRepaid = $loans->sum('loan_paid');
            $outstanding = $loans->whereIn('status', [Loans::STATUS_ACTIVE, Loans::STATUS_OVERDUE])->sum(function ($loan) {
                return ($loan->total_loan) - $loan->loan_paid;
            });

            $financials[] = [
                'branch_id' => $branch->id,
                'branch_name' => $branch->branch_name,
                'balance' => (float) $branch->balance,
                'total_disbursed' => (float) $totalDisbursed,
                'total_repaid' => (float) $totalRepaid,
                'outstanding' => (float) $outstanding,
                'utilization_rate' => $branch->balance > 0 ? round(($totalDisbursed / $branch->balance) * 100, 2) : 0,
            ];
        }

        return $financials;
    }

    /**
     * Get product performance
     */
    private function getProductPerformance($branchIds, $zoneIds, $companyId)
    {
        $products = LoansProducts::where('company', $companyId)
            ->where('status', 1)
            ->get();

        $performance = [];
        foreach ($products as $product) {
            $loans = Loans::where('product', $product->id)
                ->where('company', $companyId)
                ->when(!empty($zoneIds), function ($q) use ($zoneIds) {
                    $q->whereIn('zone', $zoneIds);
                })
                ->get();

            $totalLoans = $loans->count();
            $activeLoans = $loans->whereIn('status', [Loans::STATUS_ACTIVE, Loans::STATUS_OVERDUE])->count();
            $completedLoans = $loans->where('status', Loans::STATUS_COMPLETED)->count();
            $defaultedLoans = $loans->where('status', Loans::STATUS_DEFAULTED)->count();

            $totalDisbursed = $loans->sum('principal_amount');
            $totalRepaid = $loans->sum('loan_paid');
            $outstanding = $loans->whereIn('status', [Loans::STATUS_ACTIVE, Loans::STATUS_OVERDUE])->sum(function ($loan) {
                return ($loan->total_loan) - $loan->loan_paid;
            });

            $performance[] = [
                'product_id' => $product->id,
                'product_name' => $product->product_name,
                'total_loans' => $totalLoans,
                'active_loans' => $activeLoans,
                'completed_loans' => $completedLoans,
                'defaulted_loans' => $defaultedLoans,
                'total_disbursed' => (float) $totalDisbursed,
                'total_repaid' => (float) $totalRepaid,
                'outstanding' => (float) $outstanding,
                'default_rate' => $totalLoans > 0 ? round(($defaultedLoans / $totalLoans) * 100, 2) : 0,
                'repayment_rate' => $totalDisbursed > 0 ? round(($totalRepaid / $totalDisbursed) * 100, 2) : 0,
            ];
        }

        return $performance;
    }

    /**
     * Get company customers
     */
    private function getCompanyCustomers($companyId)
    {
        $total = CustomersZone::where('company_id', $companyId)->count();
        $active = CustomersZone::where('company_id', $companyId)->where('status', 1)->count();

        return ['total' => $total, 'active' => $active];
    }

    /**
     * Get company loans
     */
    private function getCompanyLoans($companyId)
    {
        $loans = Loans::where('company', $companyId)->get();

        $total = $loans->count();
        $active = $loans->whereIn('status', [Loans::STATUS_ACTIVE, Loans::STATUS_OVERDUE])->count();
        $completed = $loans->where('status', Loans::STATUS_COMPLETED)->count();
        $defaulted = $loans->where('status', Loans::STATUS_DEFAULTED)->count();
        $totalPortfolio = $loans->whereIn('status', [Loans::STATUS_ACTIVE, Loans::STATUS_OVERDUE])->sum(function ($loan) {
            return ($loan->total_loan) - $loan->loan_paid;
        });

        $defaultRate = $total > 0 ? round(($defaulted / $total) * 100, 2) : 0;

        return [
            'total' => $total,
            'active' => $active,
            'completed' => $completed,
            'defaulted' => $defaulted,
            'total_portfolio' => $totalPortfolio,
            'default_rate' => $defaultRate,
        ];
    }

    /**
     * Get financial KPI
     */
    private function getFinancialKPI($companyId)
    {
        $loans = Loans::where('company', $companyId)
            ->whereIn('status', [Loans::STATUS_ACTIVE, Loans::STATUS_OVERDUE])
            ->get();

        $totalPortfolio = $loans->sum(function ($loan) {
            return ($loan->total_loan) - $loan->loan_paid;
        });

        $averageLoanSize = $loans->count() > 0 ? $loans->avg('principal_amount') : 0;

        // Calculate PAR
        $par30 = 0;
        $par60 = 0;
        $par90 = 0;

        foreach ($loans as $loan) {
            if ($loan->end_date) {
                $daysOverdue = Carbon::parse($loan->end_date)->diffInDays(now());
                $outstanding = ($loan->total_loan) - $loan->loan_paid;

                if ($daysOverdue > 30 && $daysOverdue <= 60) {
                    $par30 += $outstanding;
                } elseif ($daysOverdue > 60 && $daysOverdue <= 90) {
                    $par60 += $outstanding;
                } elseif ($daysOverdue > 90) {
                    $par90 += $outstanding;
                }
            }
        }

        // Collection efficiency for current month
        $startOfMonth = Carbon::now()->startOfMonth();
        $target = LoanPaymentSchedules::whereBetween('payment_due_date', [$startOfMonth, Carbon::now()])
            ->where('company', $companyId)
            ->sum('payment_total_amount');

        $collected = PaymentSubmissions::join('loan_payment_schedule', 'loan_payment_schedule.id', '=', 'payment_submissions.schedule_id')
            ->whereBetween('payment_due_date', [$startOfMonth, Carbon::now()])
            ->where('payment_submissions.company', $companyId)
            ->where('submission_status', 11)
            ->sum('amount');

        $collectionEfficiency = $target > 0 ? round(($collected / $target) * 100, 2) : 0;

        return [
            'total_portfolio' => (float) $totalPortfolio,
            'average_loan_size' => (float) $averageLoanSize,
            'collection_efficiency' => $collectionEfficiency,
            'par_30' => (float) $par30,
            'par_60' => (float) $par60,
            'par_90' => (float) $par90,
        ];
    }

    /**
     * Get operational KPI
     */
    private function getOperationalKPI($companyId)
    {
        $loans = Loans::where('company', $companyId)->get();

        $totalApplications = $loans->count();
        $approved = $loans->whereIn('status', [Loans::STATUS_ACTIVE, Loans::STATUS_OVERDUE, Loans::STATUS_COMPLETED])->count();
        $approvalRate = $totalApplications > 0 ? round(($approved / $totalApplications) * 100, 2) : 0;

        // Calculate average approval time (difference between created_at and start_date)
        $approvedLoans = $loans->whereNotNull('start_date');
        $totalDays = 0;
        foreach ($approvedLoans as $loan) {
            $totalDays += Carbon::parse($loan->created_at)->diffInDays($loan->start_date);
        }
        $averageApprovalTime = $approvedLoans->count() > 0 ? round($totalDays / $approvedLoans->count(), 1) : 0;

        return [
            'approval_rate' => $approvalRate,
            'average_approval_time' => $averageApprovalTime,
        ];
    }

    /**
     * Get profit loss
     */
    private function getProfitLoss($companyId)
    {
        $startDate = Carbon::now()->startOfYear();
        $endDate = Carbon::now();

        // Interest income
        $interestIncome = PaymentSubmissions::where('payment_submissions.company', $companyId)
            ->join('loan_payment_schedule', 'loan_payment_schedule.id', '=', 'payment_submissions.schedule_id')
            ->where('submission_status', 11)
            ->whereBetween('payment_due_date', [$startDate, $endDate])
            ->sum('paid_interest');
        $scheduleIncome = PaymentSubmissions::where('payment_submissions.company', $companyId)
            ->join('loan_payment_schedule', 'loan_payment_schedule.id', '=', 'payment_submissions.schedule_id')
            ->where('submission_status', 11)
            ->whereBetween('payment_due_date', [$startDate, $endDate])
            ->sum('amount');

        // Penalty income
        $penaltyIncome = PaymentSubmissions::where('payment_submissions.company', $companyId)
            ->join('loan_payment_schedule', 'loan_payment_schedule.id', '=', 'payment_submissions.schedule_id')
            ->where('submission_status', 11)
            ->whereBetween('payment_due_date', [$startDate, $endDate])
            ->whereHas('schedule', function ($q) {
                $q->where('is_penalty', true);
            })
            ->sum('amount');

        // Total expenses
        $totalExpense = Expenses::where('company_id', $companyId)
            ->whereBetween('expense_date', [$startDate, $endDate])
            ->sum('amount');

        // Staff related expenses
        $staffExpense = Expenses::where('company_id', $companyId)
            ->whereBetween('expense_date', [$startDate, $endDate])
            ->whereHas('category', function ($q) {
                $q->where('is_staff_related', true);
            })
            ->sum('amount');

        $totalIncome = $interestIncome + $penaltyIncome;
        $netProfit = $totalIncome - $totalExpense;
        $profitMargin = $totalIncome > 0 ? round(($netProfit / $totalIncome) * 100, 2) : 0;

        return [
            'interest_income' => (float) $interestIncome,
            'penalty_income' => (float) $penaltyIncome,
            'total_income' => (float) $totalIncome,
            'total_expense' => (float) $totalExpense,
            'staff_expense' => (float) $staffExpense,
            'operational_expense' => (float) ($totalExpense - $staffExpense),
            'net_profit' => (float) $netProfit,
            'profit_margin' => $profitMargin,
        ];
    }

    /**
     * Get branch performance comparison
     */
    private function getBranchPerformanceComparison($companyId)
    {
        $branches = BranchModel::where('company', $companyId)
            ->where('status', 1)
            ->get();

        $performance = [];
        foreach ($branches as $branch) {
            $zoneIds = Zone::where('branch', $branch->id)->pluck('id')->toArray();

            $loans = Loans::where('company', $companyId)
                ->whereIn('zone', $zoneIds)
                ->get();

            $totalDisbursed = $loans->whereIn('status', [Loans::STATUS_ACTIVE, Loans::STATUS_OVERDUE, Loans::STATUS_COMPLETED, Loans::STATUS_DEFAULTED])->sum('total_loan');
            $totalRepaid = $loans->sum('loan_paid');
            $activeLoans = $loans->whereIn('status', [Loans::STATUS_ACTIVE, Loans::STATUS_OVERDUE])->count();
            $completedLoans = $loans->where('status', Loans::STATUS_COMPLETED)->count();

            $defaultedLoans = $loans->where('status', Loans::STATUS_DEFAULTED)->count();
            $defaultRate = $loans->count() > 0 ? round(($defaultedLoans / $loans->count()) * 100, 2) : 0;

            $collectionRate = $totalDisbursed > 0 ? round(($totalRepaid / $totalDisbursed) * 100, 2) : 0;

            $todayGoal = $this->getTodayCollection([], [$branch->id], $companyId);
            $totalPortfolio = $loans
                ->whereIn('status', [
                    Loans::STATUS_ACTIVE,
                    Loans::STATUS_OVERDUE
                ])
                ->sum(
                    fn($loan) =>
                    $loan->total_loan - $loan->loan_paid
                );

            $performance[] = [
                'branch_id' => $branch->id,
                'branch_name' => $branch->branch_name,
                'active_customers' => CustomersZone::whereIn('zone_id', $zoneIds)->count(),
                'active_loans' => $activeLoans,
                'completed_loans' => $completedLoans,
                'total_disbursed' => (float) $totalDisbursed,
                'total_repaid' => (float) $totalRepaid,
                'collection_rate' => $collectionRate,
                'default_rate' => $defaultRate,
                'outstanding' => (float) ($totalPortfolio),
                'today_target' => (float) ($todayGoal['target'] ?? 0),
                'today_collected' => (float) ($todayGoal['collected'] ?? 0),
                'today_efficiency' => !empty($todayGoal['target']) && $todayGoal['target'] > 0 ? round(($todayGoal['collected'] / $todayGoal['target']) * 100, 2) : 0,
                'yesterday_target' => (float) ($todayGoal['target_yesterday'] ?? 0),
                'yesterday_collected' => (float) ($todayGoal['collected_yesterday'] ?? 0),
                'yesterday_efficiency' => (!empty($todayGoal['target_yesterday']) && $todayGoal['target_yesterday'] > 0) ? round(($todayGoal['collected_yesterday'] / $todayGoal['target_yesterday']) * 100, 2) : 0,
            ];
        }

        // Sort by collection rate descending
        usort($performance, function ($a, $b) {
            return $b['collection_rate'] <=> $a['collection_rate'];
        });

        // Add ranks
        foreach ($performance as $index => &$branch) {
            $branch['rank'] = $index + 1;
        }

        return $performance;
    }

    /**
     * Get product performance comparison
     */
    private function getProductPerformanceComparison($companyId)
    {
        $products = LoansProducts::where('company', $companyId)
            ->where('status', 1)
            ->get();

        $performance = [];
        foreach ($products as $product) {
            $loans = Loans::where('product', $product->id)
                ->where('company', $companyId)
                ->get();

            $totalLoans = $loans->count();
            $activeLoans = $loans->whereIn('status', [Loans::STATUS_ACTIVE, Loans::STATUS_OVERDUE])->count();
            $completedLoans = $loans->where('status', Loans::STATUS_COMPLETED)->count();
            $defaultedLoans = $loans->where('status', Loans::STATUS_DEFAULTED)->count();

            $totalDisbursed = $loans->sum('principal_amount');
            $totalRepaid = $loans->sum('loan_paid');

            $popularity = $totalLoans > 0 ? round(($totalLoans / Loans::where('company', $companyId)->count()) * 100, 2) : 0;
            $defaultRate = $totalLoans > 0 ? round(($defaultedLoans / $totalLoans) * 100, 2) : 0;

            $performance[] = [
                'product_id' => $product->id,
                'product_name' => $product->product_name,
                'total_loans' => $totalLoans,
                'active_loans' => $activeLoans,
                'completed_loans' => $completedLoans,
                'defaulted_loans' => $defaultedLoans,
                'total_disbursed' => (float) $totalDisbursed,
                'total_repaid' => (float) $totalRepaid,
                'default_rate' => $defaultRate,
                'popularity' => $popularity,
                'avg_loan_size' => $totalLoans > 0 ? (float) ($totalDisbursed / $totalLoans) : 0,
            ];
        }

        // Sort by popularity descending
        usort($performance, function ($a, $b) {
            return $b['popularity'] <=> $a['popularity'];
        });

        return $performance;
    }

    /**
     * Get PAR trend
     */
    private function getParTrend($companyId)
    {
        $trend = [];
        for ($i = 5; $i >= 0; $i--) {
            $monthStart = Carbon::now()->subMonths($i)->startOfMonth();
            $monthEnd = Carbon::now()->subMonths($i)->endOfMonth();

            $loans = Loans::where('company', $companyId)
                ->whereIn('status', [Loans::STATUS_ACTIVE, Loans::STATUS_OVERDUE])
                ->where(function ($q) use ($monthStart, $monthEnd) {
                    $q->whereBetween('start_date', [$monthStart, $monthEnd])
                        ->orWhereBetween('end_date', [$monthStart, $monthEnd]);
                })
                ->get();

            $par30 = 0;
            $par60 = 0;
            $par90 = 0;

            foreach ($loans as $loan) {
                if ($loan->end_date) {
                    $daysOverdue = Carbon::parse($loan->end_date)->diffInDays(now());
                    $outstanding = ($loan->total_loan) - $loan->loan_paid;

                    if ($daysOverdue > 30 && $daysOverdue <= 60) {
                        $par30 += $outstanding;
                    } elseif ($daysOverdue > 60 && $daysOverdue <= 90) {
                        $par60 += $outstanding;
                    } elseif ($daysOverdue > 90) {
                        $par90 += $outstanding;
                    }
                }
            }

            $totalPortfolio = $loans->sum(function ($loan) {
                return ($loan->total_loan) - $loan->loan_paid;
            });

            $trend[] = [
                'month' => $monthStart->format('M Y'),
                'par_30' => (float) $par30,
                'par_60' => (float) $par60,
                'par_90' => (float) $par90,
                'total_portfolio' => (float) $totalPortfolio,
                'par_percentage' => $totalPortfolio > 0 ? round((($par30 + $par60 + $par90) / $totalPortfolio) * 100, 2) : 0,
            ];
        }

        return $trend;
    }

    /**
     * Get customer acquisition trend
     */
    private function getCustomerAcquisitionTrend($companyId)
    {
        $trend = [];
        for ($i = 5; $i >= 0; $i--) {
            $monthStart = Carbon::now()->subMonths($i)->startOfMonth();
            $monthEnd = Carbon::now()->subMonths($i)->endOfMonth();

            $newCustomers = CustomersZone::where('company_id', $companyId)
                ->whereBetween('created_at', [$monthStart, $monthEnd])
                ->count();

            $totalCustomers = CustomersZone::where('company_id', $companyId)
                ->where('created_at', '<=', $monthEnd)
                ->count();

            $trend[] = [
                'month' => $monthStart->format('M Y'),
                'new_customers' => $newCustomers,
                'total_customers' => $totalCustomers,
                'growth_rate' => $totalCustomers > 0 ? round(($newCustomers / $totalCustomers) * 100, 2) : 0,
            ];
        }

        return $trend;
    }

    /**
     * Get risk analytics
     */
    private function getRiskAnalytics($companyId)
    {
        // Credit score distribution
        $customers = Customers::whereHas('zoneAssignment', function ($q) use ($companyId) {
            $q->where('company_id', $companyId);
        })->get();

        $scoreDistribution = [
            'excellent' => 0, // 800+
            'good' => 0,      // 700-799
            'fair' => 0,      // 600-699
            'poor' => 0,      // 500-599
            'very_poor' => 0, // <500
        ];

        foreach ($customers as $customer) {
            // Simplified score calculation
            $score = 700; // Default base score
            // Add logic based on payment history, etc.

            if ($score >= 800) $scoreDistribution['excellent']++;
            elseif ($score >= 700) $scoreDistribution['good']++;
            elseif ($score >= 600) $scoreDistribution['fair']++;
            elseif ($score >= 500) $scoreDistribution['poor']++;
            else $scoreDistribution['very_poor']++;
        }

        // High risk customers (with overdue loans)
        $highRiskCustomers = Loans::where('company', $companyId)
            ->where('status', Loans::STATUS_OVERDUE)
            ->distinct('customer')
            ->count();

        // Delinquency rate by product
        $products = LoansProducts::where('company', $companyId)->get();
        $delinquencyByProduct = [];
        foreach ($products as $product) {
            $loans = Loans::where('product', $product->id)
                ->where('company', $companyId)
                ->get();

            $totalLoans = $loans->count();
            $overdueLoans = $loans->where('status', Loans::STATUS_OVERDUE)->count();
            $delinquencyRate = $totalLoans > 0 ? round(($overdueLoans / $totalLoans) * 100, 2) : 0;

            $delinquencyByProduct[] = [
                'product_name' => $product->product_name,
                'total_loans' => $totalLoans,
                'overdue_loans' => $overdueLoans,
                'delinquency_rate' => $delinquencyRate,
            ];
        }

        // Top 10 customers by exposure (largest outstanding balances)
        $topExposure = Loans::where('company', $companyId)
            ->whereIn('status', [Loans::STATUS_ACTIVE, Loans::STATUS_OVERDUE])
            ->with(['loan_customer'])
            ->get()
            ->groupBy('customer')
            ->map(function ($customerLoans) {
                return [
                    'customer_id' => $customerLoans->first()->customer,
                    'customer_name' => $customerLoans->first()->loan_customer->fullname ?? 'Unknown',
                    'total_outstanding' => $customerLoans->sum(function ($loan) {
                        return ($loan->total_loan) - $loan->loan_paid;
                    }),
                    'loan_count' => $customerLoans->count(),
                ];
            })
            ->sortByDesc('total_outstanding')
            ->take(10)
            ->values();

        $totalPortfolio = $this->getCompanyLoans($companyId)['total_portfolio'] ?? 0;

        return [
            'score_distribution' => $scoreDistribution,
            'high_risk_customers' => $highRiskCustomers,
            'delinquency_by_product' => $delinquencyByProduct,
            'top_exposure' => $topExposure,
            'concentration_risk' => [
                'top_5_percentage' => $totalPortfolio > 0
                    ? ($topExposure->take(5)->sum('total_outstanding') / $totalPortfolio) * 100
                    : 0,

                'top_10_percentage' => $totalPortfolio > 0
                    ? ($topExposure->sum('total_outstanding') / $totalPortfolio) * 100
                    : 0,
            ],
        ];
    }

    /**
     * Get top company customers
     */
    private function getTopCompanyCustomers($companyId, $limit = 10)
    {
        return Loans::where('company', $companyId)
            ->whereIn('status', [Loans::STATUS_ACTIVE, Loans::STATUS_OVERDUE, Loans::STATUS_COMPLETED])
            ->with(['loan_customer'])
            ->get()
            ->groupBy('customer')
            ->map(function ($customerLoans) {
                return [
                    'customer_id' => $customerLoans->first()->customer,
                    'customer_name' => $customerLoans->first()->loan_customer->fullname ?? 'Unknown',
                    'total_borrowed' => $customerLoans->sum('total_loan'),
                    'total_repaid' => $customerLoans->sum('loan_paid'),
                    'outstanding' => $customerLoans->sum(function ($loan) {
                        return ($loan->total_loan) - $loan->loan_paid;
                    }),
                    'loan_count' => $customerLoans->count(),
                    'repayment_rate' => $customerLoans->sum('total_loan') > 0
                        ? round(($customerLoans->sum('loan_paid') / $customerLoans->sum('total_loan')) * 100, 2)
                        : 0,
                ];
            })
            ->sortByDesc('total_borrowed')
            ->take($limit)
            ->values();
    }

    /**
     * Get manager pending approvals
     */
    private function getManagerPendingApprovals($companyId)
    {
        // Pending zone approvals (submission_status = 4)
        $pendingZoneApprovals = PaymentSubmissions::where('company', $companyId)
            ->where('submission_status', 4)
            ->count();

        // Pending branch approvals (submission_status = 8)
        $pendingBranchApprovals = PaymentSubmissions::where('company', $companyId)
            ->where('submission_status', 8)
            ->count();

        // Pending loan applications
        $pendingLoanApplications = Loans::where('company', $companyId)
            ->where('status', Loans::STATUS_SUBMITTED)
            ->count();

        // Pending customer registrations
        $pendingCustomerRegistrations = CustomersZone::where('company_id', $companyId)
            ->where('status', 4)
            ->count();

        return [
            'pending_zone_approvals' => $pendingZoneApprovals,
            'pending_branch_approvals' => $pendingBranchApprovals,
            'pending_loan_applications' => $pendingLoanApplications,
            'pending_customer_registrations' => $pendingCustomerRegistrations,
            'total_pending' => $pendingZoneApprovals + $pendingBranchApprovals + $pendingLoanApplications + $pendingCustomerRegistrations,
        ];
    }

    /**
     * Get cash flow
     */
    private function getCashFlow($companyId)
    {
        $startDate = Carbon::now()->startOfMonth();
        $endDate = Carbon::now();

        // Cash inflows (collections)
        $collections = PaymentSubmissions::where('payment_submissions.company', $companyId)
            ->join('loan_payment_schedule', 'loan_payment_schedule.id', '=', 'payment_submissions.schedule_id')
            ->where('submission_status', 11)
            ->whereBetween('payment_due_date', [$startDate, $endDate])
            ->sum('amount');

        // Cash outflows (disbursements)
        $disbursements = Loans::where('company', $companyId)
            ->whereBetween('start_date', [$startDate, $endDate])
            ->sum('principal_amount');

        // Cash outflows (expenses)
        $expenses = Expenses::where('company_id', $companyId)
            ->whereBetween('expense_date', [$startDate, $endDate])
            ->sum('amount');

        $netCashFlow = $collections - ($disbursements + $expenses);

        return [
            'collections' => (float) $collections,
            'disbursements' => (float) $disbursements,
            'expenses' => (float) $expenses,
            'net_cash_flow' => (float) $netCashFlow,
            'month_to_date' => [
                'collections' => (float) $collections,
                'disbursements' => (float) $disbursements,
                'expenses' => (float) $expenses,
            ],
        ];
    }

    /**
     * Get staff performance
     */
    private function getStaffPerformance($companyId)
    {
        $users = User::where('user_company', $companyId)->get();
        $performance = [];

        foreach ($users as $user) {
            // Loans registered by this user
            $loans = Loans::where('company', $companyId)
                ->where('registered_by', $user->id)
                ->get();

            $totalDisbursed = $loans->sum('principal_amount');
            $totalLoans = $loans->count();

            $performance[] = [
                'user_id' => $user->id,
                'name' => $user->first_name . ' ' . $user->last_name,
                'email' => $user->email,
                'role' => $this->getUserRole($user->id),
                'total_loans_registered' => $totalLoans,
                'total_disbursed' => (float) $totalDisbursed,
            ];
        }

        // Sort by total disbursed descending
        usort($performance, function ($a, $b) {
            return $b['total_disbursed'] <=> $a['total_disbursed'];
        });

        return $performance;
    }

    /**
     * Get user role
     */
    private function getUserRole($userId)
    {
        $role = users_roles::where('user_id', $userId)
            ->where('user_role_status', 1)
            ->first();
        $role_permissions = role_permissions::where('role_id', $role->role_id)
            ->where('permission_status', 1)->get();
        $permissions = $role_permissions->pluck('permission_id')->toArray();
        if (in_array(19, $permissions)) {
            return 'Officer';
        } elseif (in_array(20, $permissions)) {
            return 'Branch Incharge';
        } elseif (in_array(21, $permissions)) {
            return 'Manager';
        } else {
            return 'Admin';
        }
    }

    /**
     * Get companies overview (for super admin)
     */
    private function getCompaniesOverview()
    {
        $companies = Company::where('company_status', '!=', 3)->get();
        $overview = [];

        foreach ($companies as $company) {
            $loans = Loans::where('company', $company->id)->get();
            $totalPortfolio = $loans->whereIn('status', [Loans::STATUS_ACTIVE, Loans::STATUS_OVERDUE])->sum(function ($loan) {
                return ($loan->total_loan) - $loan->loan_paid;
            });

            $overview[] = [
                'company_id' => $company->id,
                'company_name' => $company->company_name,
                'status' => $company->company_status,
                'total_customers' => CustomersZone::where('company_id', $company->id)->count(),
                'active_loans' => $loans->whereIn('status', [Loans::STATUS_ACTIVE, Loans::STATUS_OVERDUE])->count(),
                'total_portfolio' => (float) $totalPortfolio,
                'subscription_plan' => $company->subscription_plan ?? 'Free',
                'subscription_end_date' => $company->subscription_end_date,
            ];
        }

        return $overview;
    }

    /**
     * Get consolidated financials (for super admin)
     */
    private function getConsolidatedFinancials()
    {
        $startDate = Carbon::now()->startOfYear();
        $endDate = Carbon::now();

        $totalLoans = Loans::whereBetween('created_at', [$startDate, $endDate])->count();
        $totalDisbursed = Loans::whereBetween('created_at', [$startDate, $endDate])->sum('principal_amount');
        $totalCollected = PaymentSubmissions::join('loan_payment_schedule', 'loan_payment_schedule.id', '=', 'payment_submissions.schedule_id')
            ->whereBetween('payment_due_date', [$startDate, $endDate])
            ->where('submission_status', 11)
            ->sum('amount');

        // Total interest collected
        $totalInterest = PaymentSubmissions::join('loan_payment_schedule', 'loan_payment_schedule.id', '=', 'payment_submissions.schedule_id')
            ->whereBetween('payment_due_date', [$startDate, $endDate])
            ->where('submission_status', 11)
            ->sum('paid_interest');

        // Total expenses across all companies
        $totalExpenses = Expenses::whereBetween('created_at', [$startDate, $endDate])->sum('amount');

        return [
            'total_loans' => $totalLoans,
            'total_disbursed' => (float) $totalDisbursed,
            'total_collected' => (float) $totalCollected,
            'total_interest' => (float) $totalInterest,
            'total_expenses' => (float) $totalExpenses,
            'net_profit' => (float) ($totalInterest - $totalExpenses),
            'collection_rate' => $totalDisbursed > 0 ? round(($totalCollected / $totalDisbursed) * 100, 2) : 0,
        ];
    }

    /**
     * Get system health (for super admin)
     */
    private function getSystemHealth()
    {
        // Calculate how long this dashboard request has taken up to this point
        $responseTime = defined('LARAVEL_START') ? round((microtime(true) - LARAVEL_START) * 1000) : rand(50, 200);

        return [
            'api_response_time' => $responseTime, // ms - based on current request speed
            'database_size' => $this->getDatabaseSize(),
            'active_users_today' => $this->getActiveUsersCount(),
            'error_rate' => 0.5, // percentage - replace with actual metrics
            'uptime' => 99.9, // percentage - replace with actual metrics
        ];
    }

    /**
     * Get database size
     */
    private function getDatabaseSize()
    {
        try {
            $totalSizeBytes = 0;
            $tableNames = DB::select("SHOW TABLES");

            foreach ($tableNames as $tableObj) {
                $tableName = current((array)$tableObj);

                // Get table status
                $status = DB::select("SHOW TABLE STATUS WHERE Name = ?", [$tableName]);

                if (!empty($status)) {
                    $totalSizeBytes += ($status[0]->Data_length + $status[0]->Index_length);
                }
            }

            // Calculate size to readable format
            $units = ['B', 'KB', 'MB', 'GB', 'TB'];
            $totalSizeBytes = max($totalSizeBytes, 0);
            $pow = floor(($totalSizeBytes ? log($totalSizeBytes) : 0) / log(1024));
            $pow = min($pow, count($units) - 1);
            $totalSizeBytes /= pow(1024, $pow);

            return round($totalSizeBytes, 2) . ' ' . $units[$pow];
        } catch (\Exception $e) {
            return '0 B';
        }
    }

    /**
     * Get active users count
     */
    private function getActiveUsersCount()
    {
        // Implement active users count logic
        return User::where('last_login_at', '>=', Carbon::today())->count();
    }

    /**
     * Get user activity (for super admin)
     */
    private function getUserActivity()
    {
        return [
            'total_users' => User::count(),
            'active_users' => User::where('status', 1)->count(),
            'new_users_today' => User::whereDate('created_at', Carbon::today())->count(),
            'login_activity' => $this->getLoginActivity(),
        ];
    }

    /**
     * Get login activity
     */
    private function getLoginActivity()
    {
        // Implement login activity tracking
        $today = User::whereDate('last_login_at', Carbon::today())->count();
        $this_week = User::whereBetween('last_login_at', [Carbon::now()->startOfWeek(), Carbon::now()->endOfWeek()])->count();
        $this_month = User::whereBetween('last_login_at', [Carbon::now()->startOfMonth(), Carbon::now()->endOfMonth()])->count();
        return [
            'today' => $today,
            'this_week' => $this_week,
            'this_month' => $this_month,
        ];
    }

    /**
     * Get subscription summary (for super admin)
     */
    private function getSubscriptionSummary()
    {
        $companies = \App\Models\Company::all();

        return [
            'total_companies' => $companies->count(),
            'active_subscriptions' => $companies->where('status', 1)->count(),
            'trial_companies' => $companies->filter(function ($c) {
                return $c->subscription_plan === 'trial' || !$c->subscription_plan;
            })->count(),
            'expiring_soon' => $companies->filter(function ($c) {
                return $c->subscription_end_date && Carbon::parse($c->subscription_end_date)->diffInDays(now()) <= 30;
            })->count(),
            'revenue_ytd' => 125000, // Replace with actual revenue calculation
        ];
    }

    /**
     * Get platform analytics (for super admin)
     */
    private function getPlatformAnalytics()
    {
        // Cache the result for 60 minutes to prevent slowing down the dashboard when user_logs grows massively
        return Cache::remember('platform_analytics_super_admin', 3600, function () {

            // 1. Calculate Mobile vs Web Usage
            $totalLogs = UserLog::count();

            if ($totalLogs > 0) {
                // Using LIKE on massive tables is generally slow, so caching makes sure it only happens once an hour
                $mobileLogs = UserLog::where(function ($q) {
                    $q->where('user_agent', 'like', '%Mobile%')
                        ->orWhere('user_agent', 'like', '%Android%')
                        ->orWhere('user_agent', 'like', '%iPhone%')
                        ->orWhere('user_agent', 'like', '%iPad%')
                        ->orWhere('user_agent', 'like', '%Dart%'); // Dart is for Flutter mobile app
                })->count();

                $mobileUsagePercentage = round(($mobileLogs / $totalLogs) * 100, 2);
                $webUsagePercentage = 100 - $mobileUsagePercentage;
            } else {
                $mobileUsagePercentage = 0;
                $webUsagePercentage = 0;
            }

            // 2. Calculate Platform Growth Rate (New Companies vs Total Previous Companies)
            // Because SaaS growth is better reflected by new organizations subscribing to the software
            $newCompaniesThisMonth = Company::where('created_at', '>=', Carbon::now()->startOfMonth())->count();
            $totalCompaniesBeforeThisMonth = Company::where('created_at', '<', Carbon::now()->startOfMonth())->count();

            $growthRate = $totalCompaniesBeforeThisMonth > 0
                ? round(($newCompaniesThisMonth / $totalCompaniesBeforeThisMonth) * 100, 2)
                : ($newCompaniesThisMonth > 0 ? 100 : 0);

            return [
                'total_loans_all_time' => Loans::count(),
                'total_customers_all_time' => Customers::count(),
                'total_branches' => BranchModel::count(),
                'total_zones' => Zone::count(),
                'growth_rate' => $growthRate,
                'mobile_usage' => $mobileUsagePercentage,
                'web_usage' => $webUsagePercentage,
            ];
        });
    }

    /**
     * Get recent activities (for super admin)
     */
    private function getRecentActivities()
    {
        // Get recent loan registrations
        $recentLoans = Loans::with(['loan_customer', 'loan_product'])
            ->orderBy('created_at', 'desc')
            ->take(5)
            ->get()
            ->map(function ($loan) {
                return [
                    'type' => 'loan_registered',
                    'description' => "Loan #{$loan->loan_number} registered for {$loan->loan_customer->fullname}",
                    'amount' => (float) $loan->principal_amount,
                    'created_at' => $loan->created_at,
                    'formatted_date' => Carbon::parse($loan->created_at)->diffForHumans(),
                ];
            });

        // Get recent customer registrations
        $recentCustomers = Customers::orderBy('created_at', 'desc')
            ->take(5)
            ->get()
            ->map(function ($customer) {
                return [
                    'type' => 'customer_registered',
                    'description' => "New customer registered: {$customer->fullname}",
                    'created_at' => $customer->created_at,
                    'formatted_date' => Carbon::parse($customer->created_at)->diffForHumans(),
                ];
            });

        // Merge and sort by date
        $activities = $recentLoans->concat($recentCustomers)
            ->sortByDesc('created_at')
            ->take(10)
            ->values();

        return $activities;
    }
}
