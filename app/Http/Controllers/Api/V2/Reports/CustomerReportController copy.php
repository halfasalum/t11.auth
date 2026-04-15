<?php

namespace App\Http\Controllers\Api\V2\Reports;

use App\Http\Controllers\Api\V2\BaseController;
use App\Models\Customers;
use App\Models\Loans;
use App\Models\PaymentSubmissions;
use App\Models\CustomersZone;
use Illuminate\Http\Request;
use Carbon\Carbon;

class CustomerReportController extends BaseController
{
    /**
     * Repayment Behavior Report
     */
    public function repaymentBehavior(Request $request)
    {
        try {
            $companyId = $this->getCompanyId();
            
            $customers = Customers::byCompany($companyId)
                ->with(['loans', 'zoneAssignment'])
                ->get();
            
            $result = [];
            foreach ($customers as $customer) {
                $totalExpected = 0;
                $totalPaid = 0;
                $onTimePayments = 0;
                $latePayments = 0;
                $totalDaysLate = 0;
                
                foreach ($customer->loans as $loan) {
                    $schedules = $loan->schedules;
                    $totalExpected += $schedules->sum('payment_total_amount');
                    
                    $payments = PaymentSubmissions::where('loan_number', $loan->loan_number)
                        ->where('submission_status', 11)
                        ->get();
                    
                    foreach ($payments as $payment) {
                        $totalPaid += $payment->amount;
                        $schedule = $payment->schedule;
                        
                        if ($schedule && $payment->submitted_date <= $schedule->payment_due_date) {
                            $onTimePayments++;
                        } else {
                            $latePayments++;
                            if ($schedule) {
                                $daysLate = Carbon::parse($payment->submitted_date)->diffInDays($schedule->payment_due_date);
                                $totalDaysLate += $daysLate;
                            }
                        }
                    }
                }
                
                $onTimeRate = $totalExpected > 0 ? round(($totalPaid / $totalExpected) * 100, 2) : 0;
                $avgDaysLate = $latePayments > 0 ? round($totalDaysLate / $latePayments, 2) : 0;
                
                // Determine risk level
                $riskLevel = 'Low Risk';
                if ($onTimeRate < 70) {
                    $riskLevel = 'High Risk';
                } elseif ($onTimeRate < 90) {
                    $riskLevel = 'Medium Risk';
                }
                
                $result[] = [
                    'customer_id' => $customer->id,
                    'customer_name' => $customer->fullname,
                    'phone' => $customer->phone,
                    'total_loans' => $customer->loans->count(),
                    'total_expected' => (float) $totalExpected,
                    'total_paid' => (float) $totalPaid,
                    'on_time_payments' => $onTimePayments,
                    'late_payments' => $latePayments,
                    'on_time_rate' => $onTimeRate,
                    'avg_days_late' => $avgDaysLate,
                    'risk_level' => $riskLevel,
                ];
            }
            
            return $this->successResponse($result, 'Repayment behavior retrieved successfully');
            
        } catch (\Exception $e) {
            return $this->errorResponse('Failed to load repayment behavior: ' . $e->getMessage(), 500);
        }
    }
    
    /**
     * Customer Eligibility Report
     */
    public function customerEligibility(Request $request)
    {
        try {
            $companyId = $this->getCompanyId();
            
            $customers = Customers::byCompany($companyId)
                ->with(['loans', 'zoneAssignment'])
                ->get();
            
            $result = [];
            foreach ($customers as $customer) {
                // Calculate credit score (reuse from CreditScoreReportController logic)
                $creditScore = $this->calculateSimpleCreditScore($customer);
                
                $monthlyIncome = $customer->income ?? 0;
                $existingDebt = $customer->loans->sum(function ($loan) {
                    return ($loan->total_loan + ($loan->penalty_amount ?? 0)) - ($loan->loan_paid ?? 0);
                });
                
                $dtiRatio = $monthlyIncome > 0 ? round(($existingDebt / $monthlyIncome) * 100, 2) : 100;
                
                // Determine eligibility
                $eligibilityStatus = 'Not Eligible';
                $maxLoanAmount = 0;
                $recommendedProduct = null;
                
                if ($creditScore >= 600 && $dtiRatio <= 50) {
                    $eligibilityStatus = 'Eligible';
                    $maxLoanAmount = $monthlyIncome * 3;
                    $recommendedProduct = $creditScore >= 700 ? 'Premium Loan' : 'Standard Loan';
                } elseif ($creditScore >= 500 && $dtiRatio <= 70) {
                    $eligibilityStatus = 'Conditional';
                    $maxLoanAmount = $monthlyIncome * 2;
                    $recommendedProduct = 'Basic Loan';
                }
                
                $missingRequirements = [];
                if (!$customer->zoneAssignment || !$customer->zoneAssignment->has_referee) {
                    $missingRequirements[] = 'Add referee';
                }
                if (!$customer->zoneAssignment || !$customer->zoneAssignment->has_attachments) {
                    $missingRequirements[] = 'Upload documents';
                }
                if ($dtiRatio > 50) {
                    $missingRequirements[] = 'Reduce existing debt';
                }
                
                $result[] = [
                    'customer_id' => $customer->id,
                    'customer_name' => $customer->fullname,
                    'phone' => $customer->phone,
                    'credit_score' => $creditScore,
                    'monthly_income' => (float) $monthlyIncome,
                    'existing_debt' => (float) $existingDebt,
                    'dti_ratio' => $dtiRatio,
                    'eligibility_status' => $eligibilityStatus,
                    'max_loan_amount' => (float) $maxLoanAmount,
                    'recommended_product' => $recommendedProduct,
                    'missing_requirements' => $missingRequirements,
                ];
            }
            
            return $this->successResponse($result, 'Customer eligibility retrieved successfully');
            
        } catch (\Exception $e) {
            return $this->errorResponse('Failed to load customer eligibility: ' . $e->getMessage(), 500);
        }
    }
    
    /**
     * Top Borrowers Report
     */
    public function topBorrowers(Request $request)
    {
        try {
            $companyId = $this->getCompanyId();
            $limit = $request->get('limit', 50);
            
            $customers = Customers::byCompany($companyId)
                ->with(['loans'])
                ->get();
            
            $borrowers = [];
            foreach ($customers as $customer) {
                $totalBorrowed = $customer->loans->sum('principal_amount');
                $totalRepaid = $customer->loans->sum('loan_paid');
                $outstandingBalance = $totalBorrowed - $totalRepaid;
                $repaymentRate = $totalBorrowed > 0 ? round(($totalRepaid / $totalBorrowed) * 100, 2) : 0;
                $creditScore = $this->calculateSimpleCreditScore($customer);
                
                $borrowers[] = [
                    'customer_id' => $customer->id,
                    'customer_name' => $customer->fullname,
                    'phone' => $customer->phone,
                    'total_loans' => $customer->loans->count(),
                    'total_borrowed' => (float) $totalBorrowed,
                    'total_repaid' => (float) $totalRepaid,
                    'outstanding_balance' => (float) $outstandingBalance,
                    'repayment_rate' => $repaymentRate,
                    'credit_score' => $creditScore,
                ];
            }
            
            // Sort by total borrowed descending
            usort($borrowers, function ($a, $b) {
                return $b['total_borrowed'] <=> $a['total_borrowed'];
            });
            
            // Add rank
            $rankedBorrowers = array_map(function ($borrower, $index) {
                $borrower['rank'] = $index + 1;
                return $borrower;
            }, array_slice($borrowers, 0, $limit), array_keys(array_slice($borrowers, 0, $limit)));
            
            return $this->successResponse($rankedBorrowers, 'Top borrowers retrieved successfully');
            
        } catch (\Exception $e) {
            return $this->errorResponse('Failed to load top borrowers: ' . $e->getMessage(), 500);
        }
    }
    
    private function calculateSimpleCreditScore($customer)
    {
        $score = 700;
        
        // Payment history factor
        $totalPayments = 0;
        $onTimePayments = 0;
        foreach ($customer->loans as $loan) {
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
        if ($totalPayments > 0) {
            $onTimeRate = ($onTimePayments / $totalPayments) * 100;
            $score += ($onTimeRate / 100) * 150;
        }
        
        // Debt factor
        $totalDebt = $customer->loans->sum(function ($loan) {
            return ($loan->total_loan + ($loan->penalty_amount ?? 0)) - ($loan->loan_paid ?? 0);
        });
        $income = $customer->income ?? 0;
        $dtiRatio = $income > 0 ? ($totalDebt / $income) * 100 : 100;
        if ($dtiRatio <= 30) $score += 100;
        elseif ($dtiRatio <= 50) $score += 50;
        elseif ($dtiRatio > 70) $score -= 50;
        
        return min(1000, max(0, $score));
    }
}