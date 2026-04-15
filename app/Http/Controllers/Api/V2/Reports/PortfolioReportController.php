<?php

namespace App\Http\Controllers\Api\V2\Reports;

use App\Http\Controllers\Api\V2\BaseController;
use App\Models\Loans;
use App\Models\LoansProducts;
use App\Models\LoanPaymentSchedules;
use App\Models\PaymentSubmissions;
use App\Models\BranchModel;
use App\Models\Zone;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class PortfolioReportController extends BaseController
{
    /**
     * Portfolio Summary Report
     */
    public function portfolioSummary(Request $request)
    {
        try {
            $companyId = $this->getCompanyId();

            // Get active loans
            $activeLoans = Loans::where('company', $companyId)
                ->whereIn('status', [Loans::STATUS_ACTIVE, Loans::STATUS_OVERDUE])
                ->get();

            // Calculate portfolio metrics
            $totalDisbursed = Loans::where('company', $companyId)
                ->whereIn('status', [Loans::STATUS_ACTIVE, Loans::STATUS_COMPLETED, Loans::STATUS_DEFAULTED])
                ->sum('principal_amount');

            $totalRepaid = Loans::where('company', $companyId)
                ->whereIn('status', [Loans::STATUS_ACTIVE, Loans::STATUS_COMPLETED, Loans::STATUS_DEFAULTED])
                ->sum('loan_paid');

            $outstandingBalance = $activeLoans->sum(function ($loan) {
                return ($loan->total_loan + ($loan->penalty_amount ?? 0)) - ($loan->loan_paid ?? 0);
            });

            // Calculate PAR (Portfolio at Risk)
            $par30 = $activeLoans->filter(function ($loan) {
                return $loan->end_date && Carbon::parse($loan->end_date)->diffInDays(now()) > 30;
            })->sum(function ($loan) {
                return ($loan->total_loan + ($loan->penalty_amount ?? 0)) - ($loan->loan_paid ?? 0);
            });

            $par60 = $activeLoans->filter(function ($loan) {
                return $loan->end_date && Carbon::parse($loan->end_date)->diffInDays(now()) > 60;
            })->sum(function ($loan) {
                return ($loan->total_loan + ($loan->penalty_amount ?? 0)) - ($loan->loan_paid ?? 0);
            });

            $par90 = $activeLoans->filter(function ($loan) {
                return $loan->end_date && Carbon::parse($loan->end_date)->diffInDays(now()) > 90;
            })->sum(function ($loan) {
                return ($loan->total_loan + ($loan->penalty_amount ?? 0)) - ($loan->loan_paid ?? 0);
            });

            // Calculate recovery rate
            $recoveryRate = $totalDisbursed > 0 ? ($totalRepaid / $totalDisbursed) * 100 : 0;

            // Get collection efficiency for current month
            $currentMonthStart = Carbon::now()->startOfMonth();
            $expectedCollection = LoanPaymentSchedules::whereBetween('payment_due_date', [$currentMonthStart, Carbon::now()])
                ->whereHas('loan', function ($q) use ($companyId) {
                    $q->where('company', $companyId);
                })
                ->sum('payment_total_amount');

            $actualCollection = PaymentSubmissions::whereBetween('submitted_date', [$currentMonthStart, Carbon::now()])
                ->where('company', $companyId)
                ->where('submission_status', 11)
                ->sum('amount');

            $collectionEfficiency = $expectedCollection > 0 ? ($actualCollection / $expectedCollection) * 100 : 0;

            $data = [
                'summary' => [
                    'total_active_loans' => $activeLoans->count(),
                    'total_disbursed' => (float) $totalDisbursed,
                    'total_repaid' => (float) $totalRepaid,
                    'outstanding_balance' => (float) $outstandingBalance,
                    'recovery_rate' => round($recoveryRate, 2),
                    'collection_efficiency' => round($collectionEfficiency, 2),
                ],
                'portfolio_at_risk' => [
                    'par_30' => (float) $par30,
                    'par_60' => (float) $par60,
                    'par_90' => (float) $par90,
                    'par_30_percentage' => $outstandingBalance > 0 ? round(($par30 / $outstandingBalance) * 100, 2) : 0,
                    'par_60_percentage' => $outstandingBalance > 0 ? round(($par60 / $outstandingBalance) * 100, 2) : 0,
                    'par_90_percentage' => $outstandingBalance > 0 ? round(($par90 / $outstandingBalance) * 100, 2) : 0,
                ],
                'by_branch' => $this->getPortfolioByBranch($companyId),
                'by_product' => $this->getPortfolioByProduct($companyId),
            ];

            return $this->successResponse($data, 'Portfolio summary retrieved successfully');
        } catch (\Exception $e) {
            return $this->errorResponse('Failed to load portfolio summary: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Portfolio by Product Report
     */
    public function portfolioByProduct(Request $request)
    {
        try {
            $companyId = $this->getCompanyId();

            $products = LoansProducts::where('company', $companyId)
                ->where('status', 1)
                ->get();

            $productData = [];
            $totalDisbursed = 0;
            $totalLoans = 0;
            $totalOutstanding = 0;
            $totalDefaultRate = 0;
            $bestPerforming = null;
            $worstPerforming = null;
            $bestDefaultRate = 100;
            $worstDefaultRate = 0;

            foreach ($products as $product) {
                $loans = Loans::where('product', $product->id)
                    ->where('company', $companyId)
                    ->get();

                $totalDisbursedProduct = $loans->sum('principal_amount');
                $totalRepaidProduct = $loans->sum('loan_paid');
                $totalOutstandingProduct = $loans->sum(function ($loan) {
                    return ($loan->total_loan + ($loan->penalty_amount ?? 0)) - ($loan->loan_paid ?? 0);
                });

                $activeLoans = $loans->whereIn('status', [Loans::STATUS_ACTIVE, Loans::STATUS_OVERDUE])->count();
                $completedLoans = $loans->where('status', Loans::STATUS_COMPLETED)->count();
                $defaultedLoans = $loans->where('status', Loans::STATUS_DEFAULTED)->count();
                $overdueLoans = $loans->where('status', Loans::STATUS_OVERDUE)->count();

                $defaultRate = $loans->count() > 0 ? ($defaultedLoans / $loans->count()) * 100 : 0;
                $repaymentPerformance = $totalDisbursedProduct > 0 ? ($totalRepaidProduct / $totalDisbursedProduct) * 100 : 0;

                // Calculate interest collected from payment submissions
                $interestCollected = PaymentSubmissions::where('company', $companyId)
                    ->where('submission_status', 11)
                    ->whereHas('loan', function ($q) use ($product) {
                        $q->where('product', $product->id);
                    })
                    ->sum('paid_interest');

                $productInfo = [
                    'product_name' => $product->product_name,
                    'total_loans' => $loans->count(),
                    'total_disbursed' => (float) $totalDisbursedProduct,
                    'total_outstanding' => (float) $totalOutstandingProduct,
                    'total_repaid' => (float) $totalRepaidProduct,
                    'average_loan_size' => $loans->count() > 0 ? (float) ($totalDisbursedProduct / $loans->count()) : 0,
                    'default_rate' => round($defaultRate, 2),
                    'interest_collected' => (float) $interestCollected,
                    'repayment_performance' => round($repaymentPerformance, 2),
                    'active_loans' => $activeLoans,
                    'completed_loans' => $completedLoans,
                    'defaulted_loans' => $defaultedLoans,
                    'overdue_loans' => $overdueLoans,
                ];

                $productData[] = $productInfo;

                $totalDisbursed += $totalDisbursedProduct;
                $totalLoans += $loans->count();
                $totalOutstanding += $totalOutstandingProduct;

                if ($defaultRate < $bestDefaultRate) {
                    $bestDefaultRate = $defaultRate;
                    $bestPerforming = $product->product_name;
                }
                if ($defaultRate > $worstDefaultRate) {
                    $worstDefaultRate = $defaultRate;
                    $worstPerforming = $product->product_name;
                }
                $totalDefaultRate += $defaultRate;
            }

            $overallDefaultRate = count($productData) > 0 ? round($totalDefaultRate / count($productData), 2) : 0;
            $averageInterestRate = LoansProducts::where('company', $companyId)->avg('interest_rate') ?? 0;

            $data = [
                'products' => $productData,
                'summary' => [
                    'total_products' => count($productData),
                    'total_loans' => $totalLoans,
                    'total_disbursed' => (float) $totalDisbursed,
                    'total_outstanding' => (float) $totalOutstanding,
                    'average_interest_rate' => round($averageInterestRate, 2),
                    'overall_default_rate' => $overallDefaultRate,
                    'best_performing_product' => $bestPerforming,
                    'worst_performing_product' => $worstPerforming,
                ],
            ];

            return $this->successResponse($data, 'Portfolio by product retrieved successfully');
        } catch (\Exception $e) {
            return $this->errorResponse('Failed to load portfolio by product: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Loan Aging Report
     */
    public function loanAgingReport(Request $request)
    {
        try {
            $companyId = $this->getCompanyId();

            $loans = Loans::where('company', $companyId)
                ->whereIn('status', [Loans::STATUS_ACTIVE, Loans::STATUS_OVERDUE])
                ->get();

            $agingBuckets = [
                'current' => ['min' => 0, 'max' => 30, 'label' => 'Current (0-30 days)', 'color' => 'success', 'loans' => [], 'total' => 0],
                '31_60' => ['min' => 31, 'max' => 60, 'label' => '31-60 days overdue', 'color' => 'warning', 'loans' => [], 'total' => 0],
                '61_90' => ['min' => 61, 'max' => 90, 'label' => '61-90 days overdue', 'color' => 'danger', 'loans' => [], 'total' => 0],
                '91_120' => ['min' => 91, 'max' => 120, 'label' => '91-120 days overdue', 'color' => 'danger', 'loans' => [], 'total' => 0],
                '120_plus' => ['min' => 121, 'max' => PHP_INT_MAX, 'label' => '120+ days overdue', 'color' => 'dark', 'loans' => [], 'total' => 0],
            ];

            foreach ($loans as $loan) {
                $daysOverdue = 0;
                if ($loan->end_date) {
                    $daysOverdue = max(0, Carbon::parse($loan->end_date)->diffInDays(now()));
                }

                $outstandingBalance = ($loan->total_loan + ($loan->penalty_amount ?? 0)) - ($loan->loan_paid ?? 0);

                $loanData = [
                    'loan_number' => $loan->loan_number,
                    'customer_name' => $loan->loan_customer->fullname ?? 'Unknown',
                    'principal_amount' => (float) $loan->principal_amount,
                    'outstanding_balance' => (float) $outstandingBalance,
                    'end_date' => $loan->end_date,
                    'days_overdue' => $daysOverdue,
                ];

                if ($daysOverdue <= 30) {
                    $agingBuckets['current']['loans'][] = $loanData;
                    $agingBuckets['current']['total'] += $outstandingBalance;
                } elseif ($daysOverdue <= 60) {
                    $agingBuckets['31_60']['loans'][] = $loanData;
                    $agingBuckets['31_60']['total'] += $outstandingBalance;
                } elseif ($daysOverdue <= 90) {
                    $agingBuckets['61_90']['loans'][] = $loanData;
                    $agingBuckets['61_90']['total'] += $outstandingBalance;
                } elseif ($daysOverdue <= 120) {
                    $agingBuckets['91_120']['loans'][] = $loanData;
                    $agingBuckets['91_120']['total'] += $outstandingBalance;
                } else {
                    $agingBuckets['120_plus']['loans'][] = $loanData;
                    $agingBuckets['120_plus']['total'] += $outstandingBalance;
                }
            }

            $totalOutstanding = array_sum(array_column($agingBuckets, 'total'));

            $data = [
                'aging_buckets' => array_map(function ($bucket) use ($totalOutstanding) {
                    return [
                        'label' => $bucket['label'],
                        'color' => $bucket['color'],
                        'total_amount' => $bucket['total'],
                        'percentage' => $totalOutstanding > 0 ? round(($bucket['total'] / $totalOutstanding) * 100, 2) : 0,
                        'loan_count' => count($bucket['loans']),
                        'loans' => $bucket['loans'],
                    ];
                }, $agingBuckets),
                'summary' => [
                    'total_outstanding' => (float) $totalOutstanding,
                    'total_overdue' => (float) ($agingBuckets['31_60']['total'] + $agingBuckets['61_90']['total'] + $agingBuckets['91_120']['total'] + $agingBuckets['120_plus']['total']),
                    'total_overdue_percentage' => $totalOutstanding > 0 ? round((($agingBuckets['31_60']['total'] + $agingBuckets['61_90']['total'] + $agingBuckets['91_120']['total'] + $agingBuckets['120_plus']['total']) / $totalOutstanding) * 100, 2) : 0,
                ],
            ];

            return $this->successResponse($data, 'Loan aging report retrieved successfully');
        } catch (\Exception $e) {
            return $this->errorResponse('Failed to load loan aging report: ' . $e->getMessage(), 500);
        }
    }

    private function getPortfolioByBranch($companyId)
    {
        $branches = BranchModel::where('company', $companyId)->get();
        $result = [];

        foreach ($branches as $branch) {
            // Get zones under this branch - use proper relationship or direct query
            $zoneIds = Zone::where('branch', $branch->id)->pluck('id');

            if ($zoneIds->isEmpty()) {
                $result[] = [
                    'branch_name' => $branch->branch_name,
                    'total_loans' => 0,
                    'total_outstanding' => 0,
                ];
                continue;
            }

            $loans = Loans::where('company', $companyId)
                ->whereIn('zone', $zoneIds)
                ->whereIn('status', [Loans::STATUS_ACTIVE, Loans::STATUS_OVERDUE])
                ->get();

            $result[] = [
                'branch_name' => $branch->branch_name,
                'total_loans' => $loans->count(),
                'total_outstanding' => (float) $loans->sum(function ($loan) {
                    return ($loan->total_loan + ($loan->penalty_amount ?? 0)) - ($loan->loan_paid ?? 0);
                }),
            ];
        }

        return $result;
    }

    private function getPortfolioByProduct($companyId)
    {
        $products = LoansProducts::where('company', $companyId)
            ->where('status', 1)
            ->get();

        $result = [];

        foreach ($products as $product) {
            $loans = Loans::where('product', $product->id)
                ->where('company', $companyId)
                ->whereIn('status', [Loans::STATUS_ACTIVE, Loans::STATUS_OVERDUE])
                ->get();

            if ($loans->count() > 0) {
                $result[] = [
                    'product_name' => $product->product_name,
                    'total_loans' => $loans->count(),
                    'total_outstanding' => (float) $loans->sum(function ($loan) {
                        return ($loan->total_loan + ($loan->penalty_amount ?? 0)) - ($loan->loan_paid ?? 0);
                    }),
                    'average_loan_size' => (float) ($loans->sum('principal_amount') / $loans->count()),
                ];
            }
        }

        return $result;
    }
}
