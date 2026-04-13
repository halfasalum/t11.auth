<?php

namespace App\Http\Controllers\Api\V2\Reports;

use App\Http\Controllers\Api\V2\BaseController;
use App\Models\LoanPaymentSchedules;
use App\Models\PaymentSubmissions;
use App\Models\Loans;
use App\Models\Customers;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class CollectionReportController extends BaseController
{
    /**
     * Daily Collection Report
     */
    public function dailyCollection(Request $request)
    {
        try {
            $companyId = $this->getCompanyId();
            $date = $request->get('date', Carbon::today()->toDateString());
            
            $schedules = LoanPaymentSchedules::where('payment_due_date', $date)
                ->whereHas('loan', function ($q) use ($companyId) {
                    $q->where('company', $companyId);
                })
                ->with(['loan.loan_customer'])
                ->get();
            
            $expectedAmount = $schedules->sum('payment_total_amount');
            
            $submissions = PaymentSubmissions::whereDate('submitted_date', $date)
                ->where('company', $companyId)
                ->where('submission_status', 11)
                ->get();
            
            $collectedAmount = $submissions->sum('amount');
            $collectionRate = $expectedAmount > 0 ? round(($collectedAmount / $expectedAmount) * 100, 2) : 0;
            
            $loansData = [];
            foreach ($schedules as $schedule) {
                $payment = $submissions->where('schedule_id', $schedule->id)->first();
                $collected = $payment ? $payment->amount : 0;
                $status = $collected >= $schedule->payment_total_amount ? 'Paid' : ($collected > 0 ? 'Partial' : 'Pending');
                
                $loansData[] = [
                    'loan_number' => $schedule->loan_number,
                    'customer_name' => $schedule->loan->loan_customer->fullname ?? 'Unknown',
                    'expected' => (float) $schedule->payment_total_amount,
                    'collected' => (float) $collected,
                    'status' => $status,
                ];
            }
            
            $data = [
                'date' => $date,
                'expected_amount' => (float) $expectedAmount,
                'collected_amount' => (float) $collectedAmount,
                'collection_rate' => $collectionRate,
                'pending_count' => $loansData->where('status', 'Pending')->count(),
                'completed_count' => $loansData->where('status', 'Paid')->count(),
                'loans' => $loansData,
            ];
            
            return $this->successResponse($data, 'Daily collection retrieved successfully');
            
        } catch (\Exception $e) {
            return $this->errorResponse('Failed to load daily collection: ' . $e->getMessage(), 500);
        }
    }
    
    /**
     * Overdue Report
     */
    public function overdueReport(Request $request)
    {
        try {
            $companyId = $this->getCompanyId();
            
            $loans = Loans::where('company', $companyId)
                ->whereIn('status', [Loans::STATUS_ACTIVE, Loans::STATUS_OVERDUE])
                ->where('end_date', '<', Carbon::today())
                ->with(['loan_customer'])
                ->get();
            
            $overdueLoans = [];
            $totalOverdue = 0;
            $totalAmount = 0;
            $totalDays = 0;
            
            foreach ($loans as $loan) {
                $daysOverdue = Carbon::parse($loan->end_date)->diffInDays(now());
                $outstandingBalance = ($loan->total_loan + ($loan->penalty_amount ?? 0)) - ($loan->loan_paid ?? 0);
                
                // Get last payment
                $lastPayment = PaymentSubmissions::where('loan_number', $loan->loan_number)
                    ->where('submission_status', 11)
                    ->orderBy('submitted_date', 'desc')
                    ->first();
                
                $overdueLoans[] = [
                    'loan_number' => $loan->loan_number,
                    'customer_name' => $loan->loan_customer->fullname ?? 'Unknown',
                    'phone' => $loan->loan_customer->phone ?? 'N/A',
                    'principal_amount' => (float) $loan->principal_amount,
                    'outstanding_balance' => (float) $outstandingBalance,
                    'end_date' => $loan->end_date,
                    'days_overdue' => $daysOverdue,
                    'last_payment_date' => $lastPayment ? $lastPayment->submitted_date : null,
                    'last_payment_amount' => $lastPayment ? (float) $lastPayment->amount : 0,
                ];
                
                $totalOverdue++;
                $totalAmount += $outstandingBalance;
                $totalDays += $daysOverdue;
            }
            
            $data = [
                'loans' => $overdueLoans,
                'summary' => [
                    'total_overdue' => $totalOverdue,
                    'total_amount' => (float) $totalAmount,
                    'avg_days' => $totalOverdue > 0 ? round($totalDays / $totalOverdue, 2) : 0,
                ],
            ];
            
            return $this->successResponse($data, 'Overdue report retrieved successfully');
            
        } catch (\Exception $e) {
            return $this->errorResponse('Failed to load overdue report: ' . $e->getMessage(), 500);
        }
    }
    
    /**
     * Reconciliation Report
     */
    public function reconciliationReport(Request $request)
    {
        try {
            $companyId = $this->getCompanyId();
            $startDate = $request->get('start_date', Carbon::now()->startOfMonth());
            $endDate = $request->get('end_date', Carbon::now());
            
            $schedules = LoanPaymentSchedules::whereBetween('payment_due_date', [$startDate, $endDate])
                ->whereHas('loan', function ($q) use ($companyId) {
                    $q->where('company', $companyId);
                })
                ->get();
            
            $totalExpected = $schedules->sum('payment_total_amount');
            
            $submissions = PaymentSubmissions::whereBetween('submitted_date', [$startDate, $endDate])
                ->where('company', $companyId)
                ->get();
            
            $totalSubmitted = $submissions->sum('amount');
            $totalApproved = $submissions->where('submission_status', 11)->sum('amount');
            $totalRejected = $submissions->where('submission_status', 9)->sum('amount');
            $pendingZone = $submissions->where('submission_status', 4)->sum('amount');
            $pendingBranch = $submissions->where('submission_status', 8)->sum('amount');
            
            $reconciliationRate = $totalExpected > 0 ? round(($totalApproved / $totalExpected) * 100, 2) : 0;
            
            // Find discrepancies
            $discrepancies = [];
            foreach ($schedules as $schedule) {
                $submission = $submissions->where('schedule_id', $schedule->id)->first();
                if ($submission && abs($submission->amount - $schedule->payment_total_amount) > 0.01) {
                    $discrepancies[] = [
                        'loan_number' => $schedule->loan_number,
                        'customer_name' => $schedule->loan->loan_customer->fullname ?? 'Unknown',
                        'expected_amount' => (float) $schedule->payment_total_amount,
                        'submitted_amount' => (float) $submission->amount,
                        'approved_amount' => $submission->submission_status == 11 ? (float) $submission->amount : 0,
                        'status' => $this->getStatusLabel($submission->submission_status),
                        'discrepancy' => (float) abs($submission->amount - $schedule->payment_total_amount),
                    ];
                }
            }
            
            $data = [
                'date' => Carbon::now()->toDateString(),
                'total_expected' => (float) $totalExpected,
                'total_submitted' => (float) $totalSubmitted,
                'total_approved' => (float) $totalApproved,
                'total_rejected' => (float) $totalRejected,
                'pending_zone' => (float) $pendingZone,
                'pending_branch' => (float) $pendingBranch,
                'reconciliation_rate' => $reconciliationRate,
                'discrepancies' => $discrepancies,
            ];
            
            return $this->successResponse($data, 'Reconciliation report retrieved successfully');
            
        } catch (\Exception $e) {
            return $this->errorResponse('Failed to load reconciliation report: ' . $e->getMessage(), 500);
        }
    }
    
    private function getStatusLabel($status)
    {
        $labels = [
            4 => 'Pending Zone Approval',
            8 => 'Pending Branch Approval',
            9 => 'Rejected',
            11 => 'Approved',
        ];
        return $labels[$status] ?? 'Unknown';
    }
}