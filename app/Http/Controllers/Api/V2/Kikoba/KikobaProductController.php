<?php

namespace App\Http\Controllers\Api\V2\Kikoba;

use App\Http\Controllers\Api\V2\BaseController;
use App\Models\KikobaProduct;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class KikobaProductController extends BaseController
{
    public function index(Request $request)
    {
        $query = KikobaProduct::query()->where('company_id', $this->getCompanyId());

        if ($request->filled('product_type')) {
            $query->where('product_type', $request->string('product_type'));
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
            $data = $request->validate([
                'name' => 'required|string|max:150',
                'description' => 'nullable|string',
                'value' => 'required|numeric|min:0',
                'min_unit' => 'nullable|integer|min:1',
                'max_unit' => 'nullable|integer|gte:min_unit',
                'mandatory_contribution' => 'boolean',
                'submission_unit' => 'required|in:day,week,month',
                'submission_frequency' => 'required|integer|min:1',
                // week: 0=Sunday .. 6=Saturday
                'submission_day_of_week' => 'nullable|required_if:submission_unit,week|integer|between:0,6',
                // month: pick a fixed date or always the last day of the month
                'submission_month_option' => 'nullable|required_if:submission_unit,month|in:specific_date,end_of_month',
                'submission_day_of_month' => 'nullable|required_if:submission_month_option,specific_date|integer|between:1,31',
                'used_as_income' => 'boolean',
                'product_type' => 'required|in:share,saving,penalty',
                'income_calculation' => 'nullable|required_if:used_as_income,true|in:share_value,flat_rate',
            ]);
        } catch (ValidationException $e) {
            return $this->validationErrorResponse($e);
        }

        $data['company_id'] = $this->getCompanyId();
        $data['status'] = 'active';
        $data['min_unit'] = $data['min_unit'] ?? 1;
        $data = $this->normalizeScheduleSettings($data);

        $product = KikobaProduct::create($data);

        return $this->successResponse($product, 'Product created successfully', 201);
    }

    public function show(int $id)
    {
        $product = KikobaProduct::where('company_id', $this->getCompanyId())->find($id);

        if (! $product) {
            return $this->errorResponse('Product not found', 404);
        }

        return $this->successResponse($product);
    }

    public function update(Request $request, int $id)
    {
        $product = KikobaProduct::where('company_id', $this->getCompanyId())->find($id);

        if (! $product) {
            return $this->errorResponse('Product not found', 404);
        }

        try {
            $data = $request->validate([
                'name' => 'sometimes|required|string|max:150',
                'description' => 'nullable|string',
                'value' => 'sometimes|required|numeric|min:0',
                'min_unit' => 'nullable|integer|min:1',
                'max_unit' => 'nullable|integer|gte:min_unit',
                'mandatory_contribution' => 'boolean',
                'submission_unit' => 'sometimes|required|in:day,week,month',
                'submission_frequency' => 'sometimes|required|integer|min:1',
                'submission_day_of_week' => 'nullable|required_if:submission_unit,week|integer|between:0,6',
                'submission_month_option' => 'nullable|required_if:submission_unit,month|in:specific_date,end_of_month',
                'submission_day_of_month' => 'nullable|required_if:submission_month_option,specific_date|integer|between:1,31',
                'used_as_income' => 'boolean',
                'product_type' => 'sometimes|required|in:share,saving,penalty',
                'income_calculation' => 'nullable|in:share_value,flat_rate',
                'status' => 'sometimes|in:active,inactive',
            ]);
        } catch (ValidationException $e) {
            return $this->validationErrorResponse($e);
        }

        $data = $this->normalizeScheduleSettings($data, $product);

        $product->update($data);

        return $this->successResponse($product, 'Product updated successfully');
    }

    public function destroy(int $id)
    {
        $product = KikobaProduct::where('company_id', $this->getCompanyId())->find($id);

        if (! $product) {
            return $this->errorResponse('Product not found', 404);
        }

        if ($product->groupProducts()->exists()) {
            return $this->errorResponse('Cannot delete a product already assigned to one or more groups', 422);
        }

        $product->delete();

        return $this->successResponse(null, 'Product deleted successfully');
    }

    /**
     * Clear schedule-day fields that don't apply to the chosen submission unit,
     * so a product never carries stale "day of week" data on a monthly product
     * (or vice versa). Falls back to the existing product values on update.
     */
    protected function normalizeScheduleSettings(array $data, ?KikobaProduct $product = null): array
    {
        $unit = $data['submission_unit'] ?? $product?->submission_unit;

        if ($unit === 'week') {
            $data['submission_month_option'] = null;
            $data['submission_day_of_month'] = null;
        } elseif ($unit === 'month') {
            $data['submission_day_of_week'] = null;

            $monthOption = $data['submission_month_option'] ?? $product?->submission_month_option;
            if ($monthOption === 'end_of_month') {
                $data['submission_day_of_month'] = null;
            }
        } else { // day
            $data['submission_day_of_week'] = null;
            $data['submission_month_option'] = null;
            $data['submission_day_of_month'] = null;
        }

        return $data;
    }
}
