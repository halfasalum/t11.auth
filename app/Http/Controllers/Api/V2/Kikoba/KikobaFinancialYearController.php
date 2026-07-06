<?php

namespace App\Http\Controllers\Api\V2\Kikoba;

use App\Http\Controllers\Api\V2\BaseController;
use App\Models\KikobaFinancialYear;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
                'name' => 'required|string|max:100',
                'start_date' => 'required|date',
                'end_date' => 'required|date|after:start_date',
            ]);
        } catch (ValidationException $e) {
            return $this->validationErrorResponse($e);
        }

        $data['company_id'] = $this->getCompanyId();
        $data['status'] = 'upcoming';
        $data['is_current'] = false;

        $year = KikobaFinancialYear::create($data);

        return $this->successResponse($year, 'Financial year created successfully', 201);
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
