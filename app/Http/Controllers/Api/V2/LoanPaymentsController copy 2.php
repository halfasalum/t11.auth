<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\BankController;
use App\Models\Accounts;
use App\Models\Branch;
use App\Models\Customers;
use App\Models\LoanPayments;
use App\Models\LoanPaymentSchedules;
use App\Models\Loans;
use App\Models\PaymentSubmissions;
use App\Models\Zone;
use App\Services\UserLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class LoanPaymentsControllerog extends BaseController
{
    /**
     * Get today's payments (for Today's Payments tab)
     */
    public function getTodayPaymentsData(UserLogService $userLogService): JsonResponse
    {
        try {
            $userId = $this->getUserId();
            $userCompany = $this->getCompanyId();
            $userZones = $this->getUserZones();

            $userLogService->log('Access', "Access today's payments", $userId, $userCompany);

            $today = Carbon::today()->toDateString();

            // Get all schedules in one optimized query
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
                return $this->successResponse([
                    'payments' => [],
                    'accounts' => $this->getAccounts(),
                    'total_target' => 0
                ], 'No payments due today');
            }

            // Get customer details
            $customerIds = $schedules->pluck('customer_id')->filter()->unique()->toArray();
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

            $totalTarget = $unpaidSchedules->sum('payment_total_amount');

            $formattedPayments = $unpaidSchedules->map(function ($schedule) use ($customers) {
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
            })->values();

            return $this->successResponse([
                'payments' => $formattedPayments,
                'accounts' => $this->getAccounts(),
                'total_target' => $totalTarget
            ], 'Today\'s payments retrieved successfully');
        } catch (\Exception $e) {
            Log::error('Failed to load today\'s payments: ' . $e->getMessage());
            return $this->errorResponse('Failed to load today\'s payments: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Get zone approval payments (for Zone Approval tab)
     */
    public function getZoneApprovalData(UserLogService $userLogService): JsonResponse
    {
        try {
            $userId = $this->getUserId();
            $userBranches = $this->getUserBranches();

            $userLogService->log('Access', "Access zone approval payments", $userId, $this->getCompanyId());

            if (empty($userBranches)) {
                return $this->successResponse([], 'No zone approvals found');
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
                return $this->successResponse([], 'No zone approvals found');
            }

            // Get zone names
            $zoneIds = $pendingPayments->pluck('zone')->unique();
            $zoneNames = Zone::whereIn('id', $zoneIds)->pluck('zone_name', 'id');

            $formattedData = $pendingPayments->map(function ($payment) use ($zoneNames) {
                return [
                    'zone' => $payment->zone,
                    'zone_name' => $zoneNames[$payment->zone] ?? 'Unknown',
                    'payment_date' => $payment->payment_due_date,
                    'submitted_date' => $payment->submitted_date,
                    'total_target' => (float) $payment->total_target,
                    'total_paid' => (float) $payment->total_paid,
                ];
            })->values();

            return $this->successResponse($formattedData, 'Zone approvals retrieved successfully');
        } catch (\Exception $e) {
            Log::error('Failed to load zone approvals: ' . $e->getMessage());
            return $this->errorResponse('Failed to load zone approvals: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Get branch approval payments (for Branch Approval tab)
     */
    public function getBranchApprovalData(UserLogService $userLogService): JsonResponse
    {
        try {
            $userId = $this->getUserId();
            $userCompany = $this->getCompanyId();

            $userLogService->log('Access', "Access branch approval payments", $userId, $userCompany);

            $pendingPayments = PaymentSubmissions::where('submission_status', 8)
                ->where('payment_submissions.company', $userCompany)
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
                return $this->successResponse([], 'No branch approvals found');
            }

            // Get branch names
            $branchIds = $pendingPayments->pluck('branch')->unique();
            $branchNames = Branch::whereIn('id', $branchIds)->pluck('branch_name', 'id');

            $formattedData = $pendingPayments->map(function ($payment) use ($branchNames) {
                return [
                    'branch' => $payment->branch,
                    'branch_name' => $branchNames[$payment->branch] ?? 'Unknown',
                    'payment_date' => $payment->payment_due_date,
                    'total_target' => (float) $payment->total_target,
                    'total_paid' => (float) $payment->total_paid,
                ];
            })->values();

            return $this->successResponse($formattedData, 'Branch approvals retrieved successfully');
        } catch (\Exception $e) {
            Log::error('Failed to load branch approvals: ' . $e->getMessage());
            return $this->errorResponse('Failed to load branch approvals: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Get previous approvals (for Previous Approvals tab)
     */
    public function getPreviousApprovalsData(UserLogService $userLogService): JsonResponse
    {
        try {
            $userId = $this->getUserId();
            $userCompany = $this->getCompanyId();

            $userLogService->log('Access', "Access previous approvals", $userId, $userCompany);

            $approvedPayments = PaymentSubmissions::where('submission_status', 11)
                ->where('payment_submissions.company', $userCompany)
                ->join('loan_payment_schedule', 'loan_payment_schedule.id', '=', 'payment_submissions.schedule_id')
                ->select(
                    'payment_submissions.branch',
                    'loan_payment_schedule.payment_due_date',
                    DB::raw('SUM(payment_submissions.amount) as total_paid'),
                    DB::raw('SUM(loan_payment_schedule.payment_total_amount) as total_target')
                )
                ->groupBy('payment_submissions.branch', 'loan_payment_schedule.payment_due_date')
                ->orderBy('loan_payment_schedule.payment_due_date', 'desc')
                ->limit(100)
                ->get();

            if ($approvedPayments->isEmpty()) {
                return $this->successResponse([], 'No previous approvals found');
            }

            // Get branch names
            $branchIds = $approvedPayments->pluck('branch')->unique();
            $branchNames = Branch::whereIn('id', $branchIds)->pluck('branch_name', 'id');

            $formattedData = $approvedPayments->map(function ($payment) use ($branchNames) {
                return [
                    'branch' => $payment->branch,
                    'branch_name' => $branchNames[$payment->branch] ?? 'Unknown',
                    'payment_date' => $payment->payment_due_date,
                    'total_target' => (float) $payment->total_target,
                    'total_paid' => (float) $payment->total_paid,
                ];
            })->values();

            return $this->successResponse($formattedData, 'Previous approvals retrieved successfully');
        } catch (\Exception $e) {
            Log::error('Failed to load previous approvals: ' . $e->getMessage());
            return $this->errorResponse('Failed to load previous approvals: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Get unfilled payments (for Unfilled Payments tab)
     */
    public function getUnfilledPaymentsData(UserLogService $userLogService): JsonResponse
    {
        try {
            $userId = $this->getUserId();
            $userZones = $this->getUserZones();

            $userLogService->log('Access', "Access unfilled payments", $userId, $this->getCompanyId());

            if (empty($userZones)) {
                return $this->successResponse([], 'No unfilled payments found');
            }

            $today = Carbon::today()->toDateString();

            $unfilled = LoanPaymentSchedules::select(
                'loan_payment_schedule.zone',
                'loan_payment_schedule.payment_due_date',
                DB::raw('SUM(loan_payment_schedule.payment_total_amount) as total_target')
            )
                ->join('loans', 'loans.loan_number', '=', 'loan_payment_schedule.loan_number')
                ->whereIn('loan_payment_schedule.zone', $userZones)
                ->where('loan_payment_schedule.payment_due_date', '<', $today)
                ->where('loan_payment_schedule.is_submitted', false)
                ->where('loan_payment_schedule.status', 1)
                ->whereIn('loans.status', [5, 12])
                ->groupBy('loan_payment_schedule.zone', 'loan_payment_schedule.payment_due_date')
                ->get();

            if ($unfilled->isEmpty()) {
                return $this->successResponse([], 'No unfilled payments found');
            }

            $zoneIds = $unfilled->pluck('zone')->unique();
            $zoneNames = Zone::whereIn('id', $zoneIds)->pluck('zone_name', 'id');

            $formattedData = $unfilled->map(function ($item) use ($zoneNames) {
                return [
                    'zone' => $item->zone,
                    'zone_name' => $zoneNames[$item->zone] ?? 'Unknown',
                    'payment_date' => $item->payment_due_date,
                    'total_target' => (float) $item->total_target,
                    'total_paid' => 0,
                ];
            })->values();

            return $this->successResponse($formattedData, 'Unfilled payments retrieved successfully');
        } catch (\Exception $e) {
            Log::error('Failed to load unfilled payments: ' . $e->getMessage());
            return $this->errorResponse('Failed to load unfilled payments: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Get rejected payments (for Rejected Payments tab)
     */
    public function getRejectedPaymentsData(UserLogService $userLogService): JsonResponse
    {
        try {
            $userId = $this->getUserId();
            $userZones = $this->getUserZones();

            $userLogService->log('Access', "Access rejected payments", $userId, $this->getCompanyId());

            if (empty($userZones)) {
                return $this->successResponse([], 'No rejected payments found');
            }

            $today = Carbon::today()->toDateString();

            $rejected = LoanPaymentSchedules::select(
                'loan_payment_schedule.zone',
                'loan_payment_schedule.payment_due_date',
                DB::raw('SUM(loan_payment_schedule.payment_total_amount) as total_target'),
                DB::raw('SUM(payment_submissions.amount) as total_paid')
            )
                ->join('loans', 'loans.loan_number', '=', 'loan_payment_schedule.loan_number')
                ->join('payment_submissions', 'payment_submissions.schedule_id', '=', 'loan_payment_schedule.id')
                ->whereIn('loan_payment_schedule.zone', $userZones)
                ->where('loan_payment_schedule.payment_due_date', '<=', $today)
                ->where('loan_payment_schedule.is_submitted', true)
                ->where('payment_submissions.submission_status', 9)
                ->whereIn('loans.status', [5, 12])
                ->whereNotExists(function ($query) {
                    $query->select(DB::raw(1))
                        ->from('payment_submissions as ps')
                        ->whereColumn('ps.schedule_id', 'loan_payment_schedule.id')
                        ->whereIn('ps.submission_status', [4, 8, 11]);
                })
                ->groupBy('loan_payment_schedule.zone', 'loan_payment_schedule.payment_due_date')
                ->get();

            if ($rejected->isEmpty()) {
                return $this->successResponse([], 'No rejected payments found');
            }

            $zoneIds = $rejected->pluck('zone')->unique();
            $zoneNames = Zone::whereIn('id', $zoneIds)->pluck('zone_name', 'id');

            $formattedData = $rejected->map(function ($item) use ($zoneNames) {
                return [
                    'zone' => $item->zone,
                    'zone_name' => $zoneNames[$item->zone] ?? 'Unknown',
                    'payment_date' => $item->payment_due_date,
                    'total_target' => (float) $item->total_target,
                    'total_paid' => (float) $item->total_paid,
                ];
            })->values();

            return $this->successResponse($formattedData, 'Rejected payments retrieved successfully');
        } catch (\Exception $e) {
            Log::error('Failed to load rejected payments: ' . $e->getMessage());
            return $this->errorResponse('Failed to load rejected payments: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Get accounts list (shared across tabs)
     */
    private function getAccounts(): array
    {
        $userCompany = $this->getCompanyId();

        return Accounts::where('company_id', $userCompany)
            ->where('account_status', 1)
            ->select('id', 'account_name')
            ->orderBy('account_name')
            ->get()
            ->toArray();
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
     * Fetch rejected payments
     */
    public function fetchRejectedPayments($zone, $date): JsonResponse
    {
        try {
            $userCompany = $this->getCompanyId();

            // Query accounts directly instead of calling BankController
            $accounts = Accounts::where('company', $userCompany)
                ->where('account_status', 1)
                ->select('id', 'account_name')
                ->orderBy('account_name')
                ->get();

            $rejectedSchedules = LoanPaymentSchedules::select(
                'loan_payment_schedule.id',
                'loan_payment_schedule.loan_number',
                'loan_payment_schedule.payment_total_amount',
                'loan_payment_schedule.branch',
                'loan_payment_schedule.zone',
                'loan_payment_schedule.overdue_flag',
                'loans.customer as customer_id'
            )
                ->join('loans', 'loans.loan_number', '=', 'loan_payment_schedule.loan_number')
                ->join('payment_submissions', 'payment_submissions.schedule_id', '=', 'loan_payment_schedule.id')
                ->where('loan_payment_schedule.zone', $zone)
                ->where('loan_payment_schedule.payment_due_date', $date)
                ->where('loan_payment_schedule.is_submitted', true)
                ->where('loan_payment_schedule.status', 1)
                ->where('payment_submissions.submission_status', 9)
                ->where('loans.company', $userCompany)
                ->whereIn('loans.status', [5, 12])
                ->whereNotExists(function ($query) {
                    $query->select(DB::raw(1))
                        ->from('payment_submissions as ps')
                        ->whereColumn('ps.schedule_id', 'loan_payment_schedule.id')
                        ->whereIn('ps.submission_status', [4, 8, 11]);
                })
                ->distinct()
                ->get();

            // Get customer details
            $customerIds = $rejectedSchedules->pluck('customer_id')->filter()->unique()->toArray();
            $customers = Customers::whereIn('id', $customerIds)
                ->select('id', 'fullname', 'phone')
                ->get()
                ->keyBy('id');

            $formattedData = $rejectedSchedules->map(function ($schedule) use ($customers) {
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
                'rejectedData' => $formattedData,
                'accounts' => $accounts
            ], 'Rejected payments retrieved successfully');
        } catch (\Exception $e) {
            Log::error('Failed to load rejected payments: ' . $e->getMessage());
            return $this->errorResponse('Failed to load rejected payments: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Fetch unfilled payments
     */
    public function fetchUnfilledPayments($zone, $date): JsonResponse
    {
        try {
            $userCompany = $this->getCompanyId();

            // Query accounts directly instead of calling BankController
            $accounts = Accounts::where('company', $userCompany)
                ->where('account_status', 1)
                ->select('id', 'account_name')
                ->orderBy('account_name')
                ->get();

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
                'accounts' => $accounts
            ], 'Unfilled payments retrieved successfully');
        } catch (\Exception $e) {
            Log::error('Failed to load unfilled payments: ' . $e->getMessage());
            return $this->errorResponse('Failed to load unfilled payments: ' . $e->getMessage(), 500);
        }
    }
}
