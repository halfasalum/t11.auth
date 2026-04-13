<?php

namespace App\Http\Controllers\Api\V2\Reports;

use App\Http\Controllers\Api\V2\BaseController;
use App\Models\Loans;
use App\Models\PaymentSubmissions;
use App\Models\LoansProducts;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class ComplianceReportController extends BaseController
{
    /**
     * Interest Income Report
     */
    public function interestIncomeReport(Request $request)
    {
        try {
            $companyId = $this->getCompanyId();
            $year = $request->get('year', Carbon::now()->year);
            
            $interestIncome = PaymentSubmissions::where('company', $companyId)
                ->where('submission_status', 11)
                ->whereYear('submitted_date', $year)
                ->sum('paid_interest');
            
            // By product
            $products = LoansProducts::where('company', $companyId)
                ->where('status', 1)
                ->get();
            
            $byProduct = [];
            foreach ($products as $product) {
                $productInterest = PaymentSubmissions::where('company', $companyId)
                    ->where('submission_status', 11)
                    ->whereYear('submitted_date', $year)
                    ->whereHas('loan', function ($q) use ($product) {
                        $q->where('product', $product->id);
                    })
                    ->sum('paid_interest');
                
                $byProduct[] = [
                    'product_name' => $product->product_name,
                    'interest_earned' => (float) $productInterest,
                    'percentage' => $interestIncome > 0 ? round(($productInterest / $interestIncome) * 100, 2) : 0,
                    'avg_rate' => (float) $product->interest_rate,
                ];
            }
            
            // Monthly breakdown
            $monthlyData = [];
            $months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
            for ($i = 1; $i <= 12; $i++) {
                $monthStart = Carbon::create($year, $i, 1)->startOfMonth();
                $monthEnd = $monthStart->copy()->endOfMonth();
                
                $monthInterest = PaymentSubmissions::where('company', $companyId)
                    ->where('submission_status', 11)
                    ->whereBetween('submitted_date', [$monthStart, $monthEnd])
                    ->sum('paid_interest');
                
                $activeLoans = Loans::where('company', $companyId)
                    ->whereIn('status', [Loans::STATUS_ACTIVE, Loans::STATUS_OVERDUE])
                    ->whereBetween('start_date', [$monthStart, $monthEnd])
                    ->count();
                
                $monthlyData[] = [
                    'month' => $months[$i - 1],
                    'interest' => (float) $monthInterest,
                    'loans' => $activeLoans,
                ];
            }
            
            // Projected vs Actual (simplified)
            $projectedData = [];
            foreach ($monthlyData as $data) {
                $projectedData[] = [
                    'month' => $data['month'],
                    'projected' => $data['interest'] * 1.1, // 10% growth projection
                    'actual' => $data['interest'],
                ];
            }
            
            $data = [
                'total_interest_earned' => (float) $interestIncome,
                'average_interest_rate' => round(LoansProducts::where('company', $companyId)->avg('interest_rate') ?? 0, 2),
                'by_product' => $byProduct,
                'by_month' => $monthlyData,
                'projected_interest' => $projectedData,
            ];
            
            return $this->successResponse($data, 'Interest income report retrieved successfully');
            
        } catch (\Exception $e) {
            return $this->errorResponse('Failed to load interest income report: ' . $e->getMessage(), 500);
        }
    }
    
    /**
     * Loan Loss Provision Report
     */
    public function loanLossProvision(Request $request)
    {
        try {
            $companyId = $this->getCompanyId();
            
            $totalPortfolio = Loans::where('company', $companyId)
                ->whereIn('status', [Loans::STATUS_ACTIVE, Loans::STATUS_OVERDUE])
                ->get()
                ->sum(function ($loan) {
                    return ($loan->total_loan + ($loan->penalty_amount ?? 0)) - ($loan->loan_paid ?? 0);
                });
            
            // Historical loss rate (last 12 months)
            $yearAgo = Carbon::now()->subYear();
            $defaultedLoans = Loans::where('company', $companyId)
                ->where('status', Loans::STATUS_DEFAULTED)
                ->where('updated_at', '>=', $yearAgo)
                ->get();
            
            $historicalLossAmount = $defaultedLoans->sum(function ($loan) {
                return ($loan->total_loan + ($loan->penalty_amount ?? 0)) - ($loan->loan_paid ?? 0);
            });
            
            $historicalLossRate = $totalPortfolio > 0 ? round(($historicalLossAmount / $totalPortfolio) * 100, 2) : 0;
            
            // Provision by risk category
            $riskCategories = [
                ['category' => 'Standard (Low Risk)', 'provision_rate' => 1, 'exposure' => 0],
                ['category' => 'Watch (Medium Risk)', 'provision_rate' => 10, 'exposure' => 0],
                ['category' => 'Substandard', 'provision_rate' => 25, 'exposure' => 0],
                ['category' => 'Doubtful', 'provision_rate' => 50, 'exposure' => 0],
                ['category' => 'Loss', 'provision_rate' => 100, 'exposure' => 0],
            ];
            
            $loans = Loans::where('company', $companyId)
                ->whereIn('status', [Loans::STATUS_ACTIVE, Loans::STATUS_OVERDUE])
                ->get();
            
            foreach ($loans as $loan) {
                $outstanding = ($loan->total_loan + ($loan->penalty_amount ?? 0)) - ($loan->loan_paid ?? 0);
                $daysOverdue = $loan->end_date ? max(0, Carbon::parse($loan->end_date)->diffInDays(now())) : 0;
                
                if ($daysOverdue >= 180) {
                    $riskCategories[4]['exposure'] += $outstanding; // Loss
                } elseif ($daysOverdue >= 120) {
                    $riskCategories[3]['exposure'] += $outstanding; // Doubtful
                } elseif ($daysOverdue >= 90) {
                    $riskCategories[2]['exposure'] += $outstanding; // Substandard
                } elseif ($daysOverdue >= 30) {
                    $riskCategories[1]['exposure'] += $outstanding; // Watch
                } else {
                    $riskCategories[0]['exposure'] += $outstanding; // Standard
                }
            }
            
            $totalProvision = 0;
            foreach ($riskCategories as &$category) {
                $category['provision_amount'] = ($category['exposure'] * $category['provision_rate']) / 100;
                $totalProvision += $category['provision_amount'];
            }
            
            $provisionCoverage = $totalPortfolio > 0 ? round(($totalProvision / $totalPortfolio) * 100, 2) : 0;
            
            // Recommended provision (using 150% of historical loss rate)
            $recommendedProvision = $totalPortfolio * ($historicalLossRate * 1.5 / 100);
            $shortfall = max(0, $recommendedProvision - $totalProvision);
            
            $data = [
                'total_portfolio' => (float) $totalPortfolio,
                'total_provision' => (float) $totalProvision,
                'provision_coverage_ratio' => $provisionCoverage,
                'by_category' => $riskCategories,
                'historical_loss_rate' => $historicalLossRate,
                'recommended_provision' => (float) $recommendedProvision,
                'current_provision' => (float) $totalProvision,
                'shortfall' => (float) $shortfall,
            ];
            
            return $this->successResponse($data, 'Loan loss provision report retrieved successfully');
            
        } catch (\Exception $e) {
            return $this->errorResponse('Failed to load loan loss provision report: ' . $e->getMessage(), 500);
        }
    }
}