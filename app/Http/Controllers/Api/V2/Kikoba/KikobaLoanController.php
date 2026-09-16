<?php

namespace App\Http\Controllers\Api\V2\Kikoba;

use App\Http\Controllers\Api\V2\BaseController;
use App\Models\KikobaGroup;
use App\Models\KikobaGroupMember;
use App\Models\KikobaLoan;
use App\Models\KikobaLoanProduct;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Phase 2 of Kikoba Loans: applying for a loan against a member's paid share
 * value in a specific group. Approval/disbursement/repayment schedule are a
 * later phase — every application here just lands as "pending".
 */
class KikobaLoanController extends BaseController
{
    public function index(Request $request)
    {
        $query = KikobaLoan::where('company_id', $this->getCompanyId())
            ->with(['group', 'groupMember.member', 'loanProduct', 'applicant:id,name,first_name,last_name']);

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
                'loan_period' => 'required|integer|min:1',
                'purpose' => 'nullable|string',
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
        $requestedAmount = $product->eligibleAmountFor($shareValue, $multiplier);

        if ($requestedAmount < (float) $product->min_loan_amount) {
            return $this->errorResponse(
                'This member\'s share value at ' . $multiplier . 'x (' . number_format($requestedAmount, 2) .
                    ') is below this product\'s minimum loan amount (' . number_format((float) $product->min_loan_amount, 2) . ')',
                422
            );
        }

        $loan = KikobaLoan::create([
            'company_id' => $companyId,
            'kikoba_group_id' => $group->id,
            'kikoba_group_member_id' => $groupMember->id,
            'kikoba_loan_product_id' => $product->id,
            'loan_number' => $this->generateLoanNumber(),
            'share_value_at_application' => $shareValue,
            'multiplier' => $multiplier,
            'requested_amount' => $requestedAmount,
            'loan_period' => $data['loan_period'],
            'purpose' => $data['purpose'] ?? null,
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
            ->with(['group', 'groupMember.member', 'loanProduct', 'applicant:id,name,first_name,last_name'])
            ->find($id);

        if (! $loan) {
            return $this->errorResponse('Loan application not found', 404);
        }

        return $this->successResponse($loan);
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
