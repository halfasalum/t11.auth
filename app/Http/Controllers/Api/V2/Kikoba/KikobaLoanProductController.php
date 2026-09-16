<?php

namespace App\Http\Controllers\Api\V2\Kikoba;

use App\Http\Controllers\Api\V2\BaseController;
use App\Models\KikobaLoanProduct;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class KikobaLoanProductController extends BaseController
{
    public function index(Request $request)
    {
        $query = KikobaLoanProduct::query()->where('company_id', $this->getCompanyId());

        if ($request->filled('search')) {
            $search = $request->string('search');
            $query->where('name', 'like', "%{$search}%");
        }

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        $products = $query->orderBy('name')->paginate((int) $request->input('per_page', 20));

        return $this->successResponse($this->paginateResponse($products));
    }

    public function store(Request $request)
    {
        try {
            $data = $request->validate($this->rules());
        } catch (ValidationException $e) {
            return $this->validationErrorResponse($e);
        }

        $data['company_id'] = $this->getCompanyId();
        $data['created_by'] = $this->getUserId();
        $data['status'] = 'active';
        $data['share_multipliers'] = $this->normalizeMultipliers($data['share_multipliers']);
        $data = $this->normalizeInterestAndPenalty($data);

        $product = KikobaLoanProduct::create($data);

        return $this->successResponse($product, 'Loan product created successfully', 201);
    }

    public function show(int $id)
    {
        $product = KikobaLoanProduct::where('company_id', $this->getCompanyId())->find($id);

        if (! $product) {
            return $this->errorResponse('Loan product not found', 404);
        }

        return $this->successResponse($product);
    }

    public function update(Request $request, int $id)
    {
        $product = KikobaLoanProduct::where('company_id', $this->getCompanyId())->find($id);

        if (! $product) {
            return $this->errorResponse('Loan product not found', 404);
        }

        try {
            $data = $request->validate($this->rules(sometimes: true));
        } catch (ValidationException $e) {
            return $this->validationErrorResponse($e);
        }

        if (array_key_exists('share_multipliers', $data)) {
            $data['share_multipliers'] = $this->normalizeMultipliers($data['share_multipliers']);
        }

        $data = $this->normalizeInterestAndPenalty($data, $product);

        $product->update($data);

        return $this->successResponse($product, 'Loan product updated successfully');
    }

    public function destroy(int $id)
    {
        $product = KikobaLoanProduct::where('company_id', $this->getCompanyId())->find($id);

        if (! $product) {
            return $this->errorResponse('Loan product not found', 404);
        }

        $product->delete();

        return $this->successResponse(null, 'Loan product deleted successfully');
    }

    private function rules(bool $sometimes = false): array
    {
        $req = $sometimes ? 'sometimes|required' : 'required';

        return [
            'name' => "{$req}|string|max:150",
            'description' => 'nullable|string',

            'interest_mode' => "{$req}|in:fixed,percentage",
            'interest_rate' => 'nullable|required_if:interest_mode,percentage|numeric|min:0',
            'interest_amount' => 'nullable|required_if:interest_mode,fixed|numeric|min:0',

            'min_loan_amount' => "{$req}|numeric|min:0",
            'max_loan_amount' => 'nullable|numeric|gte:min_loan_amount',

            'min_loan_period' => "{$req}|integer|min:1",
            'max_loan_period' => "{$req}|integer|gte:min_loan_period",
            'loan_period_unit' => "{$req}|in:days,weeks,months",

            'repayment_interval' => "{$req}|integer|min:1",
            'repayment_interval_unit' => "{$req}|in:days,weeks,months",

            'skip_sat' => 'boolean',
            'skip_sun' => 'boolean',

            'penalty_type' => "{$req}|in:none,fixed,percentage",
            'fixed_penalty_amount' => 'nullable|required_if:penalty_type,fixed|numeric|min:0',
            'penalty_percentage' => 'nullable|required_if:penalty_type,percentage|numeric|min:0',

            // Allowed borrowing multiples of a member's paid share value, e.g. [1,2,3]
            'share_multipliers' => "{$req}|array|min:1",
            'share_multipliers.*' => 'numeric|min:0.1',

            'status' => 'sometimes|in:active,inactive',
        ];
    }

    /**
     * Dedupe, sort, and round the multiplier list so the same product never
     * stores e.g. [2, 1, 2, 1.005] — always a clean ascending set.
     */
    private function normalizeMultipliers(array $multipliers): array
    {
        return collect($multipliers)
            ->map(fn ($m) => round((float) $m, 2))
            ->filter(fn ($m) => $m > 0)
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    /**
     * Clear the interest/penalty fields that don't apply to the chosen mode,
     * so a product never carries a stale interest_rate while in fixed mode
     * (or vice versa). Falls back to the existing product values on update.
     */
    private function normalizeInterestAndPenalty(array $data, ?KikobaLoanProduct $product = null): array
    {
        $interestMode = $data['interest_mode'] ?? $product?->interest_mode;

        if ($interestMode === 'fixed') {
            $data['interest_rate'] = null;
        } elseif ($interestMode === 'percentage') {
            $data['interest_amount'] = null;
        }

        $penaltyType = $data['penalty_type'] ?? $product?->penalty_type;

        if ($penaltyType === 'none') {
            $data['fixed_penalty_amount'] = 0;
            $data['penalty_percentage'] = 0;
        } elseif ($penaltyType === 'fixed') {
            $data['penalty_percentage'] = 0;
        } elseif ($penaltyType === 'percentage') {
            $data['fixed_penalty_amount'] = 0;
        }

        return $data;
    }
}
