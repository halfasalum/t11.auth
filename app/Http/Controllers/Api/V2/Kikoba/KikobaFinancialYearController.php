<?php

namespace App\Http\Controllers\Api\V2\Kikoba;

use App\Http\Controllers\Api\V2\BaseController;
use App\Models\KikobaFinancialYear;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class KikobaFinancialYearController extends BaseController
{
    public function index(Request $request)
    {
        $query = KikobaFinancialYear::query()->where('company_id', $this->getCompanyId());

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        $years = $query->orderByDesc('start_date')->paginate((int) $request->input('per_page', 20));

        return $this->successResponse($this->paginateResponse($years));
    }

    public function store(Request $request)
    {
        try {
            $data = $request->validate([
                'name'       => [
                    'required',
                    'string',
                    'max:100',
                    Rule::unique('kikoba_financial_years', 'name')->where('company_id', $this->getCompanyId())->whereNull('deleted_at')
                ],
                'start_date' => 'required|date',
                'end_date'   => 'required|date|after:start_date',
            ]);
        } catch (ValidationException $e) {
            return $this->validationErrorResponse($e);
        }

        $companyId = $this->getCompanyId();
        $today     = now()->startOfDay();   // Today's date without time

        // Deactivate all previous financial years for this company
        KikobaFinancialYear::where('company_id', $companyId)
            ->update(['is_current' => false]);

        // Determine if this new year should be current
        $isCurrent = $today->between(
            Carbon::parse($data['start_date'])->startOfDay(),
            Carbon::parse($data['end_date'])->endOfDay()
        );

        $data['company_id'] = $companyId;
        $data['status']     = 'upcoming';
        $data['is_current'] = 0;

        $year = KikobaFinancialYear::create($data);

        return $this->successResponse(
            $year,
            'Financial year created successfully',
            201
        );
    }

    public function show(int $id)
    {
        $year = KikobaFinancialYear::where('company_id', $this->getCompanyId())->find($id);

        if (! $year) {
            return $this->errorResponse('Financial year not found', 404);
        }

        return $this->successResponse($year);
    }

    public function update(Request $request, int $id)
    {
        $year = KikobaFinancialYear::where('company_id', $this->getCompanyId())->find($id);

        if (! $year) {
            return $this->errorResponse('Financial year not found', 404);
        }

        try {
            $data = $request->validate([
                'name' => 'sometimes|required|string|max:100',
                'start_date' => 'sometimes|required|date',
                'end_date' => 'sometimes|required|date|after:start_date',
            ]);
        } catch (ValidationException $e) {
            return $this->validationErrorResponse($e);
        }

        $year->update($data);

        return $this->successResponse($year, 'Financial year updated successfully');
    }

    public function destroy(int $id)
    {
        $year = KikobaFinancialYear::where('company_id', $this->getCompanyId())->find($id);

        if (! $year) {
            return $this->errorResponse('Financial year not found', 404);
        }

        if ($year->status === 'active') {
            return $this->errorResponse('Cannot delete an active financial year', 422);
        }

        $year->delete();

        return $this->successResponse(null, 'Financial year deleted successfully');
    }

    /**
     * Mark this financial year as the current active one for the company,
     * demoting any previously active year to closed.
     */
    public function activate(int $id)
    {
        $year = KikobaFinancialYear::where('company_id', $this->getCompanyId())->find($id);

        if (! $year) {
            return $this->errorResponse('Financial year not found', 404);
        }

        DB::transaction(function () use ($year) {
            KikobaFinancialYear::where('company_id', $this->getCompanyId())
                ->where('id', '!=', $year->id)
                ->where('is_current', true)
                ->update(['is_current' => false, 'status' => 'closed']);

            $year->update(['is_current' => true, 'status' => 'active']);
        });

        return $this->successResponse($year->fresh(), 'Financial year activated successfully');
    }

    public function close(int $id)
    {
        $year = KikobaFinancialYear::where('company_id', $this->getCompanyId())->find($id);

        if (! $year) {
            return $this->errorResponse('Financial year not found', 404);
        }

        $year->update(['status' => 'closed']);

        return $this->successResponse($year, 'Financial year closed successfully');
    }

    public function terminate(int $id)
    {
        $year = KikobaFinancialYear::where('company_id', $this->getCompanyId())->find($id);

        if (! $year) {
            return $this->errorResponse('Financial year not found', 404);
        }

        $year->update(['status' => 'terminated']);

        return $this->successResponse($year, 'Financial year terminated successfully');
    }
}
