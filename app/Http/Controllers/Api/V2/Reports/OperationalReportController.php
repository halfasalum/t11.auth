<?php

namespace App\Http\Controllers\Api\V2\Reports;

use App\Http\Controllers\Api\V2\BaseController;
use App\Models\Expenses;
use App\Models\Income;
use App\Models\Customers;
use App\Models\CustomersZone;
use App\Models\Loans;
use App\Models\PaymentSubmissions;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class OperationalReportController extends BaseController
{
    /**
     * Expense Analysis Report
     */
    public function expenseAnalysis(Request $request)
    {
        try {
            $companyId = $this->getCompanyId();
            $period = $request->get('period', 'monthly');
            $year = $request->get('year', Carbon::now()->year);

            $expenses = Expenses::where('expenses.company_id', $companyId)
                ->whereYear('expense_date', $year)
                ->join('expense_categories', 'expenses.category_id', '=', 'expense_categories.id')
                ->select(
                    'expense_categories.name as category',
                    'expense_categories.is_staff_related',
                    DB::raw('SUM(expenses.amount) as amount'),
                    DB::raw('MONTH(expenses.expense_date) as month')
                )
                ->groupBy('expense_categories.id', 'expense_categories.name', 'expense_categories.is_staff_related', 'month')
                ->get();

            $totalExpenses = $expenses->sum('amount');

            $byCategory = $expenses->groupBy('category')->map(function ($items, $category) use ($totalExpenses) {
                $amount = $items->sum('amount');
                return [
                    'category' => $category,
                    'amount' => (float) $amount,
                    'percentage' => $totalExpenses > 0 ? round(($amount / $totalExpenses) * 100, 2) : 0,
                    'is_staff_related' => $items->first()->is_staff_related,
                    'monthly_trend' => $items->map(function ($item) {
                        return [
                            'month' => Carbon::create()->month($item->month)->format('M'),
                            'amount' => (float) $item->amount,
                        ];
                    })->values(),
                ];
            })->values();

            return $this->successResponse($byCategory, 'Expense analysis retrieved successfully');
        } catch (\Exception $e) {
            return $this->errorResponse('Failed to load expense analysis: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Income Analysis Report
     */
    public function incomeAnalysis(Request $request)
    {
        try {
            $companyId = $this->getCompanyId();
            $period = $request->get('period', 'monthly');
            $year = $request->get('year', Carbon::now()->year);

            // Interest income from loans
            $interestIncome = PaymentSubmissions::where('company', $companyId)
                ->where('submission_status', 11)
                ->whereYear('submitted_date', $year)
                ->select(
                    DB::raw('SUM(paid_interest) as amount'),
                    DB::raw('MONTH(submitted_date) as month')
                )
                ->groupBy('month')
                ->get();

            // Penalty income
            $penaltyIncome = PaymentSubmissions::where('company', $companyId)
                ->where('submission_status', 11)
                ->whereYear('submitted_date', $year)
                ->whereHas('schedule', function ($q) {
                    $q->where('is_penalty', true);
                })
                ->select(
                    DB::raw('SUM(amount) as amount'),
                    DB::raw('MONTH(submitted_date) as month')
                )
                ->groupBy('month')
                ->get();

            // Other income
            $otherIncome = Income::where('income_company', $companyId)
                ->whereYear('income_date', $year)
                ->select(
                    DB::raw('SUM(income_amount) as amount'),
                    DB::raw('MONTH(income_date) as month')
                )
                ->groupBy('month')
                ->get();

            $months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

            $incomeBySource = [
                [
                    'source' => 'Interest Income',
                    'amount' => (float) $interestIncome->sum('amount'),
                    'monthly_trend' => $this->buildMonthlyTrend($interestIncome, $months),
                ],
                [
                    'source' => 'Penalty Income',
                    'amount' => (float) $penaltyIncome->sum('amount'),
                    'monthly_trend' => $this->buildMonthlyTrend($penaltyIncome, $months),
                ],
                [
                    'source' => 'Other Income',
                    'amount' => (float) $otherIncome->sum('amount'),
                    'monthly_trend' => $this->buildMonthlyTrend($otherIncome, $months),
                ],
            ];

            $totalIncome = array_sum(array_column($incomeBySource, 'amount'));

            foreach ($incomeBySource as &$source) {
                $source['percentage'] = $totalIncome > 0 ? round(($source['amount'] / $totalIncome) * 100, 2) : 0;
                $source['growth_rate'] = $this->calculateGrowthRate($source['monthly_trend']);
            }

            return $this->successResponse($incomeBySource, 'Income analysis retrieved successfully');
        } catch (\Exception $e) {
            return $this->errorResponse('Failed to load income analysis: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Customer Acquisition Report
     */
    public function customerAcquisition(Request $request)
    {
        try {
            $companyId = $this->getCompanyId();
            $period = $request->get('period', 'monthly');
            $year = $request->get('year', Carbon::now()->year);

            $customers = Customers::byCompany($companyId)
                ->whereYear('created_at', $year)
                ->get();

            $monthlyData = [];
            for ($i = 1; $i <= 12; $i++) {
                $monthStart = Carbon::create($year, $i, 1)->startOfMonth();
                $monthEnd = $monthStart->copy()->endOfMonth();

                $newCustomers = $customers->filter(function ($c) use ($monthStart, $monthEnd) {
                    return Carbon::parse($c->created_at)->between($monthStart, $monthEnd);
                })->count();

                $totalCustomers = $customers->filter(function ($c) use ($monthEnd) {
                    return Carbon::parse($c->created_at)->lte($monthEnd);
                })->count();

                $monthlyData[] = [
                    'month' => $monthStart->format('M'),
                    'new_customers' => $newCustomers,
                    'total_customers' => $totalCustomers,
                ];
            }

            $currentPeriod = $period === 'monthly' ? $monthlyData[date('n') - 1]['new_customers'] ?? 0 : array_sum(array_column($monthlyData, 'new_customers'));
            $totalCustomers = $customers->count();
            $growthRate = $totalCustomers > 0 ? round(($currentPeriod / $totalCustomers) * 100, 2) : 0;

            // Acquisition by source (simplified - you can expand based on your data)
            $bySource = [
                ['source' => 'Walk-in', 'count' => (int) ($totalCustomers * 0.4), 'percentage' => 40],
                ['source' => 'Referral', 'count' => (int) ($totalCustomers * 0.35), 'percentage' => 35],
                ['source' => 'Marketing', 'count' => (int) ($totalCustomers * 0.15), 'percentage' => 15],
                ['source' => 'Online', 'count' => (int) ($totalCustomers * 0.1), 'percentage' => 10],
            ];

            $data = [
                'period' => $period,
                'new_customers' => $currentPeriod,
                'total_customers' => $totalCustomers,
                'growth_rate' => $growthRate,
                'by_source' => $bySource,
                'monthly_data' => $monthlyData,
            ];

            return $this->successResponse($data, 'Customer acquisition retrieved successfully');
        } catch (\Exception $e) {
            return $this->errorResponse('Failed to load customer acquisition: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Loan Approval Efficiency Report
     */
    public function loanApprovalEfficiency(Request $request)
    {
        try {
            $companyId = $this->getCompanyId();
            $period = $request->get('period', 'monthly');
            $year = $request->get('year', Carbon::now()->year);

            $loans = Loans::where('company', $companyId)

                ->whereYear('created_at', $year)
                ->with(['registeredBy']) // Eager load the user who registered
                ->get();

            $totalApplications = $loans->count();
            $approved = $loans->whereIn('status', [Loans::STATUS_ACTIVE, Loans::STATUS_OVERDUE, Loans::STATUS_DEFAULTED, Loans::STATUS_COMPLETED])->count();
            $rejected = $loans->where('status', Loans::STATUS_REJECTED)->count();
            $pending = $loans->where('status', Loans::STATUS_SUBMITTED)->count();
            $approvalRate = $totalApplications > 0 ? round(($approved / $totalApplications) * 100, 2) : 0;

            // Calculate average approval time (using actual data)
            $avgApprovalTime = $this->calculateAverageApprovalTime($loans);

            // Get performance by officer (using actual registered_by data)
            $byOfficer = $this->getPerformanceByOfficer($loans);

            // Monthly trend
            $monthlyTrend = [];
            for ($i = 1; $i <= 12; $i++) {
                $monthStart = Carbon::create($year, $i, 1)->startOfMonth();
                $monthEnd = $monthStart->copy()->endOfMonth();

                $monthLoans = $loans->filter(function ($l) use ($monthStart, $monthEnd) {
                    return Carbon::parse($l->created_at)->between($monthStart, $monthEnd);
                });

                $monthlyTrend[] = [
                    'month' => $monthStart->format('M'),
                    'applications' => $monthLoans->count(),
                    'approved' => $monthLoans->where('status', Loans::STATUS_ACTIVE)->count(),
                    'approval_rate' => $monthLoans->count() > 0 ? round(($monthLoans->where('status', Loans::STATUS_ACTIVE)->count() / $monthLoans->count()) * 100, 2) : 0,
                ];
            }

            $data = [
                'total_applications' => $totalApplications,
                'approved' => $approved,
                'rejected' => $rejected,
                'pending' => $pending,
                'approval_rate' => $approvalRate,
                'avg_approval_time_hours' => $avgApprovalTime,
                'by_officer' => $byOfficer,
                'monthly_trend' => $monthlyTrend,
            ];

            return $this->successResponse($data, 'Loan approval efficiency retrieved successfully');
        } catch (\Exception $e) {
            Log::error('Loan approval efficiency error: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
            return $this->errorResponse('Failed to load loan approval efficiency: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Calculate average approval time in hours
     */
    private function calculateAverageApprovalTime($loans)
    {
        $totalApprovalTime = 0;
        $approvedLoansCount = 0;

        $approvedStatuses = [
            Loans::STATUS_ACTIVE,
            Loans::STATUS_OVERDUE,
            Loans::STATUS_DEFAULTED,
            Loans::STATUS_COMPLETED
        ];

        foreach ($loans as $loan) {
            // Only calculate for approved loans that have start_date
            if (in_array($loan->status, $approvedStatuses) && $loan->start_date && $loan->created_at) {
                $createdAt = Carbon::parse($loan->created_at);
                $approvedAt = Carbon::parse($loan->start_date);

                // Ensure approval date is not before creation date
                if ($approvedAt->greaterThanOrEqualTo($createdAt)) {
                    $hoursDiff = $createdAt->diffInHours($approvedAt);
                    $totalApprovalTime += $hoursDiff;
                    $approvedLoansCount++;
                } else {
                    // Log warning for data inconsistency
                    Log::warning('Loan has start_date before created_at', [
                        'loan_id' => $loan->id,
                        'loan_number' => $loan->loan_number,
                        'created_at' => $loan->created_at,
                        'start_date' => $loan->start_date
                    ]);
                }
            }
        }

        return $approvedLoansCount > 0 ? round($totalApprovalTime / $approvedLoansCount, 2) : 0;
    }

    /**
     * Get performance metrics by officer (alternative using direct query)
     */
    private function getPerformanceByOfficer($loans)
    {
        // Get unique officer IDs from loans
        $officerIds = $loans->pluck('registered_by')->filter()->unique()->toArray();

        if (empty($officerIds)) {
            Log::warning('getPerformanceByOfficer - No officer IDs found in loans');
            return [];
        }

        // Fetch officers from database
        $officers = User::whereIn('id', $officerIds)->get()->keyBy('id');

        Log::info('getPerformanceByOfficer - Officers found', [
            'officer_ids' => $officerIds,
            'officers_count' => $officers->count()
        ]);

        $approvedStatuses = [
            Loans::STATUS_ACTIVE,
            Loans::STATUS_OVERDUE,
            Loans::STATUS_DEFAULTED,
            Loans::STATUS_COMPLETED
        ];

        $officerPerformance = [];

        foreach ($officerIds as $officerId) {
            $officer = $officers->get($officerId);

            if (!$officer) {
                Log::warning('getPerformanceByOfficer - Officer not found in DB', [
                    'officer_id' => $officerId
                ]);
                continue;
            }

            $officerLoans = $loans->where('registered_by', $officerId);
            $totalApplications = $officerLoans->count();
            $approved = $officerLoans->whereIn('status', $approvedStatuses)->count();
            $rejected = $officerLoans->where('status', Loans::STATUS_REJECTED)->count();
            $approvalRate = $totalApplications > 0 ? round(($approved / $totalApplications) * 100, 2) : 0;

            // Calculate average approval time
            $totalApprovalTime = 0;
            $approvedCount = 0;

            foreach ($officerLoans as $loan) {
                if (in_array($loan->status, $approvedStatuses) && $loan->start_date && $loan->created_at) {
                    $createdAt = Carbon::parse($loan->created_at);
                    $approvedAt = Carbon::parse($loan->start_date);

                    if ($approvedAt->greaterThanOrEqualTo($createdAt)) {
                        $totalApprovalTime += $createdAt->diffInHours($approvedAt);
                        $approvedCount++;
                    }
                }
            }

            $avgTime = $approvedCount > 0 ? round($totalApprovalTime / $approvedCount, 2) : 0;

            $officerPerformance[] = [
                'officer_id' => $officer->id,
                'officer_name' => $officer->name ?? $officer->fullname ?? 'Unknown',
                'officer_email' => $officer->email ?? '',
                'applications' => $totalApplications,
                'approved' => $approved,
                'rejected' => $rejected,
                'approval_rate' => $approvalRate,
                'avg_time' => $avgTime,
            ];
        }

        // Sort by number of applications (highest first)
        usort($officerPerformance, function ($a, $b) {
            return $b['applications'] <=> $a['applications'];
        });

        return $officerPerformance;
    }

    private function buildMonthlyTrend($data, $months)
    {
        $trend = [];
        foreach ($months as $index => $month) {
            $monthNum = $index + 1;
            $item = $data->firstWhere('month', $monthNum);
            $trend[] = [
                'month' => $month,
                'amount' => (float) ($item->amount ?? 0),
            ];
        }
        return $trend;
    }

    private function calculateGrowthRate($trend)
    {
        if (count($trend) < 2) return 0;
        $lastTwo = array_slice($trend, -2);
        $previous = $lastTwo[0]['amount'];
        $current = $lastTwo[1]['amount'];
        return $previous > 0 ? round((($current - $previous) / $previous) * 100, 2) : 0;
    }
}
