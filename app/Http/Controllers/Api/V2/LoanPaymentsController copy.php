<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\BankController;
use App\Models\Accounts;
use App\Models\Branch;
use App\Models\BranchModel;
use App\Models\Customers;
use App\Models\CustomersZone;
use App\Models\LoanPayments;
use App\Models\LoanPaymentSchedules;
use App\Models\Loans;
use App\Models\PaymentSubmissions;
use App\Models\Zone;
use App\Services\UserLogService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class LoanPaymentsControllerBK extends BaseController
{
    /**
     * Main payments dashboard data
     */
    public function loanPayments(UserLogService $userLogService): JsonResponse
    {
        try {
            $userId = $this->getUserId();
            $userCompany = $this->getCompanyId();
            $userZones = $this->getUserZones();
            $userBranches = $this->getUserBranches();

            $userLogService->log('Access', "Access loan payments dashboard", $userId, $userCompany);

            // Fetch all data in parallel using cache where possible
            $cacheKey = "payments_dashboard_{$userCompany}_" . md5(json_encode($userZones) . json_encode($userBranches));

            //$data = Cache::remember($cacheKey, now()->addMinutes(5), function () use ($userZones, $userBranches, $userCompany) {
            // Query accounts directly (avoid calling another controller)
            $accounts = Accounts::where('company', $userCompany)
                ->where('account_status', 1)
                ->select('id', 'account_name')
                ->orderBy('account_name')
                ->get();

            $data = [
                'accounts' => $accounts,
                'todayPayments' => $this->getTodayPayments($userZones, $userCompany),
                'branchApproval' => $this->getZonePaymentApproval($userBranches),
                'managerApproval' => $this->getBranchPaymentApproval($userCompany),
                'managerPreviousApproval' => $this->getManagerPreviousApproval($userCompany),
                'unfilledPayments' => $this->getUnfilledPayments($userZones),
                'rejectedPayments' => $this->getRejectedPayments($userZones),
            ];
            //});

            return $this->successResponse($data, 'Payments data retrieved successfully');
        } catch (\Exception $e) {
            Log::error('Failed to load payments dashboard: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);

            return $this->errorResponse('Failed to load payments data: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Get today's payments with eager loading and optimized queries
     */
    private function getTodayPayments(array $userZones, int $companyId): array
    {
        if (empty($userZones)) {
            return [];
        }

        $today = Carbon::today()->toDateString();

        // Use join instead of whereHas for better performance
        $schedules = LoanPaymentSchedules::select(
            'loan_payment_schedule.id',
            'loan_payment_schedule.loan_number',
            'loan_payment_schedule.payment_total_amount',
            'loan_payment_schedule.branch',
            'loan_payment_schedule.zone',
            'loan_payment_schedule.overdue_flag',
            'loans.customer as customer_id'
        )
            ->join('loans', 'loans.loan_number', '=', 'loan_payment_schedule.loan_number')
            ->where('loan_payment_schedule.payment_due_date', $today)
            ->whereIn('loan_payment_schedule.zone', $userZones)
            ->where('loan_payment_schedule.status', 1)
            ->whereIn('loans.status', [5, 12])
            ->get();

        if ($schedules->isEmpty()) {
            return [];
        }

        // Get all customer IDs from the schedules
        $customerIds = $schedules->pluck('customer_id')->filter()->unique()->toArray();

        // Get customer details in one query
        $customers = Customers::whereIn('id', $customerIds)
            ->select('id', 'fullname', 'phone')
            ->get()
            ->keyBy('id');

        // Get already paid schedule IDs
        $paidScheduleIds = PaymentSubmissions::whereIn('schedule_id', $schedules->pluck('id'))
            ->whereIn('submission_status', [4, 8, 11, 9])
            ->pluck('schedule_id')
            ->toArray();

        // Filter out already paid schedules
        $unpaidSchedules = $schedules->reject(function ($schedule) use ($paidScheduleIds) {
            return in_array($schedule->id, $paidScheduleIds);
        });

        // Format response
        return $unpaidSchedules->map(function ($schedule) use ($customers) {
            $customer = $customers->get($schedule->customer_id);

            return [
                'schedule' => [
                    'id' => $schedule->id,
                    'loan_number' => $schedule->loan_number,
                    'payment_total_amount' => (float) $schedule->payment_total_amount,
                    'branch' => $schedule->branch,
                    'zone' => $schedule->zone,
                    'customer' => $schedule->customer_id,
                    'overdue_flag' => $schedule->overdue_flag ?? 0,
                ],
                'customer' => [
                    'id' => $customer->id ?? null,
                    'fullname' => $customer->fullname ?? 'Unknown',
                    'phone' => $customer->phone ?? '—',
                ]
            ];
        })->values()->toArray();
    }
    /**
     * Get zone payment approval data (for branch managers)
     */
    private function getZonePaymentApproval(array $userBranches): array
    {
        if (empty($userBranches)) {
            return [];
        }

        // Get distinct payment dates with pending approval (status 4)
        $pendingPayments = PaymentSubmissions::whereIn('payment_submissions.branch', $userBranches)
            ->where('submission_status', 4)
            ->join('loan_payment_schedule', 'loan_payment_schedule.id', '=', 'payment_submissions.schedule_id')
            ->where('loan_payment_schedule.status', 1)
            ->select(
                'payment_submissions.zone',
                'loan_payment_schedule.payment_due_date',
                'payment_submissions.submitted_date',
                DB::raw('SUM(payment_submissions.amount) as total_paid'),
                DB::raw('SUM(loan_payment_schedule.payment_total_amount) as total_target')
            )
            ->groupBy('payment_submissions.zone', 'loan_payment_schedule.payment_due_date', 'payment_submissions.submitted_date')
            ->get();

        if ($pendingPayments->isEmpty()) {
            return [];
        }

        // Get zone names in one query
        $zoneIds = $pendingPayments->pluck('zone')->unique();
        $zoneNames = Zone::whereIn('id', $zoneIds)->pluck('zone_name', 'id');

        return $pendingPayments->map(function ($payment) use ($zoneNames) {
            return [
                'zone' => $payment->zone,
                'zone_name' => $zoneNames[$payment->zone] ?? 'Unknown',
                'payment_date' => $payment->payment_due_date,
                'submitted_date' => $payment->submitted_date,
                'total_target' => (float) $payment->total_target,
                'total_paid' => (float) $payment->total_paid,
            ];
        })->values()->toArray();
    }

    /**
     * Get branch payment approval data (for head office)
     */
    private function getBranchPaymentApproval(int $companyId): array
    {
        $pendingPayments = PaymentSubmissions::where('submission_status', 8)
            ->where('payment_submissions.company', $companyId)
            ->join('loan_payment_schedule', 'loan_payment_schedule.id', '=', 'payment_submissions.schedule_id')
            ->select(
                'payment_submissions.branch',
                'loan_payment_schedule.payment_due_date',
                DB::raw('SUM(payment_submissions.amount) as total_paid'),
                DB::raw('SUM(loan_payment_schedule.payment_total_amount) as total_target')
            )
            ->groupBy('payment_submissions.branch', 'loan_payment_schedule.payment_due_date')
            ->get();

        if ($pendingPayments->isEmpty()) {
            return [];
        }

        // Get branch names
        $branchIds = $pendingPayments->pluck('branch')->unique();
        $branchNames = BranchModel::whereIn('id', $branchIds)->pluck('branch_name', 'id');

        return $pendingPayments->map(function ($payment) use ($branchNames) {
            return [
                'branch' => $payment->branch,
                'branch_name' => $branchNames[$payment->branch] ?? 'Unknown',
                'payment_date' => $payment->payment_due_date,
                'total_target' => (float) $payment->total_target,
                'total_paid' => (float) $payment->total_paid,
            ];
        })->values()->toArray();
    }

    /**
     * Get previously approved payments (status 11)
     */
    private function getManagerPreviousApproval(int $companyId): array
    {
        $approvedPayments = PaymentSubmissions::where('submission_status', 11)
            ->where('payment_submissions.company', $companyId)
            ->join('loan_payment_schedule', 'loan_payment_schedule.id', '=', 'payment_submissions.schedule_id')
            ->select(
                'payment_submissions.branch',
                'loan_payment_schedule.payment_due_date',
                DB::raw('SUM(payment_submissions.amount) as total_paid'),
                DB::raw('SUM(loan_payment_schedule.payment_total_amount) as total_target')
            )
            ->groupBy('payment_submissions.branch', 'loan_payment_schedule.payment_due_date')
            ->orderBy('loan_payment_schedule.payment_due_date', 'desc')
            ->limit(50)
            ->get();

        if ($approvedPayments->isEmpty()) {
            return [];
        }

        $branchIds = $approvedPayments->pluck('branch')->unique();
        $branchNames = BranchModel::whereIn('id', $branchIds)->pluck('branch_name', 'id');

        return $approvedPayments->map(function ($payment) use ($branchNames) {
            return [
                'branch' => $payment->branch,
                'branch_name' => $branchNames[$payment->branch] ?? 'Unknown',
                'payment_date' => $payment->payment_due_date,
                'total_target' => (float) $payment->total_target,
                'total_paid' => (float) $payment->total_paid,
            ];
        })->values()->toArray();
    }

    /**
     * Get unfilled payments (past due with no submission)
     */
    private function getUnfilledPayments(array $userZones): array
    {
        if (empty($userZones)) {
            return [];
        }

        $today = Carbon::today()->toDateString();

        $unfilled = LoanPaymentSchedules::whereIn('zone', $userZones)
            ->where('payment_due_date', '<', $today)
            ->where('is_submitted', false)
            ->where('status', 1)
            ->whereHas('loan', function ($query) {
                $query->whereIn('status', [5, 12]);
            })
            ->select(
                'zone',
                'payment_due_date',
                DB::raw('SUM(payment_total_amount) as total_target')
            )
            ->groupBy('zone', 'payment_due_date')
            ->get();

        if ($unfilled->isEmpty()) {
            return [];
        }

        $zoneIds = $unfilled->pluck('zone')->unique();
        $zoneNames = Zone::whereIn('id', $zoneIds)->pluck('zone_name', 'id');

        return $unfilled->map(function ($item) use ($zoneNames) {
            return [
                'zone' => $item->zone,
                'zone_name' => $zoneNames[$item->zone] ?? 'Unknown',
                'payment_date' => $item->payment_due_date,
                'total_target' => (float) $item->total_target,
                'total_paid' => 0,
            ];
        })->values()->toArray();
    }

    /**
     * Get rejected payments
     */
    private function getRejectedPayments(array $userZones): array
    {
        if (empty($userZones)) {
            return [];
        }

        $today = Carbon::today()->toDateString();

        $rejected = LoanPaymentSchedules::whereIn('loan_payment_schedule.zone', $userZones)
            ->where('loan_payment_schedule.payment_due_date', '<=', $today)
            ->where('loan_payment_schedule.is_submitted', true)
            ->join('payment_submissions', 'payment_submissions.schedule_id', '=', 'loan_payment_schedule.id')
            ->where('payment_submissions.submission_status', 9)
            ->whereNotExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('payment_submissions as ps')
                    ->whereColumn('ps.schedule_id', 'loan_payment_schedule.id')
                    ->whereIn('ps.submission_status', [4, 8, 11]);
            })
            ->select(
                'loan_payment_schedule.zone',
                'loan_payment_schedule.payment_due_date',
                DB::raw('SUM(loan_payment_schedule.payment_total_amount) as total_target'),
                DB::raw('SUM(payment_submissions.amount) as total_paid')
            )
            ->groupBy('loan_payment_schedule.zone', 'loan_payment_schedule.payment_due_date')
            ->get();

        if ($rejected->isEmpty()) {
            return [];
        }

        $zoneIds = $rejected->pluck('zone')->unique();
        $zoneNames = Zone::whereIn('id', $zoneIds)->pluck('zone_name', 'id');

        return $rejected->map(function ($item) use ($zoneNames) {
            return [
                'zone' => $item->zone,
                'zone_name' => $zoneNames[$item->zone] ?? 'Unknown',
                'payment_date' => $item->payment_due_date,
                'total_target' => (float) $item->total_target,
                'total_paid' => (float) $item->total_paid,
            ];
        })->values()->toArray();
    }

    /**
     * Submit payments with batch processing
     */
    public function submitPayment(Request $request, UserLogService $userLogService): JsonResponse
    {
        try {
            $validated = $request->validate([
                'payments' => 'required|array|min:1',
                'payments.*.loan_number' => 'required|string',
                'payments.*.amount' => 'required|numeric|min:0',
                'payments.*.customer' => 'required|integer',
                'payments.*.zone' => 'required|integer',
                'payments.*.branch' => 'required|integer',
                'payments.*.schedule' => 'required|integer',
                'payments.*.account_id' => 'nullable|integer'
            ]);

            $userId = $this->getUserId();
            $userCompany = $this->getCompanyId();
            $currentDate = Carbon::today()->toDateString();

            $userLogService->log('Submit', "Submitting " . count($validated['payments']) . " payments", $userId, $userCompany);

            DB::beginTransaction();

            $bank = new BankController();
            $submittedPayments = [];

            foreach ($validated['payments'] as $payment) {
                // Get schedule data
                $schedule = LoanPaymentSchedules::where([
                    'id' => $payment['schedule'],
                    'loan_number' => $payment['loan_number'],
                    'zone' => $payment['zone']
                ])->first();

                if (!$schedule) {
                    continue;
                }

                // Update existing submissions to rejected
                PaymentSubmissions::where('schedule_id', $schedule->id)
                    ->update(['submission_status' => 9]);

                LoanPayments::where('schedule_id', $schedule->id)
                    ->update(['status' => 9]);

                // Create new payment submission
                $submissionData = [
                    'loan_number' => $payment['loan_number'],
                    'schedule_id' => $schedule->id,
                    'amount' => $payment['amount'],
                    'company' => $userCompany,
                    'branch' => $payment['branch'],
                    'zone' => $payment['zone'],
                    'submitted_date' => $currentDate,
                    'submitted_by' => $userId,
                    'submission_status' => 4, // Pending zone approval
                    'paid_account' => $payment['account_id'] ?? null,
                ];

                PaymentSubmissions::updateOrCreate(
                    [
                        'schedule_id' => $schedule->id,
                        'submission_status' => 4
                    ],
                    $submissionData
                );

                // Register bank transaction if amount > 0
                if ($payment['amount'] > 0 && !empty($payment['account_id'])) {
                    $bank->registerTransaction(
                        $payment['account_id'],
                        $payment['amount'],
                        true,
                        $schedule->payment_due_date,
                        false,
                        $payment['branch'],
                        $payment['zone'],
                        $payment['loan_number'],
                        $payment['customer'],
                        $schedule->id
                    );
                }

                // Create loan payment record
                LoanPayments::create([
                    'schedule_id' => $schedule->id,
                    'customer' => $payment['customer'],
                    'company' => $userCompany,
                    'branch' => $payment['branch'],
                    'zone' => $payment['zone'],
                    'loan_number' => $payment['loan_number'],
                    'payment_date' => $schedule->payment_due_date,
                    'amount_paid' => $payment['amount'],
                    'received_by' => $userId,
                    'officer_status' => 4,
                    'paid_account' => $payment['account_id'] ?? null,
                    'status' => 4,
                ]);

                // Mark schedule as submitted
                $schedule->update(['is_submitted' => true]);

                $submittedPayments[] = $payment['loan_number'];
            }

            DB::commit();

            // Clear cache
            $this->clearPaymentsCache($userCompany);

            return $this->successResponse(
                [
                    'submitted_count' => count($submittedPayments),
                    'submitted_loans' => $submittedPayments
                ],
                count($submittedPayments) . ' payment(s) submitted successfully',
                201
            );
        } catch (ValidationException $e) {
            DB::rollBack();
            return $this->validationErrorResponse($e);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Payment submission error: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);

            return $this->errorResponse('Failed to submit payments: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Approve zone payments
     */
    public function approveZonePayments(Request $request, UserLogService $userLogService): JsonResponse
    {
        try {
            $validated = $request->validate([
                'zone' => 'required|integer',
                'payment_date' => 'required|date',
                'submitted_date' => 'required|date',
            ]);

            $userId = $this->getUserId();
            $userCompany = $this->getCompanyId();

            $userLogService->log('Approve', "Approve zone {$validated['zone']} payments for {$validated['payment_date']}", $userId, $userCompany);

            DB::beginTransaction();

            // Update payment submissions to branch approval status (8)
            $updated = PaymentSubmissions::where('submission_status', 4)
                ->where('zone', $validated['zone'])
                ->where('submitted_date', $validated['submitted_date'])
                ->whereHas('schedule', function ($query) use ($validated) {
                    $query->where('payment_due_date', $validated['payment_date'])
                        ->where('is_submitted', 1)
                        ->where('status', 1);
                })
                ->update(['submission_status' => 8]);

            DB::commit();

            // Clear cache
            $this->clearPaymentsCache($userCompany);

            return $this->successResponse(
                ['updated_count' => $updated],
                "Zone payments approved successfully. {$updated} records updated."
            );
        } catch (ValidationException $e) {
            return $this->validationErrorResponse($e);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Zone approval error: ' . $e->getMessage());

            return $this->errorResponse('Failed to approve zone payments: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Reject zone payments
     */
    public function rejectZonePayments(Request $request, UserLogService $userLogService): JsonResponse
    {
        try {
            $validated = $request->validate([
                'zone' => 'required|integer',
                'payment_date' => 'required|date',
                'submitted_date' => 'required|date',
            ]);

            $userId = $this->getUserId();
            $userCompany = $this->getCompanyId();

            $userLogService->log('Reject', "Reject zone {$validated['zone']} payments for {$validated['payment_date']}", $userId, $userCompany);

            DB::beginTransaction();

            // Get payments to reject for reversal
            $paymentsToReject = PaymentSubmissions::where('submission_status', 4)
                ->where('zone', $validated['zone'])
                ->where('submitted_date', $validated['submitted_date'])
                ->whereHas('schedule', function ($query) use ($validated) {
                    $query->where('payment_due_date', $validated['payment_date']);
                })
                ->get();

            $bank = new BankController();

            foreach ($paymentsToReject as $payment) {
                // Reverse bank transaction
                if ($payment->amount > 0 && $payment->paid_account) {
                    $bank->registerTransaction(
                        $payment->paid_account,
                        $payment->amount,
                        false,
                        $validated['payment_date'],
                        true,
                        $payment->branch,
                        $payment->zone,
                        $payment->loan_number,
                        $payment->customer,
                        $payment->schedule_id
                    );
                }
            }

            // Update to rejected status (9)
            $updated = PaymentSubmissions::where('submission_status', 4)
                ->where('zone', $validated['zone'])
                ->where('submitted_date', $validated['submitted_date'])
                ->whereHas('schedule', function ($query) use ($validated) {
                    $query->where('payment_due_date', $validated['payment_date']);
                })
                ->update(['submission_status' => 9]);

            DB::commit();

            // Clear cache
            $this->clearPaymentsCache($userCompany);

            return $this->successResponse(
                ['updated_count' => $updated],
                "Zone payments rejected successfully. {$updated} records updated."
            );
        } catch (ValidationException $e) {
            return $this->validationErrorResponse($e);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Zone rejection error: ' . $e->getMessage());

            return $this->errorResponse('Failed to reject zone payments: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Approve branch payments (head office)
     */
    public function approveBranchPayments(Request $request, UserLogService $userLogService): JsonResponse
    {
        try {
            $validated = $request->validate([
                'branch' => 'required|integer',
                'payment_date' => 'required|date',
            ]);

            $userId = $this->getUserId();
            $userCompany = $this->getCompanyId();

            $userLogService->log('Approve', "Approve branch {$validated['branch']} payments for {$validated['payment_date']}", $userId, $userCompany);

            DB::beginTransaction();

            // Update to final approval status (11)
            $updated = PaymentSubmissions::where('submission_status', 8)
                ->where('branch', $validated['branch'])
                ->whereHas('schedule', function ($query) use ($validated) {
                    $query->where('payment_due_date', $validated['payment_date']);
                })
                ->update(['submission_status' => 11]);

            // Update loan balances
            $this->updateLoanBalances($validated['branch'], $validated['payment_date']);

            DB::commit();

            // Clear cache
            $this->clearPaymentsCache($userCompany);

            return $this->successResponse(
                ['updated_count' => $updated],
                "Branch payments approved successfully. {$updated} records updated."
            );
        } catch (ValidationException $e) {
            return $this->validationErrorResponse($e);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Branch approval error: ' . $e->getMessage());

            return $this->errorResponse('Failed to approve branch payments: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Reject branch payments
     */
    public function rejectBranchPayments(Request $request, UserLogService $userLogService): JsonResponse
    {
        try {
            $validated = $request->validate([
                'branch' => 'required|integer',
                'payment_date' => 'required|date',
            ]);

            $userId = $this->getUserId();
            $userCompany = $this->getCompanyId();

            $userLogService->log('Reject', "Reject branch {$validated['branch']} payments for {$validated['payment_date']}", $userId, $userCompany);

            DB::beginTransaction();

            // Revert to zone approval status (4)
            $updated = PaymentSubmissions::where('submission_status', 8)
                ->where('branch', $validated['branch'])
                ->whereHas('schedule', function ($query) use ($validated) {
                    $query->where('payment_due_date', $validated['payment_date']);
                })
                ->update(['submission_status' => 4]);

            DB::commit();

            // Clear cache
            $this->clearPaymentsCache($userCompany);

            return $this->successResponse(
                ['updated_count' => $updated],
                "Branch payments rejected successfully. {$updated} records updated."
            );
        } catch (ValidationException $e) {
            return $this->validationErrorResponse($e);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Branch rejection error: ' . $e->getMessage());

            return $this->errorResponse('Failed to reject branch payments: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Update loan balances after approval
     */
    private function updateLoanBalances(int $branchId, string $paymentDate): void
    {
        $approvedPayments = PaymentSubmissions::where('submission_status', 11)
            ->where('branch', $branchId)
            ->whereHas('schedule', function ($query) use ($paymentDate) {
                $query->where('payment_due_date', $paymentDate);
            })
            ->with(['loan'])
            ->get();

        foreach ($approvedPayments as $payment) {
            if ($payment->amount == 0) {
                $this->updateLoanPenalty($payment->schedule_id, $payment->loan_number);
            }

            // Update loan payment record
            LoanPayments::where('loan_number', $payment->loan_number)
                ->where('schedule_id', $payment->schedule_id)
                ->where('branch', $branchId)
                ->where('payment_date', $paymentDate)
                ->update(['status' => 11]);

            // Update loan paid amount
            $loan = Loans::where('loan_number', $payment->loan_number)->first();
            if ($loan) {
                $newPaidAmount = ($loan->loan_paid ?? 0) + $payment->amount;
                $loan->update(['loan_paid' => $newPaidAmount]);

                // Check if loan is completed
                $totalDue = $loan->total_loan + ($loan->penalty_amount ?? 0);
                if ($newPaidAmount >= $totalDue) {
                    $loan->update(['status' => Loans::STATUS_COMPLETED]);
                }
            }
        }
    }

    /**
     * Update loan penalty
     */
    private function updateLoanPenalty(int $scheduleId, string $loanNumber): void
    {
        $schedule = LoanPaymentSchedules::find($scheduleId);
        $loan = Loans::where('loan_number', $loanNumber)->with('loan_product')->first();

        if ($schedule && $loan && $loan->loan_product) {
            $product = $loan->loan_product;
            $penaltyAmount = 0;

            if ($product->penalty_type == 1) {
                $penaltyAmount = $product->fixed_penalty_amount ?? 0;
            } else {
                $penaltyAmount = (($product->penalty_percentage ?? 0) * $loan->principal_amount) / 100;
            }

            $schedule->update(['penalty_amount' => $penaltyAmount]);

            $loan->update([
                'penalty_amount' => ($loan->penalty_amount ?? 0) + $penaltyAmount
            ]);
        }
    }

    /**
     * Clear payments cache
     */
    private function clearPaymentsCache(int $companyId): void
    {
        Cache::forget("payments_dashboard_{$companyId}_*");
    }

    /**
     * List active accounts (for dropdown)
     */
    public function listActiveAccounts(): JsonResponse
    {
        try {
            $userCompany = $this->getCompanyId();

            $accounts = Accounts::where('company', $userCompany)
                ->where('account_status', 1)
                ->select('id', 'account_name', 'account_number', 'bank_name')
                ->orderBy('account_name')
                ->get();

            return $this->successResponse($accounts, 'Accounts retrieved successfully');
        } catch (\Exception $e) {
            Log::error('Failed to load accounts: ' . $e->getMessage());
            return $this->errorResponse('Failed to load accounts', 500);
        }
    }

    /**
     * Get loan schedules (for loan schedule page)
     */
    public function loanSchedules($loanId, UserLogService $userLogService): JsonResponse
    {
        try {
            $userId = $this->getUserId();
            $userCompany = $this->getCompanyId();

            $userLogService->log('Access', "Access loan schedules for loan ID: {$loanId}", $userId, $userCompany);

            $loan = Loans::where('id', $loanId)
                ->where('company', $userCompany)
                ->with(['loan_product', 'loan_customer'])
                ->first();

            if (!$loan) {
                return $this->errorResponse('Loan not found', 404);
            }

            $schedules = LoanPaymentSchedules::where('loan_number', $loan->loan_number)
                ->where('status', 1)
                ->orderBy('payment_due_date')
                ->get();

            $payments = PaymentSubmissions::where('loan_number', $loan->loan_number)
                ->whereIn('submission_status', [11])
                ->get()
                ->keyBy('schedule_id');

            $scheduleData = $schedules->map(function ($schedule) use ($payments) {
                $paidAmount = $payments->get($schedule->id)?->amount ?? 0;
                $targetAmount = (float) $schedule->payment_total_amount;

                return [
                    'schedule_id' => $schedule->id,
                    'overdue_flag' => $schedule->overdue_flag ?? 0,
                    'is_submitted' => $schedule->is_submitted ?? false,
                    'schedule_date' => $schedule->payment_due_date,
                    'target_amount' => number_format($targetAmount, 2),
                    'paid_amount' => number_format($paidAmount, 2),
                    'balance_amount' => number_format($targetAmount - $paidAmount, 2),
                ];
            });

            return $this->successResponse([
                'schedules' => $scheduleData,
                'customer' => $loan->loan_customer,
                'loan' => $loan
            ], 'Loan schedules retrieved successfully');
        } catch (\Exception $e) {
            Log::error('Failed to load loan schedules: ' . $e->getMessage());
            return $this->errorResponse('Failed to load loan schedules', 500);
        }
    }

    /**
     * Delete schedule
     */
    public function deleteSchedule($scheduleId, UserLogService $userLogService): JsonResponse
    {
        try {
            $userId = $this->getUserId();
            $userCompany = $this->getCompanyId();

            $userLogService->log('Delete', "Delete payment schedule: {$scheduleId}", $userId, $userCompany);

            $schedule = LoanPaymentSchedules::where('id', $scheduleId)
                ->where('company', $userCompany)
                ->first();

            if (!$schedule) {
                return $this->errorResponse('Schedule not found', 404);
            }

            $schedule->update(['status' => 3]); // Deleted status

            return $this->successResponse(null, 'Schedule deleted successfully');
        } catch (\Exception $e) {
            Log::error('Failed to delete schedule: ' . $e->getMessage());
            return $this->errorResponse('Failed to delete schedule', 500);
        }
    }

    /**
     * Zone payments view
     */
    public function zonePaymentsView($zone, $date, UserLogService $userLogService): JsonResponse
    {
        try {
            $userId = $this->getUserId();
            $userCompany = $this->getCompanyId();

            $userLogService->log('Access', "Access zone payments view for zone: {$zone} on date: {$date}", $userId, $userCompany);

            $payments = LoanPaymentSchedules::where('zone', $zone)
                ->where('payment_due_date', $date)
                ->where('is_submitted', true)
                ->where('status', 1)
                ->whereHas('loan', function ($query) {
                    $query->whereIn('status', [5, 12]);
                })
                ->join('payment_submissions', 'payment_submissions.schedule_id', '=', 'loan_payment_schedule.id')
                ->whereIn('payment_submissions.submission_status', [4, 8, 11])
                ->leftJoin('accounts', 'accounts.id', '=', 'payment_submissions.paid_account')
                ->select(
                    'loan_payment_schedule.*',
                    'payment_submissions.amount as paid_amount',
                    'payment_submissions.submission_status',
                    'payment_submissions.paid_account',
                    'accounts.account_name'
                )
                ->get();

            // Get customers
            $customerIds = $payments->pluck('customer')->unique();
            $customers = Customers::whereIn('id', $customerIds)->get()->keyBy('id');

            $formattedPayments = $payments->map(function ($payment) use ($customers) {
                $customer = $customers->get($payment->customer);
                return [
                    'schedule' => [
                        'id' => $payment->id,
                        'loan_number' => $payment->loan_number,
                        'payment_total_amount' => (float) $payment->payment_total_amount,
                        'payment_due_date' => $payment->payment_due_date,
                        'branch' => $payment->branch,
                        'zone' => $payment->zone,
                        'customer' => $payment->customer,
                        'overdue_flag' => $payment->overdue_flag ?? 0,
                    ],
                    'customer' => [
                        'id' => $customer->id ?? null,
                        'fullname' => $customer->fullname ?? 'Unknown',
                        'phone' => $customer->phone ?? '—',
                    ],
                    'payment' => [
                        'amount' => (float) ($payment->paid_amount ?? 0),
                        'submission_status' => $payment->submission_status,
                        'paid_account' => $payment->paid_account,
                        'account_name' => $payment->account_name,
                    ]
                ];
            });

            return $this->successResponse($formattedPayments, 'Zone payments retrieved successfully');
        } catch (\Exception $e) {
            Log::error('Failed to load zone payments: ' . $e->getMessage());
            return $this->errorResponse('Failed to load zone payments', 500);
        }
    }

    /**
     * Branch payments view
     */
    public function branchPaymentsView($branch, $date, UserLogService $userLogService): JsonResponse
    {
        try {
            $userId = $this->getUserId();
            $userCompany = $this->getCompanyId();

            $userLogService->log('Access', "Access branch payments view for branch: {$branch} on date: {$date}", $userId, $userCompany);

            $payments = LoanPaymentSchedules::where('branch', $branch)
                ->where('payment_due_date', $date)
                ->where('is_submitted', true)
                ->where('status', 1)
                ->whereHas('loan', function ($query) {
                    $query->whereIn('status', [5, 12]);
                })
                ->join('payment_submissions', 'payment_submissions.schedule_id', '=', 'loan_payment_schedule.id')
                ->whereIn('payment_submissions.submission_status', [8, 11])
                ->leftJoin('accounts', 'accounts.id', '=', 'payment_submissions.paid_account')
                ->select(
                    'loan_payment_schedule.*',
                    'payment_submissions.amount as paid_amount',
                    'payment_submissions.submission_status',
                    'payment_submissions.paid_account',
                    'accounts.account_name'
                )
                ->get();

            // Get customers
            $customerIds = $payments->pluck('customer')->unique();
            $customers = Customers::whereIn('id', $customerIds)->get()->keyBy('id');

            $formattedPayments = $payments->map(function ($payment) use ($customers) {
                $customer = $customers->get($payment->customer);
                return [
                    'schedule' => [
                        'id' => $payment->id,
                        'loan_number' => $payment->loan_number,
                        'payment_total_amount' => (float) $payment->payment_total_amount,
                        'payment_due_date' => $payment->payment_due_date,
                        'branch' => $payment->branch,
                        'zone' => $payment->zone,
                        'customer' => $payment->customer,
                        'overdue_flag' => $payment->overdue_flag ?? 0,
                    ],
                    'customer' => [
                        'id' => $customer->id ?? null,
                        'fullname' => $customer->fullname ?? 'Unknown',
                        'phone' => $customer->phone ?? '—',
                    ],
                    'payment' => [
                        'amount' => (float) ($payment->paid_amount ?? 0),
                        'submission_status' => $payment->submission_status,
                        'paid_account' => $payment->paid_account,
                        'account_name' => $payment->account_name,
                    ]
                ];
            });

            return $this->successResponse($formattedPayments, 'Branch payments retrieved successfully');
        } catch (\Exception $e) {
            Log::error('Failed to load branch payments: ' . $e->getMessage());
            return $this->errorResponse('Failed to load branch payments', 500);
        }
    }

    /**
     * Fetch unfilled payments
     */
    public function fetchUnfilledPayments($zone, $date): JsonResponse
    {
        try {
            $userCompany = $this->getCompanyId();

            $unfilledSchedules = LoanPaymentSchedules::select(
                'loan_payment_schedule.id',
                'loan_payment_schedule.loan_number',
                'loan_payment_schedule.payment_total_amount',
                'loan_payment_schedule.branch',
                'loan_payment_schedule.zone',
                'loan_payment_schedule.overdue_flag',
                'loans.customer as customer_id'
            )
                ->join('loans', 'loans.loan_number', '=', 'loan_payment_schedule.loan_number')
                ->where('loan_payment_schedule.zone', $zone)
                ->where('loan_payment_schedule.payment_due_date', $date)
                ->where('loan_payment_schedule.is_submitted', false)
                ->where('loan_payment_schedule.status', 1)
                ->where('loans.company', $userCompany)
                ->whereIn('loans.status', [5, 12])
                ->get();

            // Get customer details
            $customerIds = $unfilledSchedules->pluck('customer_id')->filter()->unique()->toArray();
            $customers = Customers::whereIn('id', $customerIds)
                ->select('id', 'fullname', 'phone')
                ->get()
                ->keyBy('id');

            // Get accounts - DIRECTLY AS ARRAY, not as JsonResponse
            $accounts = Accounts::where('company', $userCompany)
                ->where('account_status', 1)
                ->select('id', 'account_name')
                ->orderBy('account_name')
                ->get();

            $formattedData = $unfilledSchedules->map(function ($schedule) use ($customers) {
                $customer = $customers->get($schedule->customer_id);

                return [
                    'schedule' => [
                        'id' => $schedule->id,
                        'loan_number' => $schedule->loan_number,
                        'payment_total_amount' => (float) $schedule->payment_total_amount,
                        'branch' => $schedule->branch,
                        'zone' => $schedule->zone,
                        'customer' => $schedule->customer_id,
                        'overdue_flag' => $schedule->overdue_flag ?? 0,
                    ],
                    'customer' => [
                        'id' => $customer->id ?? null,
                        'fullname' => $customer->fullname ?? 'Unknown',
                        'phone' => $customer->phone ?? '—',
                    ]
                ];
            });

            return $this->successResponse([
                'unfilledData' => $formattedData,
                'accounts' => $accounts  // This is already a Collection, not JsonResponse
            ], 'Unfilled payments retrieved successfully');
        } catch (\Exception $e) {
            Log::error('Failed to load unfilled payments: ' . $e->getMessage());
            return $this->errorResponse('Failed to load unfilled payments: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Fetch rejected payments
     */
    public function fetchRejectedPayments($zone, $date): JsonResponse
    {
        try {
            $userCompany = $this->getCompanyId();
            $bank = new BankController();
            $accounts = $bank->listActiveAccounts();

            $rejectedSchedules = LoanPaymentSchedules::where('zone', $zone)
                ->where('payment_due_date', $date)
                ->where('is_submitted', true)
                ->where('status', 1)
                ->whereHas('loan', function ($query) use ($userCompany) {
                    $query->where('company', $userCompany)
                        ->whereIn('status', [5, 12]);
                })
                ->join('payment_submissions', 'payment_submissions.schedule_id', '=', 'loan_payment_schedule.id')
                ->where('payment_submissions.submission_status', 9)
                ->whereNotExists(function ($query) {
                    $query->select(DB::raw(1))
                        ->from('payment_submissions as ps')
                        ->whereColumn('ps.schedule_id', 'loan_payment_schedule.id')
                        ->whereIn('ps.submission_status', [4, 8, 11]);
                })
                ->with(['loan.customerRelation'])
                ->select('loan_payment_schedule.*')
                ->distinct()
                ->get();

            $formattedData = $rejectedSchedules->map(function ($schedule) {
                return [
                    'schedule' => [
                        'id' => $schedule->id,
                        'loan_number' => $schedule->loan_number,
                        'payment_total_amount' => (float) $schedule->payment_total_amount,
                        'branch' => $schedule->branch,
                        'zone' => $schedule->zone,
                        'customer' => $schedule->loan->customer ?? null,
                        'overdue_flag' => $schedule->overdue_flag ?? 0,
                    ],
                    'customer' => [
                        'id' => $schedule->loan->customerRelation->id ?? null,
                        'fullname' => $schedule->loan->customerRelation->fullname ?? 'Unknown',
                        'phone' => $schedule->loan->customerRelation->phone ?? '—',
                    ]
                ];
            });

            return $this->successResponse([
                'rejectedData' => $formattedData,
                'accounts' => $accounts->getData(true)['data'] ?? []
            ], 'Rejected payments retrieved successfully');
        } catch (\Exception $e) {
            Log::error('Failed to load rejected payments: ' . $e->getMessage());
            return $this->errorResponse('Failed to load rejected payments', 500);
        }
    }
}
