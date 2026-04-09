<?php

namespace App\Http\Controllers\Api\V2;

use App\Models\Loans;
use App\Models\LoansProducts;
use App\Models\LoanPaymentSchedules;
use App\Models\PaymentSubmissions;
use App\Models\Customers;
use App\Models\CustomersZone;
use App\Models\Zone;
use App\Models\Branch;
use App\Models\LoanToken;
use App\Services\UserLogService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Carbon\Carbon;
use App\Http\Controllers\Api\V2\BaseController;
use App\Models\Collateral;
use DateTime;
use Illuminate\Support\Facades\Log;

class LoanController extends BaseController
{
    protected $userLogService;

    public function __construct(UserLogService $userLogService)
    {
        $this->userLogService = $userLogService;
    }

    // ====================== LOAN LISTING ======================

    public function index(Request $request)
    {
        return $this->getLoansByStatus($request, null);
    }

    public function active(Request $request)
    {
        return $this->getLoansByStatus($request, Loans::STATUS_ACTIVE);
    }

    public function pending(Request $request)
    {
        return $this->getLoansByStatus($request, Loans::STATUS_SUBMITTED);
    }

    public function completed(Request $request)
    {
        return $this->getLoansByStatus($request, Loans::STATUS_COMPLETED);
    }

    public function overdue(Request $request)
    {
        return $this->getLoansByStatus($request, Loans::STATUS_OVERDUE);
    }

    public function defaulted(Request $request)
    {
        return $this->getLoansByStatus($request, Loans::STATUS_DEFAULTED);
    }

    private function getLoansByStatus(Request $request, $status = null)
    {
        $companyId = $this->getCompanyId();
        $userZones = $this->getUserZones();
        $userBranches = $this->getUserBranches();
        $isManager = $this->hasPermission(21);

        // Eager load relationships through customerZone to get actual customer data
        $query = Loans::with([
            'loan_customer',
            'loan_zone',
            'loan_product'
        ])->where('company', $companyId);

        if (!$isManager) {
            if (!empty($userZones)) {
                $query->whereIn('zone', $userZones);
            } elseif (!empty($userBranches)) {
                $zonesInBranch = Zone::whereIn('branch', $userBranches)->pluck('id');
                $query->whereIn('zone', $zonesInBranch);
            }
        }

        if ($status !== null) {
            $query->where('status', $status);
        }

        if ($request->has('customer_id')) {
            $query->where('customer', $request->customer_id);
        }

        if ($request->has('zone_id')) {
            $query->where('zone', $request->zone_id);
        }

        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('loan_number', 'LIKE', "%{$search}%")
                    ->orWhereHas('customerZone.customer', function ($cq) use ($search) {
                        $cq->where('fullname', 'LIKE', "%{$search}%")
                            ->orWhere('phone', 'LIKE', "%{$search}%")
                            ->orWhere('customer_phone', 'LIKE', "%{$search}%");
                    });
            });
        }

        if ($request->has('date_from')) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }

        if ($request->has('date_to')) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }

        $loans = $query->orderBy('created_at', 'desc')
            ->paginate($request->get('per_page', 15));

        $loans->getCollection()->transform(fn($loan) => $this->transformLoan($loan));

        return $this->successResponse($this->paginateResponse($loans));
    }

    public function stats(Request $request)
    {
        $companyId = $this->getCompanyId();
        $userZones = $this->getUserZones();
        $userBranches = $this->getUserBranches();
        $isManager = $this->hasPermission(21);

        $query = Loans::where('company', $companyId);

        if (!$isManager) {
            if (!empty($userZones)) {
                $query->whereIn('zone', $userZones);
            } elseif (!empty($userBranches)) {
                $zones = Zone::whereIn('branch', $userBranches)->pluck('id');
                $query->whereIn('zone', $zones);
            }
        }

        if ($request->has('date_from')) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }

        if ($request->has('date_to')) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }

        $stats = [
            'total'           => (clone $query)->count(),
            'active'          => (clone $query)->where('status', Loans::STATUS_ACTIVE)->count(),
            'pending'         => (clone $query)->where('status', Loans::STATUS_SUBMITTED)->count(),
            'completed'       => (clone $query)->where('status', Loans::STATUS_COMPLETED)->count(),
            'overdue'         => (clone $query)->where('status', Loans::STATUS_OVERDUE)->count(),
            'defaulted'       => (clone $query)->where('status', Loans::STATUS_DEFAULTED)->count(),
            'total_disbursed' => (clone $query)->whereIn('status', [Loans::STATUS_ACTIVE, Loans::STATUS_COMPLETED, Loans::STATUS_OVERDUE])->sum('principal_amount'),
            'total_repaid'    => (clone $query)->where('status', Loans::STATUS_COMPLETED)->sum('loan_paid'),
            'outstanding'     => (clone $query)->whereIn('status', [Loans::STATUS_ACTIVE, Loans::STATUS_OVERDUE])
                ->get()
                ->sum(fn($loan) => ($loan->total_loan + ($loan->penalty_amount ?? 0)) - ($loan->loan_paid ?? 0)),
        ];

        return $this->successResponse($stats);
    }

    // ====================== SINGLE LOAN ======================

    public function show($loan)
    {
        $companyId = $this->getCompanyId();
        $isManager = $this->hasPermission(21);

        $loanModel = Loans::with([
            'customerZone',
            'customerZone.customer',
            'zone',
            'product',
            'schedules' => fn($q) => $q->where('status', 1)->orderBy('payment_due_date'),
            'payments'  => fn($q) => $q->where('submission_status', 11)->orderBy('created_at', 'desc')
        ])
            ->where('company', $companyId)
            ->where(function ($q) use ($loan) {
                $q->where('id', $loan)->orWhere('loan_number', $loan);
            })
            ->first();

        if (!$loanModel) {
            return $this->errorResponse('Loan not found', 404);
        }

        if (!$isManager) {
            $userZones = $this->getUserZones();
            $userBranches = $this->getUserBranches();

            if (!empty($userZones) && !in_array($loanModel->zone, $userZones)) {
                return $this->errorResponse('Unauthorized access', 403);
            }

            if (!empty($userBranches)) {
                $zone = Zone::find($loanModel->zone);
                if ($zone && !in_array($zone->branch, $userBranches)) {
                    return $this->errorResponse('Unauthorized access', 403);
                }
            }
        }

        return $this->successResponse($this->transformLoan($loanModel, true));
    }

    public function schedule($loan)
    {
        $companyId = $this->getCompanyId();

        $loanModel = Loans::where('company', $companyId)
            ->where(function ($q) use ($loan) {
                $q->where('id', $loan)->orWhere('loan_number', $loan);
            })
            ->first();

        if (!$loanModel) {
            return $this->errorResponse('Loan not found', 404);
        }

        $schedules = LoanPaymentSchedules::where('loan_number', $loanModel->loan_number)
            ->where('status', 1)
            ->orderBy('payment_due_date')
            ->get();

        $payments = PaymentSubmissions::where('loan_number', $loanModel->loan_number)
            ->where('submission_status', 11)
            ->get()
            ->groupBy('schedule_id');

        $scheduleData = $schedules->map(function ($schedule) use ($payments) {
            $paid = $payments->get($schedule->id)?->sum('amount') ?? 0;
            $balance = $schedule->payment_total_amount - $paid;

            return [
                'id'         => $schedule->id,
                'due_date'   => $schedule->payment_due_date,
                'principal'  => $this->parseNumber($schedule->payment_principal_amount),
                'interest'   => $this->parseNumber($schedule->payment_interest_amount),
                'total_due'  => $this->parseNumber($schedule->payment_total_amount),
                'paid'       => $this->parseNumber($paid),
                'balance'    => $this->parseNumber($balance),
                'is_overdue' => Carbon::parse($schedule->payment_due_date)->isPast() && $balance > 0,
                'is_paid'    => $balance <= 0,
            ];
        });

        return $this->successResponse([
            'loan'      => $this->transformLoan($loanModel),
            'schedules' => $scheduleData,
            'summary'   => [
                'total_due'  => $this->parseNumber($schedules->sum('payment_total_amount')),
                'total_paid' => $this->parseNumber($payments->sum('amount')),
                'remaining'  => $this->parseNumber($schedules->sum('payment_total_amount') - $payments->sum('amount')),
            ]
        ]);
    }

    // ====================== CREATE & APPROVAL ======================

    public function store(Request $request)
    {
        try {
            $validated = $request->validate([
                'product_id'       => 'required|exists:loans_products,id',
                'customer_id'      => 'required|exists:customers,id',
                'principal_amount' => 'required|numeric|min:1',
                'loan_period'      => 'required|numeric|min:1',
                'attachment'       => 'required|file|mimes:jpg,png,jpeg,pdf|max:10240',
                'token'            => 'required|digits:6',
            ]);

            $companyId = $this->getCompanyId();
            $userId    = $this->getUserId();

            $tokenRecord = LoanToken::where('loan_token', $validated['token'])
                ->where('status', 1)
                ->where('company', $companyId)
                ->first();

            if (!$tokenRecord) {
                return $this->errorResponse('Invalid or expired token', 422);
            }

            $product = LoansProducts::findOrFail($validated['product_id']);

            if (
                $validated['principal_amount'] < $product->min_loan_amount ||
                $validated['principal_amount'] > $product->max_loan_amount
            ) {
                return $this->errorResponse('Principal amount outside product limits', 422);
            }

            $interestAmount = $this->calculateInterest($validated['principal_amount'], $validated['loan_period'], $product);
            $totalLoan = $validated['principal_amount'] + $interestAmount;

            // Get customer zone assignment - THIS IS THE KEY
            $customerZone = CustomersZone::where('customer_id', $validated['customer_id'])
                ->where('company_id', $companyId)
                ->first();

            if (!$customerZone) {
                return $this->errorResponse('Customer not assigned to any zone', 422);
            }

            $loanNumber = $this->generateLoanNumber($customerZone->zone_id, $validated['customer_id'], $companyId);

            $file = $request->file('attachment');
            $filename = time() . '.' . $file->getClientOriginalExtension();
            $file->move(base_path('../terminal_public/uploads/loans'), $filename);

            // IMPORTANT: Store the customerZone->id in the customer field, not the actual customer id
            $loan = Loans::create([
                'loan_number'       => $loanNumber,
                'product'           => $product->id,
                'customer'          => $customerZone->id,  // This stores customers_zones.id
                'principal_amount'  => $validated['principal_amount'],
                'interest_amount'   => $interestAmount,
                'total_loan'        => $totalLoan,
                'loan_period'       => $validated['loan_period'],
                'loan_period_unit'  => $product->loan_period_unit,
                'zone'              => $customerZone->zone_id,
                'registered_by'     => $userId,
                'company'           => $companyId,
                'status'            => Loans::STATUS_SUBMITTED,
                'loan_file'         => 'uploads/loans/' . $filename,
            ]);

            $tokenRecord->update(['status' => 2]);

            $this->userLogService->log('Register', "Register new loan: {$loanNumber}", $userId, $companyId);

            return $this->successResponse($this->transformLoan($loan), 'Loan request created successfully', 201);
        } catch (ValidationException $e) {
            return $this->validationErrorResponse($e);
        } catch (\Exception $e) {
            return $this->errorResponse('Failed to register loan: ' . $e->getMessage(), 500);
        }
    }

    public function approve(Request $request, $loan)
    {
        if (!$this->hasPermission(21)) {
            return $this->errorResponse('Unauthorized', 403);
        }

        try {
            $validated = $request->validate([
                'start_date' => 'required|date|date_format:Y-m-d',
                'end_date'   => 'required|date|date_format:Y-m-d|after:start_date',
            ]);

            $companyId = $this->getCompanyId();

            $loanModel = Loans::where('loan_number', $loan)
                ->where('company', $companyId)
                ->where('status', Loans::STATUS_SUBMITTED)
                ->first();

            if (!$loanModel) {
                return $this->errorResponse('Loan not found or not in pending status', 404);
            }

            $zone = Zone::findOrFail($loanModel->zone);
            $branch = Branch::findOrFail($zone->branch);

            if ($loanModel->principal_amount > $branch->balance) {
                return $this->errorResponse('Branch has insufficient balance. Please allocate fund', 422);
            }

            $installments = $this->generateInstallmentSchedule($loanModel, $validated['start_date'], $validated['end_date']);

            DB::transaction(function () use ($loanModel, $installments, $validated, $branch) {
                foreach ($installments as $inst) {
                    LoanPaymentSchedules::create([
                        'loan_number'              => $loanModel->loan_number,
                        'payment_due_date'         => $inst['due_date'],
                        'payment_principal_amount' => $inst['principal'],
                        'payment_interest_amount'  => $inst['interest'],
                        'payment_total_amount'     => $inst['total'],
                        'status'                   => 1,
                        'company'                  => $this->getCompanyId(),
                        'branch'                   => $branch->id,
                        'zone'                     => $loanModel->zone,
                    ]);
                }

                $loanModel->update([
                    'start_date' => $validated['start_date'],
                    'end_date'   => $validated['end_date'],
                    'status'     => Loans::STATUS_ACTIVE,
                ]);

                $branch->decrement('balance', $loanModel->principal_amount);
            });

            $this->userLogService->log('Approve', "Approve loan: {$loanModel->loan_number}", $this->getUserId(), $companyId);

            return $this->successResponse($this->transformLoan($loanModel), 'Loan approved successfully');
        } catch (ValidationException $e) {
            return $this->validationErrorResponse($e);
        } catch (\Exception $e) {
            return $this->errorResponse('Failed to approve loan: ' . $e->getMessage(), 500);
        }
    }

    public function reject(Request $request, $loan)
    {
        if (!$this->hasPermission(21)) {
            return $this->errorResponse('Unauthorized', 403);
        }

        try {
            $validated = $request->validate([
                'remarks' => 'required|string|max:255',
            ]);

            $loanModel = Loans::where('loan_number', $loan)
                ->where('company', $this->getCompanyId())
                ->where('status', Loans::STATUS_SUBMITTED)
                ->first();

            if (!$loanModel) {
                return $this->errorResponse('Loan not found or cannot be rejected', 404);
            }

            $loanModel->update([
                'remarks' => $validated['remarks'],
                'status'  => 9, // Rejected status
            ]);

            $this->userLogService->log('Reject', "Reject loan: {$loan}", $this->getUserId(), $this->getCompanyId());

            return $this->successResponse(null, 'Loan rejected successfully');
        } catch (ValidationException $e) {
            return $this->validationErrorResponse($e);
        }
    }

    public function complete(Request $request, $loan)
    {
        if (!$this->hasPermission(21)) {
            return $this->errorResponse('Unauthorized', 403);
        }

        $loanModel = Loans::where('loan_number', $loan)
            ->where('company', $this->getCompanyId())
            ->first();

        if (!$loanModel) {
            return $this->errorResponse('Loan not found', 404);
        }

        if (($loanModel->total_loan + ($loanModel->penalty_amount ?? 0)) > ($loanModel->loan_paid ?? 0)) {
            return $this->errorResponse('Loan is not fully paid', 422);
        }

        $loanModel->update(['status' => Loans::STATUS_COMPLETED]);

        LoanPaymentSchedules::where('loan_number', $loan)
            ->where('status', 1)
            ->update(['status' => 3]);

        $this->userLogService->log('Complete', "Mark loan as complete: {$loan}", $this->getUserId(), $this->getCompanyId());

        return $this->successResponse(null, 'Loan marked as completed');
    }

    public function markDefault(Request $request, $loan)
    {
        if (!$this->hasPermission(21)) {
            return $this->errorResponse('Unauthorized', 403);
        }

        $loanModel = Loans::where('loan_number', $loan)
            ->where('company', $this->getCompanyId())
            ->first();

        if (!$loanModel) {
            return $this->errorResponse('Loan not found', 404);
        }

        $loanModel->update(['status' => Loans::STATUS_DEFAULTED]);

        LoanPaymentSchedules::where('loan_number', $loan)
            ->where('status', 1)
            ->update(['status' => 3]);

        $this->userLogService->log('Default', "Mark loan as default: {$loan}", $this->getUserId(), $this->getCompanyId());

        return $this->successResponse(null, 'Loan marked as defaulted');
    }

    // ====================== PRODUCTS ======================

    public function products(Request $request)
    {
        $companyId = $this->getCompanyId();

        $products = LoansProducts::where('company', $companyId)
            ->where('status', '!=', 3)
            ->when($request->has('active_only'), fn($q) => $q->where('status', 1))
            ->get()
            ->map(function ($product) {
                return [
                    'id'                    => $product->id,
                    'name'                  => $product->product_name,
                    'min_amount'            => $this->parseNumber($product->min_loan_amount),
                    'max_amount'            => $this->parseNumber($product->max_loan_amount),
                    'min_period'            => $product->min_loan_period,
                    'max_period'            => $product->max_loan_period,
                    'period_unit'           => $product->loan_period_unit,
                    'repayment_interval'    => $product->repayment_interval,
                    'repayment_interval_unit' => $product->repayment_interval_unit,
                    'interest_type'         => $product->interest_mode,
                    'interest_amount'       => $this->parseNumber($product->interest_amount),
                    'interest_rate'         => $this->parseNumber($product->interest_rate),
                    'penalty_type'          => $product->penalty_type,
                    'penalty_amount'        => $this->parseNumber($product->fixed_penalty_amount),
                    'penalty_rate'          => $this->parseNumber($product->penalty_percentage),
                    'skip_saturday'         => (bool) $product->skip_sat,
                    'skip_sunday'           => (bool) $product->skip_sun,
                    'is_active'             => $product->status === 1,
                ];
            });

        return $this->successResponse($products);
    }

    // ====================== HELPERS ======================

    protected function transformLoan($loan, $detailed = false)
    {
        // Get the actual customer through the customerZone relationship
        $customer = $loan->loan_customer ? $loan->loan_customer : null;
        $product = $loan->loan_product ? $loan->loan_product : null;
        $zone = $loan->loan_zone ? $loan->loan_zone : null;
        /* Log::info('zone', [$zone]);
        Log::info('customer', [$customer]);
        Log::info('product', [$product]); */

        $data = [
            'id'                => $loan->id,
            'loan_number'       => $loan->loan_number,
            'principal_amount'  => $this->parseNumber($loan->principal_amount),
            'interest_amount'   => $this->parseNumber($loan->interest_amount),
            'total_loan'        => $this->parseNumber($loan->total_loan),
            'loan_paid'         => $this->parseNumber($loan->loan_paid ?? 0),
            'penalty_amount'    => $this->parseNumber($loan->penalty_amount ?? 0),
            'loan_period'       => $loan->loan_period,
            'loan_period_unit'  => $loan->loan_period_unit,
            'start_date'        => $loan->start_date,
            'end_date'          => $loan->end_date,
            'created_at'        => $loan->created_at,
            'status'            => $loan->status,
            'status_label'      => $this->getStatusLabel($loan->status),
            'balance'           => $this->parseNumber(($loan->total_loan + ($loan->penalty_amount ?? 0)) - ($loan->loan_paid ?? 0)),
        ];


        $data['product'] = $loan->loan_product ? [
            'id'   => $loan->loan_product->id,
            'name' => $loan->loan_product->product_name,
        ] : null;

        // Customer data comes from the customerZone relationship
        $data['customer'] = $customer ? [
            'id'       => $customer->id,
            'fullname' => $customer->fullname,
            'phone'    => $customer->customer_phone ?? $customer->phone,
            'email'    => $customer->email,
        ] : null;

        $data['zone'] = $loan->loan_zone ? [
            'id'   => $loan->loan_zone->id,
            'name' => $loan->loan_zone->zone_name,
        ] : null;


        return $data;
    }

    protected function getStatusLabel($status)
    {
        $labels = [
            3  => 'Deleted',
            4  => 'Submitted',
            5  => 'Active',
            6  => 'Completed',
            7  => 'Defaulted',
            9  => 'Rejected',
            12 => 'Overdue',
        ];
        return $labels[$status] ?? 'Unknown';
    }

    protected function calculateInterest($principal, $period, $product)
    {
        if ($product->interest_mode == 1) {
            return $product->interest_amount;
        }

        $threshold = $product->interest_threshold ?: 1;
        $multiplier = ceil($period / $threshold);
        return ($principal * $product->interest_rate / 100) * $multiplier;
    }

    protected function generateLoanNumber($zoneId, $customerId, $companyId)
    {
        $year = Carbon::now()->format('Y');
        $prefix = "LN-{$year}-CO{$companyId}-Z{$zoneId}-CU{$customerId}";

        $lastLoan = Loans::where('loan_number', 'LIKE', "{$prefix}%")
            ->orderBy('id', 'desc')
            ->first();

        if ($lastLoan && preg_match('/-(\d+)$/', $lastLoan->loan_number, $matches)) {
            $newIncrement = str_pad($matches[1] + 1, 3, '0', STR_PAD_LEFT);
        } else {
            $newIncrement = '001';
        }

        return "{$prefix}-{$newIncrement}";
    }

    protected function generateInstallmentSchedule($loan, $startDate, $endDate)
    {
        $start = Carbon::parse($startDate);
        $end = Carbon::parse($endDate);
        $totalDays = $start->diffInDays($end);

        $product = LoansProducts::find($loan->product);
        $interval = $product->repayment_interval;
        $intervalUnit = strtolower($product->repayment_interval_unit ?? 'months');

        if ($intervalUnit === 'days') {
            $installmentCount = ceil($totalDays / $interval);
        } elseif ($intervalUnit === 'weeks') {
            $installmentCount = ceil($totalDays / ($interval * 7));
        } else {
            $installmentCount = $loan->loan_period;
        }

        $installmentAmount = $loan->total_loan / $installmentCount;

        $installments = [];
        $currentDate = $start->copy();
        $interestMode = $product->interest_mode;

        for ($i = 0; $i < $installmentCount; $i++) {
            if ($intervalUnit === 'days') {
                $dueDate = $currentDate->copy()->addDays($interval);
            } elseif ($intervalUnit === 'weeks') {
                $dueDate = $currentDate->copy()->addWeeks($interval);
            } else {
                $dueDate = $currentDate->copy()->addMonths($interval);
            }

            // Skip weekends
            while (($product->skip_sat && $dueDate->isSaturday()) ||
                ($product->skip_sun && $dueDate->isSunday())
            ) {
                $dueDate->addDay();
            }

            if ($interestMode == 1) {
                $interest = $loan->interest_amount / $installmentCount;
                $principal = $installmentAmount - $interest;
            } else {
                $interest = ($installmentAmount * $product->interest_rate) / 100;
                $principal = $installmentAmount - $interest;
            }

            $installments[] = [
                'due_date'  => $dueDate->toDateString(),
                'principal' => round($principal, 2),
                'interest'  => round($interest, 2),
                'total'     => round($installmentAmount, 2),
            ];

            $currentDate = $dueDate->copy();
        }

        return $installments;
    }

    // In your LoanController.php

    public function getApprovalData($loanId)
    {
        $loan = Loans::with(['loan_product', 'loan_customer', 'schedules', 'payments'])
            ->findOrFail($loanId);

        // Get customer details
        $customer = $loan->loan_customer;

        // Get product details
        $product = $loan->loan_product;

        // Get collaterals (assuming you have a collaterals table)
        $collaterals = Collateral::where('customer', $customer->id)
            ->where('status', 1)
            ->get();

        // Get previous loans
        $previousLoans = Loans::where('customer', $customer->id)
            ->where('id', '!=', $loanId)
            ->with(['loan_product'])
            ->get()
            ->map(function ($prevLoan) {
                // Calculate repayment performance
                $totalDue = $prevLoan->total_loan;
                $totalPaid = $prevLoan->loan_paid;
                $repaymentPerformance = $totalDue > 0
                    ? min(100, ($totalPaid / $totalDue) * 100)
                    : 0;

                // Count late payments
                $latePayments = PaymentSubmissions::where('loan_number', $prevLoan->loan_number)
                    ->where('submission_status', 'late')
                    ->count();

                return [
                    'id' => $prevLoan->id,
                    'loan_number' => $prevLoan->loan_number,
                    'principal_amount' => $prevLoan->principal_amount,
                    'total_loan' => $prevLoan->total_loan,
                    'loan_paid' => $prevLoan->loan_paid,
                    'status' => $prevLoan->status,
                    'status_label' => $prevLoan->status_label,
                    'start_date' => $prevLoan->start_date,
                    'end_date' => $prevLoan->end_date,
                    'created_at' => $prevLoan->created_at,
                    'repayment_performance' => $repaymentPerformance,
                    'late_payments' => $latePayments,
                ];
            });

        // Calculate credit score
        $creditScore = $this->calculateCreditScore($customer, $previousLoans);

        return response()->json([
            'success' => true,
            'data' => [
                'application' => $loan,
                'customer' => $customer,
                'product' => $product,
                'collaterals' => $collaterals,
                'previous_loans' => $previousLoans,
                'credit_score' => $creditScore,
            ]
        ]);
    }


    private function calculateCreditScore($customer, $previousLoans)
    {
        $score = 700; // Base score
        $factors = [];

        // Factor 1: Income stability
        if ($customer->income && $customer->income > 500000) {
            $score += 50;
            $factors[] = 'Good income level';
        } elseif ($customer->income && $customer->income > 200000) {
            $score += 25;
            $factors[] = 'Moderate income level';
        } else {
            $score -= 30;
            $factors[] = 'Low income level';
        }

        // Factor 2: Previous loan performance
        $totalLoans = count($previousLoans);
        if ($totalLoans > 0) {
            $avgPerformance = collect($previousLoans)->avg('repayment_performance');
            if ($avgPerformance >= 95) {
                $score += 100;
                $factors[] = 'Excellent repayment history';
            } elseif ($avgPerformance >= 80) {
                $score += 50;
                $factors[] = 'Good repayment history';
            } elseif ($avgPerformance >= 60) {
                $score += 0;
                $factors[] = 'Average repayment history';
            } else {
                $score -= 100;
                $factors[] = 'Poor repayment history';
            }
        } else {
            $factors[] = 'First-time borrower';
        }

        // Factor 3: Defaults
        $defaults = collect($previousLoans)->where('status', Loans::STATUS_DEFAULTED)->count();
        if ($defaults > 0) {
            $score -= ($defaults * 150);
            $factors[] = "Has {$defaults} defaulted loan(s)";
        }

        // Determine rating
        $rating = match (true) {
            $score >= 800 => 'Excellent',
            $score >= 700 => 'Good',
            $score >= 600 => 'Fair',
            $score >= 500 => 'Poor',
            default => 'Very Poor'
        };

        $color = match (true) {
            $score >= 800 => 'success',
            $score >= 700 => 'info',
            $score >= 600 => 'warning',
            default => 'danger'
        };

        return [
            'score' => max(0, min(1000, $score)),
            'rating' => $rating,
            'color' => $color,
            'factors' => $factors,
        ];
    }

    private function generateRepaymentSchedule($loan)
    {
        // Calculate daily payment amount
        $startDate = new DateTime($loan->start_date);
        $endDate = new DateTime($loan->end_date);
        $interval = $startDate->diff($endDate);
        $totalDays = $interval->days;

        // Simple daily equal payment schedule
        $dailyPayment = $loan->total_loan / $totalDays;
        $remaining = $loan->total_loan;

        for ($i = 0; $i < $totalDays; $i++) {
            $dueDate = clone $startDate;
            $dueDate->modify("+{$i} days");

            $payment = ($i === $totalDays - 1) ? $remaining : $dailyPayment;
            $principalPortion = ($payment / $loan->total_loan) * $loan->principal_amount;
            $interestPortion = ($payment / $loan->total_loan) * $loan->interest_amount;

            LoanPaymentSchedules::create([
                'loan_number' => $loan->loan_number,
                'payment_principal_amount' => round($principalPortion, 2),
                'payment_interest_amount' => round($interestPortion, 2),
                'payment_total_amount' => round($payment, 2),
                'payment_due_date' => $dueDate,
                'status' => 'pending',
                'company' => $loan->company,
                'branch' => $loan->branch,
                'zone' => $loan->zone,
                'is_penalty' => false,
                'penalty_amount' => 0,
                'is_submitted' => false,
                'overdue_flag' => false,
            ]);

            $remaining -= $payment;
        }
    }


    /**
     * Calculate loan end date and installment schedule based on start date
     * This endpoint is called when admin changes the start date in approval page
     */
    public function calculateLoanSchedule(Request $request)
    {
        try {
            $validated = $request->validate([
                'start_date' => 'required|date|date_format:Y-m-d',
                'loan_id' => 'required|integer|exists:loans,id'
            ]);

            $user = $this->getUserId();
            $user_company = $this->getCompanyId();
            $loan_id = $request->loan_id;

            // Load loan with relationships for better performance
            $loan = Loans::where('loans.company', $user_company)
                ->where('loans.id', $loan_id)
                ->with(['loan_product'])
                ->first();

            if (!$loan) {
                return response()->json([
                    'success' => false,
                    'message' => 'Loan not found'
                ], 404);
            }

            $product = $loan->loan_product;

            // Parse start date
            $startDate = Carbon::parse($request->start_date)->startOfDay();

            // Get loan parameters with defaults
            $loanPeriod = (int) ($loan->loan_period ?? 0);
            $loanPeriodUnit = strtolower($product->loan_period_unit ?? 'days');
            $totalLoanAmount = (float) ($loan->total_loan ?? 0);

            // Get payment interval (default to daily if not set)
            $paymentInterval = (int) ($product->repayment_interval ?? 1);
            $paymentIntervalUnit = strtolower($product->repayment_interval_unit ?? 'days');

            // Weekend skip settings
            $skipSaturday = (bool) ($product->skip_sat ?? false);
            $skipSunday = (bool) ($product->skip_sun ?? false);

            // Get holidays from database (you can store holidays in a settings table)
            $holidays = $this->getHolidays($user_company);

            // Calculate end of loan period
            $endOfLoanPeriod = $this->addUnit($startDate, $loanPeriodUnit, $loanPeriod);
            $totalDays = $startDate->diffInDays($endOfLoanPeriod);

            // Calculate number of installments
            $totalInstallments = $this->calculateInstallments(
                $startDate,
                $endOfLoanPeriod,
                $totalDays,
                $paymentInterval,
                $paymentIntervalUnit
            );

            if ($totalInstallments <= 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid loan period or payment interval'
                ], 422);
            }

            // Calculate installment amount
            $installmentAmount = round($totalLoanAmount / $totalInstallments, 2);

            // Handle rounding difference (last installment)
            $totalFromInstallments = $installmentAmount * $totalInstallments;
            $difference = round($totalLoanAmount - $totalFromInstallments, 2);

            // Generate installment dates
            $installments = $this->generateInstallments(
                $startDate,
                $paymentInterval,
                $paymentIntervalUnit,
                $totalInstallments,
                $installmentAmount,
                $difference,
                $skipSaturday,
                $skipSunday,
                $holidays
            );

            // Calculate summary statistics
            $summary = $this->calculateSummary($installments, $totalLoanAmount);

            return response()->json([
                'success' => true,
                'data' => [
                    'start_date' => $startDate->toDateString(),
                    'end_date' => $endOfLoanPeriod->toDateString(),
                    'last_installment_date' => !empty($installments) ? end($installments)['due_date'] : null,
                    'total_installments' => $totalInstallments,
                    'installment_amount' => $installmentAmount,
                    'total_loan_amount' => $totalLoanAmount,
                    'installments' => $installments,
                    'summary' => $summary,
                    'loan_details' => [
                        'loan_number' => $loan->loan_number,
                        'name' => $loan->loan_product->product_name ?? 'N/A',
                        'min_amount' => $loan->loan_product->min_loan_amount ?? 0,
                        'max_amount' => $loan->loan_product->max_loan_amount ?? 0,
                        'period' => "{$loanPeriod} {$loanPeriodUnit}",
                        'payment_interval' => "Every {$paymentInterval} {$paymentIntervalUnit}"
                    ]
                ]
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            Log::error('Calculate loan schedule error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to calculate loan schedule: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Add a unit of time to a Carbon date
     */
    private function addUnit(Carbon $date, string $unit, int $value): Carbon
    {
        $result = clone $date;

        switch ($unit) {
            case 'days':
            case 'day':
                $result->addDays($value);
                break;
            case 'weeks':
            case 'week':
                $result->addWeeks($value);
                break;
            case 'months':
            case 'month':
                $result->addMonths($value);
                break;
            case 'years':
            case 'year':
                $result->addYears($value);
                break;
            default:
                $result->addDays($value);
        }

        return $result;
    }

    /**
     * Calculate number of installments
     */
    private function calculateInstallments(
        Carbon $startDate,
        Carbon $endDate,
        int $totalDays,
        int $paymentInterval,
        string $paymentIntervalUnit
    ): int {
        if ($paymentIntervalUnit === 'days') {
            return max(1, ceil($totalDays / max(1, $paymentInterval)));
        }

        if ($paymentIntervalUnit === 'weeks') {
            $totalWeeks = $totalDays / 7;
            return max(1, ceil($totalWeeks / max(1, $paymentInterval)));
        }

        if ($paymentIntervalUnit === 'months') {
            $tempDate = $startDate->copy();
            $installments = 0;
            while ($tempDate->lessThan($endDate)) {
                $tempDate->addMonths($paymentInterval);
                $installments++;
            }
            return max(1, $installments);
        }

        // Default to daily
        return max(1, $totalDays);
    }

    /**
     * Generate installment schedule
     */
    private function generateInstallments(
        Carbon $startDate,
        int $paymentInterval,
        string $paymentIntervalUnit,
        int $totalInstallments,
        float $installmentAmount,
        float $difference,
        bool $skipSaturday,
        bool $skipSunday,
        array $holidays
    ): array {
        $installments = [];
        $currentDate = $startDate->copy();
        $remainingDifference = $difference;

        for ($i = 0; $i < $totalInstallments; $i++) {
            // Move to next payment date
            $dueDate = $this->addUnit($currentDate, $paymentIntervalUnit, $paymentInterval);

            // Skip weekends and holidays
            $dueDate = $this->adjustForWeekendsAndHolidays(
                $dueDate,
                $skipSaturday,
                $skipSunday,
                $holidays
            );

            // Calculate amount (add difference to last installment)
            $amount = $installmentAmount;
            if ($i === $totalInstallments - 1 && $remainingDifference != 0) {
                $amount = round($installmentAmount + $remainingDifference, 2);
            }

            // Calculate principal and interest portions (proportional)
            $principalPortion = round($amount * 0.8, 2); // Example: 80% principal
            $interestPortion = round($amount - $principalPortion, 2);

            $installments[] = [
                'installment_no' => $i + 1,
                'due_date' => $dueDate->toDateString(),
                'due_date_formatted' => $dueDate->format('d M Y'),
                'amount' => $amount,
                'amount_formatted' => number_format($amount, 2),
                'principal' => $principalPortion,
                'interest' => $interestPortion,
                'is_weekend' => $dueDate->isWeekend(),
                'day_name' => $dueDate->format('l')
            ];

            // Set current date for next iteration
            $currentDate = $dueDate->copy();
        }

        return $installments;
    }

    /**
     * Adjust due date to skip weekends and holidays
     */
    private function adjustForWeekendsAndHolidays(
        Carbon $date,
        bool $skipSaturday,
        bool $skipSunday,
        array $holidays
    ): Carbon {
        $adjusted = clone $date;
        $maxAttempts = 30; // Prevent infinite loop
        $attempts = 0;

        while ($attempts < $maxAttempts) {
            $isWeekend = false;

            if ($skipSaturday && $adjusted->isSaturday()) {
                $isWeekend = true;
            }

            if ($skipSunday && $adjusted->isSunday()) {
                $isWeekend = true;
            }

            $isHoliday = in_array($adjusted->toDateString(), $holidays);

            if (!$isWeekend && !$isHoliday) {
                break;
            }

            $adjusted->addDay();
            $attempts++;
        }

        return $adjusted;
    }

    /**
     * Get holidays for the company
     */
    private function getHolidays(int $companyId): array
    {
        // You can fetch from a holidays table
        // For now, return common Tanzanian holidays
        $year = date('Y');
        $nextYear = $year + 1;

        return [
            "{$year}-01-01", // New Year
            "{$year}-01-12", // Zanzibar Revolution Day
            "{$year}-04-07", // Sheikh Abeid Amani Karume Day
            "{$year}-04-26", // Union Day
            "{$year}-05-01", // Worker's Day
            "{$year}-07-07", // Saba Saba Day
            "{$year}-08-08", // Nane Nane Day
            "{$year}-10-14", // Nyerere Day
            "{$year}-12-09", // Independence Day
            "{$year}-12-25", // Christmas Day
            "{$year}-12-26", // Boxing Day
            "{$nextYear}-01-01", // Next year New Year
        ];
    }

    /**
     * Calculate summary statistics
     */
    private function calculateSummary(array $installments, float $totalLoanAmount): array
    {
        $totalAmount = array_sum(array_column($installments, 'amount'));
        $totalPrincipal = array_sum(array_column($installments, 'amount'));
        $totalInterest = array_sum(array_column($installments, 'amount'));

        return [
            'total_to_pay' => round($totalAmount, 2),
            'total_principal' => round($totalPrincipal, 2),
            'total_interest' => round($totalInterest, 2),
            'average_installment' => round($totalAmount / count($installments), 2),
            'interest_rate' => round((($totalAmount - $totalLoanAmount) / $totalLoanAmount) * 100, 2)
        ];
    }


    /**
     * Approve loan and generate repayment schedule
     * This uses the same calculation logic as calculateLoanSchedule
     */
    public function approveLoan(Request $request, UserLogService $userLogService)
    {
        try {
            // Validate request
            $validated = $request->validate([
                'start_date' => 'required|date|date_format:Y-m-d|after_or_equal:today',
                'remarks' => 'nullable|string|max:500'
            ]);

            // Get authenticated user data
            $user_company = $this->getCompanyId();
            $user_id = $this->getUserId();
            $loan_id = $request->route('loanId');

            // Start database transaction
            DB::beginTransaction();

            // Find the loan with relationships
            $loan = Loans::where([
                'loans.id' => $loan_id,
                'loans.company' => $user_company,
                'loans.status' => Loans::STATUS_SUBMITTED // Status 4
            ])
                ->with(['loan_branch', 'loan_product'])
                ->first();

            if (!$loan) {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'message' => 'Loan application not found or already processed'
                ], 404);
            }

            $product = $loan->loan_product;
            $branch = $loan->loan_branch ?? null;

            if (!$branch) {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'message' => 'Branch not found for this loan'
                ], 404);
            }

            Log::info('Branch found: ' . $branch);

            // Check branch balance
            if ($branch->balance < $loan->principal_amount) {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'message' => 'Branch has insufficient balance. Please allocate funds first.',
                    'data' => [
                        'available_balance' => $branch->balance,
                        'required_amount' => $loan->principal_amount,
                        'shortfall' => $loan->principal_amount - $branch->balance
                    ]
                ], 422);
            }

            // Parse start date
            $startDate = Carbon::parse($request->start_date)->startOfDay();

            // Get loan parameters from product
            $loanPeriod = (int) ($loan->loan_period ?? 0);
            $loanPeriodUnit = strtolower($product->loan_period_unit ?? 'days');
            $totalLoanAmount = (float) ($loan->total_loan ?? 0);

            // Get payment interval from product
            $paymentInterval = (int) ($product->repayment_interval ?? 1);
            $paymentIntervalUnit = strtolower($product->repayment_interval_unit ?? 'days');

            // Weekend skip settings
            $skipSaturday = (bool) ($product->skip_sat ?? false);
            $skipSunday = (bool) ($product->skip_sun ?? false);

            // Get holidays
            $holidays = $this->getHolidays($user_company);

            // Calculate end of loan period
            $endOfLoanPeriod = $this->addUnit($startDate, $loanPeriodUnit, $loanPeriod);
            $totalDays = $startDate->diffInDays($endOfLoanPeriod);

            // Calculate number of installments
            $totalInstallments = $this->calculateInstallments(
                $startDate,
                $endOfLoanPeriod,
                $totalDays,
                $paymentInterval,
                $paymentIntervalUnit
            );

            if ($totalInstallments <= 0) {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid loan period or payment interval'
                ], 422);
            }

            // Calculate installment amount
            $installmentAmount = round($totalLoanAmount / $totalInstallments, 2);
            $totalFromInstallments = $installmentAmount * $totalInstallments;
            $difference = round($totalLoanAmount - $totalFromInstallments, 2);

            // Generate and save installments to database
            $savedInstallments = $this->saveInstallments(
                $loan,
                $startDate,
                $paymentInterval,
                $paymentIntervalUnit,
                $totalInstallments,
                $installmentAmount,
                $difference,
                $skipSaturday,
                $skipSunday,
                $holidays,
                $user_company,
                $branch->id,
                $loan->zone
            );

            if (empty($savedInstallments)) {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to generate repayment schedule'
                ], 500);
            }

            // Update loan status
            $loan->start_date = $startDate;
            $loan->end_date = $endOfLoanPeriod;
            $loan->status = Loans::STATUS_ACTIVE; // Status 5
            $loan->remarks = $request->remarks ?? $loan->remarks;
            //$loan->approved_by = $user_id;
            //$loan->approved_at = now();
            $loan->save();

            // Deduct from branch balance
            $branch->balance -= $loan->principal_amount;
            $branch->save();

            // Log the approval
            $userLogService->log(
                'Approve',
                "Approved loan #{$loan->loan_number} with {$totalInstallments} installments. Start date: {$startDate->toDateString()}, End date: {$endOfLoanPeriod->toDateString()}",
                $user_id,
                $user_company
            );

            DB::commit();

            // Calculate summary for response
            $summary = $this->calculateInstallmentSummary($savedInstallments, $totalLoanAmount);

            return response()->json([
                'success' => true,
                'message' => 'Loan approved successfully',
                'data' => [
                    'loan_number' => $loan->loan_number,
                    'start_date' => $startDate->toDateString(),
                    'end_date' => $endOfLoanPeriod->toDateString(),
                    'total_installments' => $totalInstallments,
                    'installment_amount' => $installmentAmount,
                    'total_loan_amount' => $totalLoanAmount,
                    'branch_balance_remaining' => $branch->balance,
                    'summary' => $summary
                ]
            ], 200);
        } catch (ValidationException $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Loan approval error: ' . $e->getMessage(), [
                'loan_id' => $request->route('loanId'),
                'user_id' => $user_id ?? null,
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to approve loan: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Save installments to database
     */
    private function saveInstallments(
        $loan,
        Carbon $startDate,
        int $paymentInterval,
        string $paymentIntervalUnit,
        int $totalInstallments,
        float $installmentAmount,
        float $difference,
        bool $skipSaturday,
        bool $skipSunday,
        array $holidays,
        int $companyId,
        int $branchId,
        int $zoneId
    ): array {
        $installments = [];
        $currentDate = $startDate->copy();
        $remainingDifference = $difference;
        $totalPrincipal = (float) $loan->principal_amount;
        $totalInterest = (float) $loan->interest_amount;
        $totalLoanAmount = (float) $loan->total_loan;
        $interestMode = $product->interest_mode ?? 1; // 1 = fixed, 2 = percentage
        $interestRate = $product->interest_rate ?? 0;

        for ($i = 0; $i < $totalInstallments; $i++) {
            // Move to next payment date
            $dueDate = $this->addUnit($currentDate, $paymentIntervalUnit, $paymentInterval);

            // Skip weekends and holidays
            $dueDate = $this->adjustForWeekendsAndHolidays(
                $dueDate,
                $skipSaturday,
                $skipSunday,
                $holidays
            );

            // Calculate amount (add difference to last installment)
            $amount = $installmentAmount;
            if ($i === $totalInstallments - 1 && $remainingDifference != 0) {
                $amount = round($installmentAmount + $remainingDifference, 2);
            }

            // Calculate principal and interest portions based on interest mode
            list($principalPortion, $interestPortion) = $this->calculatePortions(
                $amount,
                $totalPrincipal,
                $totalInterest,
                $totalLoanAmount,
                $totalInstallments,
                $interestMode,
                $interestRate
            );

            // Create schedule record
            $scheduleData = [
                'loan_number' => $loan->loan_number,
                'payment_principal_amount' => $principalPortion,
                'payment_interest_amount' => $interestPortion,
                'payment_total_amount' => $amount,
                'payment_due_date' => $dueDate->toDateString(),
                'status' => 1, // Pending
                'company' => $companyId,
                'branch' => $branchId,
                'zone' => $zoneId,
                'is_penalty' => false,
                'penalty_amount' => 0,
                'is_submitted' => false,
                'overdue_flag' => false,
                'created_at' => now(),
                'updated_at' => now()
            ];

            // Insert schedule
            $schedule = LoanPaymentSchedules::create($scheduleData);

            $installments[] = array_merge($scheduleData, ['id' => $schedule->id]);

            // Set current date for next iteration
            $currentDate = $dueDate->copy();
        }

        return $installments;
    }

    /**
     * Calculate principal and interest portions for an installment
     */
    private function calculatePortions(
        float $installmentAmount,
        float $totalPrincipal,
        float $totalInterest,
        float $totalLoanAmount,
        int $totalInstallments,
        int $interestMode,
        float $interestRate
    ): array {
        if ($interestMode == 1) {
            // Fixed interest mode - distribute proportionally
            $principalPortion = round(($installmentAmount / $totalLoanAmount) * $totalPrincipal, 2);
            $interestPortion = round($installmentAmount - $principalPortion, 2);
        } else {
            // Percentage interest mode
            $interestPortion = ceil(($installmentAmount * $interestRate) / 100);
            $principalPortion = round($installmentAmount - $interestPortion, 2);
        }

        return [$principalPortion, $interestPortion];
    }

    /**
     * Calculate summary for saved installments
     */
    private function calculateInstallmentSummary(array $installments, float $totalLoanAmount): array
    {
        $totalAmount = array_sum(array_column($installments, 'payment_total_amount'));
        $totalPrincipal = array_sum(array_column($installments, 'payment_principal_amount'));
        $totalInterest = array_sum(array_column($installments, 'payment_interest_amount'));

        return [
            'total_to_pay' => round($totalAmount, 2),
            'total_principal' => round($totalPrincipal, 2),
            'total_interest' => round($totalInterest, 2),
            'average_installment' => round($totalAmount / count($installments), 2),
            'interest_rate' => $totalLoanAmount > 0 ? round(($totalInterest / $totalLoanAmount) * 100, 2) : 0
        ];
    }

    /**
     * Reject loan application
     */
    public function rejectLoan(Request $request, UserLogService $userLogService)
    {
        try {
            // Validate request
            $validated = $request->validate([
                'remarks' => 'required|string|max:500'
            ]);

            // Get authenticated user data

            $user_company = $this->getCompanyId();
            $user_id = $this->getUserId();
            $loan_id = $request->route('loanId');

            // Start transaction
            DB::beginTransaction();

            // Find and update the loan
            $loan = Loans::where([
                'id' => $loan_id,
                'company' => $user_company,
                'status' => Loans::STATUS_SUBMITTED // Status 4
            ])->first();

            if (!$loan) {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'message' => 'Loan application not found or already processed'
                ], 404);
            }

            // Update loan with rejection details
            $loan->remarks = $request->remarks;
            $loan->status = 9; // Rejected status
            $loan->rejected_by = $user_id;
            $loan->rejected_at = now();
            $loan->save();

            // Log the rejection
            $userLogService->log(
                'Reject',
                "Rejected loan #{$loan->loan_number}. Reason: {$request->remarks}",
                $user_id,
                $user_company
            );

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Loan rejected successfully',
                'data' => [
                    'loan_number' => $loan->loan_number,
                    'status' => 'Rejected',
                    'remarks' => $request->remarks,
                    'rejected_at' => now()->toDateTimeString()
                ]
            ], 200);
        } catch (ValidationException $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Loan rejection error: ' . $e->getMessage(), [
                'loan_id' => $request->route('loanId'),
                'user_id' => $user_id ?? null,
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to reject loan: ' . $e->getMessage()
            ], 500);
        }
    }
}
