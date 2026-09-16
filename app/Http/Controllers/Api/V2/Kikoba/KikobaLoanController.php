<?php

namespace App\Http\Controllers\Api\V2\Kikoba;

use App\Http\Controllers\Api\V2\BaseController;
use App\Models\KikobaGroup;
use App\Models\KikobaGroupMember;
use App\Models\KikobaLoan;
use App\Models\KikobaLoanProduct;
use App\Models\KikobaLoanSchedule;
use App\Services\Kikoba\KikobaLoanScheduleService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Phase 2 of Kikoba Loans: applying for a loan against a member's paid share
 * value in a specific group, plus the single-step approve/reject decision
 * that (on approval) generates the repayment schedule immediately. No
 * disbursement/fund-ledger accounting yet — that's a later phase.
 */
class KikobaLoanController extends BaseController
{
    public function index(Request $request)
    {
        $query = KikobaLoan::where('company_id', $this->getCompanyId())
            ->with(['group', 'groupMember.member', 'loanProduct', 'schedules', 'applicant:id,name,first_name,last_name']);

        if ($request->filled('kikoba_group_id')) {
            $query->where('kikoba_group_id', $request->integer('kikoba_group_id'));
        }

        if ($request->filled('kikoba_group_member_id')) {
            $query->where('kikoba_group_member_id', $request->integer('kikoba_group_member_id'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        $loans = $query->orderByDesc('id')->paginate((int) $request->input('per_page', 20));

        return $this->successResponse($this->paginateResponse($loans));
    }

    /**
     * Preview eligibility for a group member against a loan product before
     * they commit to an application — the member's paid share value in that
     * group, and what each of the product's allowed multipliers works out to.
     */
    public function eligibility(Request $request)
    {
        try {
            $data = $request->validate([
                'kikoba_group_id' => 'required|integer|exists:kikoba_groups,id',
                'kikoba_group_member_id' => 'required|integer|exists:kikoba_group_members,id',
                'kikoba_loan_product_id' => 'required|integer|exists:kikoba_loan_products,id',
            ]);
        } catch (ValidationException $e) {
            return $this->validationErrorResponse($e);
        }

        $group = $this->findGroup($data['kikoba_group_id']);
        if (! $group) {
            return $this->errorResponse('Group not found', 404);
        }

        $groupMember = KikobaGroupMember::where('kikoba_group_id', $group->id)
            ->find($data['kikoba_group_member_id']);
        if (! $groupMember) {
            return $this->errorResponse('Member not found in this group', 404);
        }

        $product = KikobaLoanProduct::where('company_id', $this->getCompanyId())
            ->find($data['kikoba_loan_product_id']);
        if (! $product) {
            return $this->errorResponse('Loan product not found', 404);
        }

        if ($product->status !== 'active') {
            return $this->errorResponse('This loan product is not active', 422);
        }

        $shareValue = $this->shareValueFor($groupMember->id);

        $options = collect($product->share_multipliers ?? [])
            ->map(function ($multiplier) use ($shareValue, $product) {
                $eligible = $product->eligibleAmountFor($shareValue, (float) $multiplier);

                return [
                    'multiplier' => (float) $multiplier,
                    'eligible_amount' => $eligible,
                    'meets_minimum' => $eligible >= (float) $product->min_loan_amount,
                ];
            })
            ->values();

        return $this->successResponse([
            'share_value' => round($shareValue, 2),
            'options' => $options,
            'min_loan_amount' => (float) $product->min_loan_amount,
            'max_loan_amount' => $product->max_loan_amount !== null ? (float) $product->max_loan_amount : null,
            'min_loan_period' => $product->min_loan_period,
            'max_loan_period' => $product->max_loan_period,
            'loan_period_unit' => $product->loan_period_unit,
        ]);
    }

    public function store(Request $request)
    {
        try {
            $data = $request->validate([
                'kikoba_group_id' => 'required|integer|exists:kikoba_groups,id',
                'kikoba_group_member_id' => 'required|integer|exists:kikoba_group_members,id',
                'kikoba_loan_product_id' => 'required|integer|exists:kikoba_loan_products,id',
                'multiplier' => 'required|numeric|min:0.01',
                // Optional: request less than the full eligible ceiling. Omit to
                // borrow the full amount that multiplier works out to.
                'requested_amount' => 'nullable|numeric|min:0.01',
                'loan_period' => 'required|integer|min:1',
                'purpose' => 'nullable|string',
                'document' => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:10240',
            ]);
        } catch (ValidationException $e) {
            return $this->validationErrorResponse($e);
        }

        $companyId = $this->getCompanyId();

        $group = $this->findGroup($data['kikoba_group_id']);
        if (! $group) {
            return $this->errorResponse('Group not found', 404);
        }

        $groupMember = KikobaGroupMember::where('kikoba_group_id', $group->id)
            ->where('status', 'active')
            ->find($data['kikoba_group_member_id']);
        if (! $groupMember) {
            return $this->errorResponse('Member not found or not active in this group', 404);
        }

        $product = KikobaLoanProduct::where('company_id', $companyId)->find($data['kikoba_loan_product_id']);
        if (! $product) {
            return $this->errorResponse('Loan product not found', 404);
        }
        if ($product->status !== 'active') {
            return $this->errorResponse('This loan product is not active', 422);
        }

        $multiplier = round((float) $data['multiplier'], 2);
        $allowedMultipliers = collect($product->share_multipliers ?? [])->map(fn ($m) => round((float) $m, 2));
        if (! $allowedMultipliers->contains($multiplier)) {
            return $this->errorResponse(
                'That multiplier is not offered by this loan product. Allowed: ' . $product->share_multipliers_label,
                422
            );
        }

        if ($data['loan_period'] < $product->min_loan_period || $data['loan_period'] > $product->max_loan_period) {
            return $this->errorResponse(
                "Loan period must be between {$product->min_loan_period} and {$product->max_loan_period} {$product->loan_period_unit}",
                422
            );
        }

        // A member with an application already awaiting review for this
        // product shouldn't be able to stack a second one on top of it.
        $hasPending = KikobaLoan::where('kikoba_group_member_id', $groupMember->id)
            ->where('kikoba_loan_product_id', $product->id)
            ->where('status', 'pending')
            ->exists();
        if ($hasPending) {
            return $this->errorResponse('This member already has a pending application for this loan product', 422);
        }

        $shareValue = $this->shareValueFor($groupMember->id);
        $eligibleAmount = $product->eligibleAmountFor($shareValue, $multiplier);

        if ($eligibleAmount < (float) $product->min_loan_amount) {
            return $this->errorResponse(
                'This member\'s share value at ' . $multiplier . 'x (' . number_format($eligibleAmount, 2) .
                    ') is below this product\'s minimum loan amount (' . number_format((float) $product->min_loan_amount, 2) . ')',
                422
            );
        }

        // The applicant may ask for less than the full eligible ceiling, but
        // never more — that ceiling is the hard threshold for this multiplier.
        $requestedAmount = isset($data['requested_amount'])
            ? round((float) $data['requested_amount'], 2)
            : $eligibleAmount;

        if ($requestedAmount > $eligibleAmount) {
            return $this->errorResponse(
                'Requested amount (' . number_format($requestedAmount, 2) .
                    ') cannot exceed the eligible amount for this multiplier (' . number_format($eligibleAmount, 2) . ')',
                422
            );
        }

        if ($requestedAmount < (float) $product->min_loan_amount) {
            return $this->errorResponse(
                'Requested amount (' . number_format($requestedAmount, 2) .
                    ') is below this product\'s minimum loan amount (' . number_format((float) $product->min_loan_amount, 2) . ')',
                422
            );
        }

        $documentPath = null;
        if ($request->hasFile('document')) {
            $file = $request->file('document');
            $filename = 'KLD-' . now()->format('ymdHis') . '-' . Str::upper(Str::random(6)) . '.' . $file->getClientOriginalExtension();
            $file->storeAs('kikoba-loans', $filename, 'public');
            $documentPath = 'storage/kikoba-loans/' . $filename;
        }

        $loan = KikobaLoan::create([
            'company_id' => $companyId,
            'kikoba_group_id' => $group->id,
            'kikoba_group_member_id' => $groupMember->id,
            'kikoba_loan_product_id' => $product->id,
            'loan_number' => $this->generateLoanNumber(),
            'share_value_at_application' => $shareValue,
            'multiplier' => $multiplier,
            'eligible_amount' => $eligibleAmount,
            'requested_amount' => $requestedAmount,
            'loan_period' => $data['loan_period'],
            'purpose' => $data['purpose'] ?? null,
            'document_path' => $documentPath,
            'status' => 'pending',
            'applied_by' => $this->getUserId(),
        ]);

        return $this->successResponse(
            $loan->load(['group', 'groupMember.member', 'loanProduct', 'applicant:id,name,first_name,last_name']),
            'Loan application submitted successfully',
            201
        );
    }

    public function show(int $id)
    {
        $loan = KikobaLoan::where('company_id', $this->getCompanyId())
            ->with([
                'group', 'groupMember.member', 'loanProduct', 'schedules',
                'applicant:id,name,first_name,last_name',
                'approver:id,name,first_name,last_name',
                'rejecter:id,name,first_name,last_name',
            ])
            ->find($id);

        if (! $loan) {
            return $this->errorResponse('Loan application not found', 404);
        }

        return $this->successResponse($loan);
    }

    public function schedule(int $id)
    {
        $loan = KikobaLoan::where('company_id', $this->getCompanyId())->find($id);

        if (! $loan) {
            return $this->errorResponse('Loan application not found', 404);
        }

        return $this->successResponse($loan->schedules()->get());
    }

    public function cancel(int $id)
    {
        $loan = KikobaLoan::where('company_id', $this->getCompanyId())->find($id);

        if (! $loan) {
            return $this->errorResponse('Loan application not found', 404);
        }

        if ($loan->status !== 'pending') {
            return $this->errorResponse('Only a pending application can be cancelled', 422);
        }

        $loan->update(['status' => 'cancelled']);

        return $this->successResponse($loan, 'Loan application cancelled');
    }

    /**
     * Recompute the schedule a pending application WOULD get, for a given
     * amount/start date, without saving anything — lets the approver preview
     * it while still choosing those values.
     */
    public function previewSchedule(Request $request, int $id, KikobaLoanScheduleService $scheduleService)
    {
        try {
            $data = $request->validate([
                'approved_amount' => 'nullable|numeric|min:0.01',
                'start_date' => 'required|date|date_format:Y-m-d',
            ]);
        } catch (ValidationException $e) {
            return $this->validationErrorResponse($e);
        }

        $loan = KikobaLoan::where('company_id', $this->getCompanyId())
            ->where('status', 'pending')
            ->with('loanProduct')
            ->find($id);

        if (! $loan) {
            return $this->errorResponse('Loan application not found or not pending', 404);
        }

        [$approvedAmount, $error] = $this->resolveApprovedAmount($loan, $data['approved_amount'] ?? null);
        if ($error) {
            return $this->errorResponse($error, 422);
        }

        $startDate = Carbon::parse($data['start_date'])->startOfDay();
        $installments = $scheduleService->generate($loan->loanProduct, $approvedAmount, $loan->loan_period, $startDate);

        return $this->successResponse($installments);
    }

    /**
     * Single-step approval: accepting a pending application immediately
     * generates its repayment schedule and activates it. The approver may
     * lower the amount (never above the eligible ceiling captured at
     * application time) and must supply the disbursement start date the
     * schedule is built from.
     */
    public function approve(Request $request, int $id, KikobaLoanScheduleService $scheduleService)
    {
        try {
            $data = $request->validate([
                'approved_amount' => 'nullable|numeric|min:0.01',
                'start_date' => 'required|date|date_format:Y-m-d',
            ]);
        } catch (ValidationException $e) {
            return $this->validationErrorResponse($e);
        }

        $loan = KikobaLoan::where('company_id', $this->getCompanyId())
            ->where('status', 'pending')
            ->with('loanProduct')
            ->find($id);

        if (! $loan) {
            return $this->errorResponse('Loan application not found or not pending', 404);
        }

        $product = $loan->loanProduct;

        [$approvedAmount, $error] = $this->resolveApprovedAmount($loan, $data['approved_amount'] ?? null);
        if ($error) {
            return $this->errorResponse($error, 422);
        }

        $startDate = Carbon::parse($data['start_date'])->startOfDay();
        $installments = $scheduleService->generate($product, $approvedAmount, $loan->loan_period, $startDate);

        DB::transaction(function () use ($loan, $installments, $approvedAmount, $startDate) {
            foreach ($installments as $i => $inst) {
                KikobaLoanSchedule::create([
                    'kikoba_loan_id' => $loan->id,
                    'installment_no' => $i + 1,
                    'due_date' => $inst['due_date'],
                    'principal_amount' => $inst['principal'],
                    'interest_amount' => $inst['interest'],
                    'total_amount' => $inst['total'],
                ]);
            }

            $loan->update([
                'approved_amount' => $approvedAmount,
                'start_date' => $startDate->toDateString(),
                'status' => 'active',
                'approved_by' => $this->getUserId(),
                'approved_at' => now(),
            ]);
        });

        return $this->successResponse(
            $loan->fresh()->load(['group', 'groupMember.member', 'loanProduct', 'schedules']),
            'Loan application approved and schedule generated'
        );
    }

    public function reject(Request $request, int $id)
    {
        try {
            $data = $request->validate([
                'rejection_reason' => 'required|string|max:500',
            ]);
        } catch (ValidationException $e) {
            return $this->validationErrorResponse($e);
        }

        $loan = KikobaLoan::where('company_id', $this->getCompanyId())
            ->where('status', 'pending')
            ->find($id);

        if (! $loan) {
            return $this->errorResponse('Loan application not found or not pending', 404);
        }

        $loan->update([
            'status' => 'rejected',
            'rejected_by' => $this->getUserId(),
            'rejected_at' => now(),
            'rejection_reason' => $data['rejection_reason'],
        ]);

        return $this->successResponse($loan, 'Loan application rejected');
    }

    /**
     * Shared by approve() and previewSchedule(): resolves the requested
     * approved amount (or defaults to requested_amount) and checks it against
     * the application's eligible ceiling and the product's minimum.
     *
     * @return array{0: float|null, 1: string|null} [amount, errorMessage]
     */
    private function resolveApprovedAmount(KikobaLoan $loan, ?float $requestedApprovedAmount): array
    {
        $product = $loan->loanProduct;
        $approvedAmount = $requestedApprovedAmount !== null
            ? round($requestedApprovedAmount, 2)
            : (float) $loan->requested_amount;

        if ($approvedAmount > (float) $loan->eligible_amount) {
            return [null, 'Approved amount (' . number_format($approvedAmount, 2) .
                ') cannot exceed this application\'s eligible amount (' . number_format((float) $loan->eligible_amount, 2) . ')'];
        }

        if ($approvedAmount < (float) $product->min_loan_amount) {
            return [null, 'Approved amount (' . number_format($approvedAmount, 2) .
                ') is below this product\'s minimum loan amount (' . number_format((float) $product->min_loan_amount, 2) . ')'];
        }

        return [$approvedAmount, null];
    }

    /**
     * Sum of a group member's PAID share contributions — the same join
     * pattern used by KikobaGroupMemberController/KikobaDashboardController,
     * kept in one place so eligibility and application use identical figures.
     */
    private function shareValueFor(int $groupMemberId): float
    {
        return (float) DB::table('kikoba_contributions as c')
            ->join('kikoba_group_member_products as gmp', 'gmp.id', '=', 'c.group_member_product_id')
            ->join('kikoba_group_products as gp', 'gp.id', '=', 'gmp.kikoba_group_product_id')
            ->join('kikoba_products as p', 'p.id', '=', 'gp.kikoba_product_id')
            ->where('gmp.kikoba_group_member_id', $groupMemberId)
            ->where('p.product_type', 'share')
            ->sum('c.amount');
    }

    private function generateLoanNumber(): string
    {
        return 'KLN-' . now()->format('ymd') . '-' . Str::upper(Str::random(6));
    }

    protected function findGroup(int $groupId): ?KikobaGroup
    {
        return KikobaGroup::where('company_id', $this->getCompanyId())->find($groupId);
    }
}
