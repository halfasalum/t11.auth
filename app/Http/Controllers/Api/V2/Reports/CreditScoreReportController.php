<?php

namespace App\Http\Controllers\Api\V2\Reports;

use App\Http\Controllers\Api\V2\BaseController;
use App\Models\Customers;
use App\Models\Loans;
use App\Models\PaymentSubmissions;
use App\Models\CustomersZone;
use Illuminate\Http\Request;
use Carbon\Carbon;

class CreditScoreReportController extends BaseController
{
    /**
     * Credit Score Report
     */
    public function creditScoreReport(Request $request)
    {
        try {
            $companyId = $this->getCompanyId();
            $customerId = $request->get('customer_id');
            
            $customers = Customers::byCompany($companyId)
                ->with(['loans', 'zoneAssignment'])
                ->when($customerId, function ($query, $customerId) {
                    return $query->where('id', $customerId);
                })
                ->get();
            
            $scoredCustomers = [];
            $totalScore = 0;
            $distribution = [
                'excellent' => 0,
                'good' => 0,
                'fair' => 0,
                'poor' => 0,
                'very_poor' => 0,
            ];
            
            foreach ($customers as $customer) {
                $score = $this->calculateCreditScore($customer);
                $scoredCustomers[] = $score;
                $totalScore += $score['credit_score'];
                
                if ($score['credit_score'] >= 800) $distribution['excellent']++;
                elseif ($score['credit_score'] >= 700) $distribution['good']++;
                elseif ($score['credit_score'] >= 600) $distribution['fair']++;
                elseif ($score['credit_score'] >= 500) $distribution['poor']++;
                else $distribution['very_poor']++;
            }
            
            $data = [
                'customers' => $scoredCustomers,
                'summary' => [
                    'total_customers' => $customers->count(),
                    'average_score' => $customers->count() > 0 ? round($totalScore / $customers->count()) : 0,
                    'distribution' => $distribution,
                ],
            ];
            
            return $this->successResponse($data, 'Credit score report generated successfully');
            
        } catch (\Exception $e) {
            return $this->errorResponse('Failed to load credit score report: ' . $e->getMessage(), 500);
        }
    }
    
    private function calculateCreditScore($customer)
    {
        $score = 700;
        $factors = [];
        
        // 1. Payment History (35% - max 350 points)
        $paymentHistoryScore = $this->calculatePaymentHistoryScore($customer);
        $score += $paymentHistoryScore;
        $factors[] = [
            'name' => 'Payment History',
            'score' => $paymentHistoryScore,
            'max_score' => 350,
            'details' => $this->getPaymentHistoryDetails($customer),
        ];
        
        // 2. Outstanding Debt (30% - max 300 points)
        $debtScore = $this->calculateDebtScore($customer);
        $score += $debtScore;
        $factors[] = [
            'name' => 'Outstanding Debt',
            'score' => $debtScore,
            'max_score' => 300,
            'details' => $this->getDebtDetails($customer),
        ];
        
        // 3. Credit History Length (15% - max 150 points)
        $historyScore = $this->calculateHistoryScore($customer);
        $score += $historyScore;
        $factors[] = [
            'name' => 'Credit History',
            'score' => $historyScore,
            'max_score' => 150,
            'details' => $this->getHistoryDetails($customer),
        ];
        
        // 4. Income Stability (10% - max 100 points)
        $incomeScore = $this->calculateIncomeScore($customer);
        $score += $incomeScore;
        $factors[] = [
            'name' => 'Income Stability',
            'score' => $incomeScore,
            'max_score' => 100,
            'details' => $this->getIncomeDetails($customer),
        ];
        
        // 5. Profile Completeness (10% - max 100 points)
        $profileScore = $this->calculateProfileScore($customer);
        $score += $profileScore;
        $factors[] = [
            'name' => 'Profile Completeness',
            'score' => $profileScore,
            'max_score' => 100,
            'details' => $this->getProfileDetails($customer),
        ];
        
        $finalScore = min(1000, max(0, $score));
        
        $rating = match(true) {
            $finalScore >= 800 => 'Excellent',
            $finalScore >= 700 => 'Good',
            $finalScore >= 600 => 'Fair',
            $finalScore >= 500 => 'Poor',
            default => 'Very Poor'
        };
        
        $riskLevel = match(true) {
            $finalScore >= 700 => 'Low Risk',
            $finalScore >= 600 => 'Medium Risk',
            default => 'High Risk'
        };
        
        $maxLoanAmount = $this->calculateMaxLoanAmount($customer, $finalScore);
        
        return [
            'customer_id' => $customer->id,
            'fullname' => $customer->fullname,
            'phone' => $customer->phone,
            'credit_score' => $finalScore,
            'rating' => $rating,
            'risk_level' => $riskLevel,
            'loan_eligibility' => $finalScore >= 600 ? 'Eligible' : 'Not Eligible',
            'max_loan_amount' => (float) $maxLoanAmount,
            'factors' => $factors,
        ];
    }
    
    private function calculatePaymentHistoryScore($customer)
    {
        $loans = $customer->loans;
        if ($loans->isEmpty()) return 200;
        
        $totalPayments = 0;
        $onTimePayments = 0;
        $defaultedLoans = 0;
        
        foreach ($loans as $loan) {
            $payments = PaymentSubmissions::where('loan_number', $loan->loan_number)
                ->where('submission_status', 11)
                ->get();
            
            foreach ($payments as $payment) {
                $totalPayments++;
                $schedule = $payment->schedule;
                if ($schedule && $payment->submitted_date <= $schedule->payment_due_date) {
                    $onTimePayments++;
                }
            }
            
            if ($loan->status == Loans::STATUS_DEFAULTED) {
                $defaultedLoans++;
            }
        }
        
        $score = 0;
        if ($totalPayments > 0) {
            $onTimeRate = ($onTimePayments / $totalPayments) * 100;
            $score = (int)(($onTimeRate / 100) * 300);
        }
        
        $score -= ($defaultedLoans * 50);
        
        return max(0, min(350, $score));
    }
    
    private function calculateDebtScore($customer)
    {
        $totalOutstanding = $customer->loans->sum(function ($loan) {
            return ($loan->total_loan + ($loan->penalty_amount ?? 0)) - ($loan->loan_paid ?? 0);
        });
        
        $monthlyIncome = $customer->income ?? 0;
        $dtiRatio = $monthlyIncome > 0 ? ($totalOutstanding / $monthlyIncome) * 100 : 100;
        
        if ($dtiRatio <= 30) return 300;
        if ($dtiRatio <= 50) return 200;
        if ($dtiRatio <= 70) return 100;
        return 50;
    }
    
    private function calculateHistoryScore($customer)
    {
        $firstLoanDate = $customer->loans->min('created_at');
        if (!$firstLoanDate) return 75;
        
        $yearsWithCompany = Carbon::parse($firstLoanDate)->diffInYears(now());
        
        if ($yearsWithCompany >= 3) return 150;
        if ($yearsWithCompany >= 2) return 120;
        if ($yearsWithCompany >= 1) return 90;
        return 60;
    }
    
    private function calculateIncomeScore($customer)
    {
        $income = $customer->income ?? 0;
        
        if ($income >= 1000000) return 100;
        if ($income >= 500000) return 80;
        if ($income >= 250000) return 60;
        if ($income >= 100000) return 40;
        return 20;
    }
    
    private function calculateProfileScore($customer)
    {
        return $customer->profile_completeness ?? 0;
    }
    
    private function calculateMaxLoanAmount($customer, $score)
    {
        $monthlyIncome = $customer->income ?? 0;
        $baseMultiplier = match(true) {
            $score >= 800 => 5,
            $score >= 700 => 4,
            $score >= 600 => 3,
            $score >= 500 => 2,
            default => 1,
        };
        
        return $monthlyIncome * $baseMultiplier;
    }
    
    private function getPaymentHistoryDetails($customer)
    {
        $loans = $customer->loans;
        $totalPayments = 0;
        $onTimePayments = 0;
        
        foreach ($loans as $loan) {
            $payments = PaymentSubmissions::where('loan_number', $loan->loan_number)
                ->where('submission_status', 11)
                ->get();
            
            foreach ($payments as $payment) {
                $totalPayments++;
                $schedule = $payment->schedule;
                if ($schedule && $payment->submitted_date <= $schedule->payment_due_date) {
                    $onTimePayments++;
                }
            }
        }
        
        return [
            'total_payments' => $totalPayments,
            'on_time_payments' => $onTimePayments,
            'on_time_rate' => $totalPayments > 0 ? round(($onTimePayments / $totalPayments) * 100, 2) : 0,
        ];
    }
    
    private function getDebtDetails($customer)
    {
        $totalOutstanding = $customer->loans->sum(function ($loan) {
            return ($loan->total_loan + ($loan->penalty_amount ?? 0)) - ($loan->loan_paid ?? 0);
        });
        
        $monthlyIncome = $customer->income ?? 0;
        $dtiRatio = $monthlyIncome > 0 ? ($totalOutstanding / $monthlyIncome) * 100 : 100;
        
        return [
            'total_outstanding' => (float) $totalOutstanding,
            'monthly_income' => (float) $monthlyIncome,
            'dti_ratio' => round($dtiRatio, 2),
        ];
    }
    
    private function getHistoryDetails($customer)
    {
        $firstLoanDate = $customer->loans->min('created_at');
        $totalLoans = $customer->loans->count();
        
        return [
            'first_loan_date' => $firstLoanDate,
            'years_with_company' => $firstLoanDate ? Carbon::parse($firstLoanDate)->diffInYears(now()) : 0,
            'total_loans' => $totalLoans,
        ];
    }
    
    private function getIncomeDetails($customer)
    {
        return [
            'monthly_income' => (float) ($customer->income ?? 0),
            'employment_type' => $customer->employment_label ?? 'Not specified',
            'experience_years' => $customer->experience ?? 0,
        ];
    }
    
    private function getProfileDetails($customer)
    {
        $zoneAssignment = $customer->zoneAssignment;
        
        return [
            'completeness_score' => $customer->profile_completeness ?? 0,
            'has_referee' => $zoneAssignment ? (bool) $zoneAssignment->has_referee : false,
            'has_attachments' => $zoneAssignment ? (bool) $zoneAssignment->has_attachments : false,
            'has_collateral' => $customer->collaterals()->count() > 0,
        ];
    }
}