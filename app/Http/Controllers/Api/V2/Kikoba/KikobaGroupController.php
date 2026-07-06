<?php

namespace App\Http\Controllers\Api\V2\Kikoba;

use App\Http\Controllers\Api\V2\BaseController;
use App\Models\KikobaFinancialYear;
use App\Models\KikobaGroup;
use App\Models\KikobaGroupFinancialYear;
use App\Models\KikobaGroupProduct;
use App\Services\Kikoba\KikobaScheduleGeneratorService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class KikobaGroupController extends BaseController
{
    public function __construct(protected KikobaScheduleGeneratorService $scheduleGenerator) {}

    public function index(Request $request)
    {
        $query = KikobaGroup::query()->where('company_id', $this->getCompanyId());

        if ($request->filled('search')) {
            $search = $request->string('search');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")->orWhere('code', 'like', "%{$search}%");
            });
        }

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        $groups = $query->withCount('activeMembers')->orderByDesc('id')->paginate((int) $request->input('per_page', 20));

        return $this->successResponse($this->paginateResponse($groups));
    }

    public function store(Request $request)
    {
        try {
            $data = $request->validate([
                'name' => 'required|string|max:150',
                'code' => 'nullable|string|max:50',
                'description' => 'nullable|string',
            ]);
        } catch (ValidationException $e) {
            return $this->validationErrorResponse($e);
        }

        $data['company_id'] = $this->getCompanyId();
        $data['status'] = 'active';
        $data['created_by'] = $this->getUserId();

        $group = KikobaGroup::create($data);

        return $this->successResponse($group, 'Group registered successfully', 201);
    }

    public function show(int $id)
    {
        $group = KikobaGroup::where('company_id', $this->getCompanyId())
            ->with(['products.product', 'financialYears.groupFinancialYears'])
            ->withCount('activeMembers')
            ->find($id);

        if (! $group) {
            return $this->errorResponse('Group not found', 404);
        }

        return $this->successResponse($group);
    }

    public function update(Request $request, int $id)
    {
        $group = KikobaGroup::where('company_id', $this->getCompanyId())->find($id);

        if (! $group) {
            return $this->errorResponse('Group not found', 404);
        }

        try {
            $data = $request->validate([
                'name' => 'sometimes|required|string|max:150',
                'code' => 'nullable|string|max:50',
                'description' => 'nullable|string',
                'status' => 'sometimes|in:active,inactive,closed',
            ]);
        } catch (ValidationException $e) {
            return $this->validationErrorResponse($e);
        }

        $group->update($data);

        return $this->successResponse($group, 'Group updated successfully');
    }

    public function destroy(int $id)
    {
        $group = KikobaGroup::where('company_id', $this->getCompanyId())->find($id);

        if (! $group) {
            return $this->errorResponse('Group not found', 404);
        }

        if ($group->activeMembers()->exists()) {
            return $this->errorResponse('Cannot delete a group with active members', 422);
        }

        $group->delete();

        return $this->successResponse(null, 'Group deleted successfully');
    }

    // ---------------------------------------------------------------
    // Group <-> Product assignment
    // ---------------------------------------------------------------

    public function products(int $groupId)
    {
        $group = $this->findGroup($groupId);

        if (! $group) {
            return $this->errorResponse('Group not found', 404);
        }

        return $this->successResponse($group->products()->withPivot([
            'id',
            'value_override',
            'min_unit_override',
            'max_unit_override',
            'mandatory_override',
            'status',
        ])->get());
    }

    public function attachProduct(Request $request, int $groupId)
    {
        $group = $this->findGroup($groupId);

        if (! $group) {
            return $this->errorResponse('Group not found', 404);
        }

        try {
            $data = $request->validate([
                'kikoba_product_id' => 'required|integer|exists:kikoba_products,id',
                'value_override' => 'nullable|numeric|min:0',
                'min_unit_override' => 'nullable|integer|min:1',
                'max_unit_override' => 'nullable|integer|gte:min_unit_override',
                'mandatory_override' => 'nullable|boolean',
            ]);
        } catch (ValidationException $e) {
            return $this->validationErrorResponse($e);
        }

        $data['kikoba_group_id'] = $group->id;
        $data['status'] = 'active';

        $groupProduct = KikobaGroupProduct::create($data);

        return $this->successResponse($groupProduct->load('product'), 'Product attached to group successfully', 201);
    }

    public function updateProduct(Request $request, int $groupId, int $groupProductId)
    {
        $group = $this->findGroup($groupId);

        if (! $group) {
            return $this->errorResponse('Group not found', 404);
        }

        $groupProduct = KikobaGroupProduct::where('kikoba_group_id', $group->id)->find($groupProductId);

        if (! $groupProduct) {
            return $this->errorResponse('Group product not found', 404);
        }

        try {
            $data = $request->validate([
                'value_override' => 'nullable|numeric|min:0',
                'min_unit_override' => 'nullable|integer|min:1',
                'max_unit_override' => 'nullable|integer|gte:min_unit_override',
                'mandatory_override' => 'nullable|boolean',
                'status' => 'sometimes|in:active,inactive',
            ]);
        } catch (ValidationException $e) {
            return $this->validationErrorResponse($e);
        }

        $groupProduct->update($data);

        return $this->successResponse($groupProduct, 'Group product updated successfully');
    }

    public function detachProduct(int $groupId, int $groupProductId)
    {
        $group = $this->findGroup($groupId);

        if (! $group) {
            return $this->errorResponse('Group not found', 404);
        }

        $groupProduct = KikobaGroupProduct::where('kikoba_group_id', $group->id)->find($groupProductId);

        if (! $groupProduct) {
            return $this->errorResponse('Group product not found', 404);
        }

        if ($groupProduct->memberProducts()->exists()) {
            return $this->errorResponse('Cannot remove a product already enrolled by members', 422);
        }

        $groupProduct->delete();

        return $this->successResponse(null, 'Product detached from group successfully');
    }

    // ---------------------------------------------------------------
    // Group <-> Financial Year cycle
    // ---------------------------------------------------------------

    public function financialYears(int $groupId)
    {
        $group = $this->findGroup($groupId);

        if (! $group) {
            return $this->errorResponse('Group not found', 404);
        }

        return $this->successResponse(
            $group->groupFinancialYears()->with('financialYear')->orderByDesc('id')->get()
        );
    }

    /**
     * Start a contribution cycle for this group within a given company
     * financial year, and generate schedules for all currently enrolled
     * mandatory member-products.
     */
    public function startFinancialYear(Request $request, int $groupId)
    {
        $group = $this->findGroup($groupId);

        if (! $group) {
            return $this->errorResponse('Group not found', 404);
        }

        try {
            $data = $request->validate([
                'kikoba_financial_year_id' => 'required|integer|exists:kikoba_financial_years,id',
                'start_date' => 'nullable|date',
                'end_date' => 'nullable|date|after:start_date',
            ]);
        } catch (ValidationException $e) {
            return $this->validationErrorResponse($e);
        }

        $groupFinancialYear = DB::transaction(function () use ($group, $data) {
            $data['kikoba_group_id'] = $group->id;
            $data['status'] = 'active';

            /** @var KikobaGroupFinancialYear $gfy */
            $gfy = KikobaGroupFinancialYear::create($data);

            $memberProducts = \App\Models\KikobaGroupMemberProduct::query()
                ->whereHas('groupMember', fn($q) => $q->where('kikoba_group_id', $group->id)->where('status', 'active'))
                ->where('status', 'active')
                ->get();

            foreach ($memberProducts as $memberProduct) {
                $this->scheduleGenerator->generate($memberProduct, $gfy);
            }

            return $gfy;
        });

        return $this->successResponse($groupFinancialYear->load('financialYear'), 'Financial year cycle started and schedules generated', 201);
    }

    public function closeFinancialYear(int $groupId, int $groupFinancialYearId)
    {
        $group = $this->findGroup($groupId);

        if (! $group) {
            return $this->errorResponse('Group not found', 404);
        }

        $gfy = KikobaGroupFinancialYear::where('kikoba_group_id', $group->id)->find($groupFinancialYearId);

        if (! $gfy) {
            return $this->errorResponse('Group financial year cycle not found', 404);
        }

        $gfy->update(['status' => 'closed']);

        return $this->successResponse($gfy, 'Financial year cycle closed');
    }

    protected function findGroup(int $groupId): ?KikobaGroup
    {
        return KikobaGroup::where('company_id', $this->getCompanyId())->find($groupId);
    }


   

// Add these methods to the existing KikobaGroupController

    /**
     * Get available financial years for allocation
     */
    public function getAvailableFinancialYears(int $groupId)
    {
        $group = $this->findGroup($groupId);

        if (! $group) {
            return $this->errorResponse('Group not found', 404);
        }

        // Get already allocated financial years
        $allocatedIds = KikobaGroupFinancialYear::where('kikoba_group_id', $groupId)
            ->pluck('kikoba_financial_year_id')
            ->toArray();

        // Get available financial years (not allocated to this group)
        $availableYears = KikobaFinancialYear::where('company_id', $this->getCompanyId())
            ->whereNotIn('id', $allocatedIds)
            ->where('status', '!=', 'closed')
            ->orderByDesc('start_date')
            ->get();

        return $this->successResponse($availableYears);
    }

    /**
     * Allocate a financial year to a group
     */
    public function allocateFinancialYear(Request $request, int $groupId)
    {
        $group = $this->findGroup($groupId);

        if (! $group) {
            return $this->errorResponse('Group not found', 404);
        }

        try {
            $data = $request->validate([
                'kikoba_financial_year_id' => 'required|exists:kikoba_financial_years,id',
                'start_date' => 'nullable|date',
                'end_date' => 'nullable|date|after:start_date',
            ]);
        } catch (ValidationException $e) {
            return $this->validationErrorResponse($e);
        }

        // Check if already allocated
        $exists = KikobaGroupFinancialYear::where('kikoba_group_id', $groupId)
            ->where('kikoba_financial_year_id', $data['kikoba_financial_year_id'])
            ->exists();

        if ($exists) {
            return $this->errorResponse('Financial year already allocated to this group', 422);
        }

        $financialYear = KikobaFinancialYear::where('company_id', $this->getCompanyId())
            ->find($data['kikoba_financial_year_id']);

        if (! $financialYear) {
            return $this->errorResponse('Financial year not found', 404);
        }

        // If dates not provided, use financial year dates
        $startDate = $data['start_date'] ?? $financialYear->start_date;
        $endDate = $data['end_date'] ?? $financialYear->end_date;

        // Create group financial year allocation
        $groupFinancialYear = KikobaGroupFinancialYear::create([
            'kikoba_group_id' => $groupId,
            'kikoba_financial_year_id' => $data['kikoba_financial_year_id'],
            'start_date' => $startDate,
            'end_date' => $endDate,
            'status' => 'active',
        ]);

        // Update group financial year pivot status if needed
        $financialYear->groups()->syncWithoutDetaching([
            $groupId => [
                'start_date' => $startDate,
                'end_date' => $endDate,
                'status' => 'active',
            ]
        ]);

        return $this->successResponse(
            $groupFinancialYear->load(['financialYear']),
            'Financial year allocated to group successfully',
            201
        );
    }

    /**
     * Get allocated financial years for a group
     */
    public function getAllocatedFinancialYears(int $groupId)
    {
        $group = $this->findGroup($groupId);

        if (! $group) {
            return $this->errorResponse('Group not found', 404);
        }

        $allocated = KikobaGroupFinancialYear::where('kikoba_group_id', $groupId)
            ->with(['financialYear'])
            ->orderByDesc('created_at')
            ->get();

        return $this->successResponse($allocated);
    }

    /**
     * Update allocated financial year status (activate/close)
     */
    public function updateAllocatedFinancialYear(Request $request, int $groupId, int $allocationId)
    {
        $group = $this->findGroup($groupId);

        if (! $group) {
            return $this->errorResponse('Group not found', 404);
        }

        $allocation = KikobaGroupFinancialYear::where('kikoba_group_id', $groupId)
            ->find($allocationId);

        if (! $allocation) {
            return $this->errorResponse('Allocation not found', 404);
        }

        try {
            $data = $request->validate([
                'status' => 'required|in:active,closed',
                'end_date' => 'nullable|date',
            ]);
        } catch (ValidationException $e) {
            return $this->validationErrorResponse($e);
        }

        $allocation->status = $data['status'];

        if ($data['status'] === 'closed' && isset($data['end_date'])) {
            $allocation->end_date = $data['end_date'];
        }

        $allocation->save();

        // Update pivot table
        $allocation->financialYear->groups()->syncWithoutDetaching([
            $groupId => [
                'status' => $data['status'],
                'end_date' => $allocation->end_date,
            ]
        ]);

        return $this->successResponse($allocation, 'Allocation updated successfully');
    }

    /**
     * Remove financial year allocation from group
     */
    public function removeAllocatedFinancialYear(int $groupId, int $allocationId)
    {
        $group = $this->findGroup($groupId);

        if (! $group) {
            return $this->errorResponse('Group not found', 404);
        }

        $allocation = KikobaGroupFinancialYear::where('kikoba_group_id', $groupId)
            ->find($allocationId);

        if (! $allocation) {
            return $this->errorResponse('Allocation not found', 404);
        }

        // Check if there are any schedules associated
        if ($allocation->schedules()->exists()) {
            return $this->errorResponse(
                'Cannot remove financial year with existing contribution schedules',
                422
            );
        }

        // Remove from pivot table
        $allocation->financialYear->groups()->detach($groupId);

        // Delete the allocation
        $allocation->delete();

        return $this->successResponse(null, 'Financial year removed from group successfully');
    }
}
