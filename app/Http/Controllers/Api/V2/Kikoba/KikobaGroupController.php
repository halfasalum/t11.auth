<?php

namespace App\Http\Controllers\Api\V2\Kikoba;

use App\Http\Controllers\Api\V2\BaseController;
use App\Models\KikobaContribution;
use App\Models\KikobaContributionSchedule;
use App\Models\KikobaFinancialYear;
use App\Models\KikobaGroup;
use App\Models\KikobaGroupFinancialYear;
use App\Models\KikobaGroupMember;
use App\Models\KikobaGroupMemberProduct;
use App\Models\KikobaGroupProduct;
use App\Services\Kikoba\KikobaFinancialYearCloseReportService;
use App\Services\Kikoba\KikobaScheduleGeneratorService;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

use function PHPSTORM_META\map;

class KikobaGroupController extends BaseController
{
    public function __construct(
        protected KikobaScheduleGeneratorService $scheduleGenerator,
        protected KikobaFinancialYearCloseReportService $closeReportService,
    ) {}

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

        $groups = $query->withCount([
            'activeMembers',
            'groupProducts as products_count' => fn ($q) => $q->where('status', 'active'),
            'groupFinancialYears as financial_years_count',
        ])->orderByDesc('id')->paginate((int) $request->input('per_page', 20));

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
            ->with(['products', 'financialYears.groupFinancialYears'])
            ->withCount([
                'activeMembers',
                'groupProducts as products_count' => fn ($q) => $q->where('status', 'active'),
                'groupFinancialYears as financial_years_count',
            ])
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

        // Members who joined before this product existed never got auto-enrolled
        // into it (that only happens at member-add time) — backfill them now so
        // starting a financial year later actually has something to schedule.
        $enrolled = $this->backfillMemberProductEnrollments($group, $groupProduct->id);

        $message = 'Product attached to group successfully';
        if ($enrolled > 0) {
            $message .= " — {$enrolled} existing member(s) auto-enrolled";
        }

        return $this->successResponse($groupProduct->load('product'), $message, 201);
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

        $reactivated = ($data['status'] ?? null) === 'active' && $groupProduct->status !== 'active';

        $groupProduct->update($data);

        if ($reactivated) {
            $this->backfillMemberProductEnrollments($group, $groupProduct->id);
        }

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
                // Accepts either the kikoba_group_financial_years (allocation) row id
                // or the parent kikoba_financial_years id — the two endpoints have
                // historically used the same field name for different ids.
                'kikoba_financial_year_id' => 'required|integer',
            ]);
        } catch (ValidationException $e) {
            return $this->validationErrorResponse($e);
        }

        $id = (int) $data['kikoba_financial_year_id'];

        // The field has historically carried either the allocation row id or the
        // parent financial-year id, so resolve by the allocation id first and fall
        // back to the parent id.
        $gfy = KikobaGroupFinancialYear::where('kikoba_group_id', $group->id)
            ->where('id', $id)
            ->first()
            ?? KikobaGroupFinancialYear::where('kikoba_group_id', $group->id)
                ->where('kikoba_financial_year_id', $id)
                ->first();

        if (! $gfy) {
            return $this->errorResponse('This financial year is not allocated to the group', 404);
        }

        if ($gfy->status === 'active') {
            return $this->errorResponse('This financial year cycle is already active', 422);
        }

        if ($gfy->status === 'closed') {
            return $this->errorResponse('This financial year cycle is already closed', 422);
        }

        if (! $gfy->start_date || ! $gfy->end_date) {
            return $this->errorResponse('This financial year has no start/end dates set', 422);
        }

        $summary = DB::transaction(function () use ($group, $gfy) {
            $gfy->update(['status' => 'active']);

            // Self-healing safety net: any active member missing an enrollment in
            // an active group product (e.g. product attached before this fix, or
            // a member reactivated outside the normal flow) gets backfilled here
            // so the cycle always has something to generate schedules for.
            $backfilled = $this->backfillMemberProductEnrollments($group);

            $memberProducts = KikobaGroupMemberProduct::query()
                ->whereHas('groupMember', fn($q) => $q->where('kikoba_group_id', $group->id)->where('status', 'active'))
                ->where('status', 'active')
                ->get();

            $rows = 0;
            foreach ($memberProducts as $memberProduct) {
                $rows += $this->scheduleGenerator->generate($memberProduct, $gfy)->count();
            }

            Log::info("Kikoba cycle started for group {$group->id}, gfy {$gfy->id}: "
                . "{$memberProducts->count()} enrollment(s) ({$backfilled} backfilled), {$rows} schedule row(s) created");

            return ['enrollments' => $memberProducts->count(), 'rows' => $rows, 'backfilled' => $backfilled];
        });

        $message = "Financial year cycle started — {$summary['enrollments']} enrollment(s), {$summary['rows']} schedule row(s) created";
        if ($summary['backfilled'] > 0) {
            $message .= " ({$summary['backfilled']} member(s) auto-enrolled in products first)";
        }

        return $this->successResponse(
            $gfy->load('financialYear'),
            $message,
            201
        );
    }

    /**
     * Ensure every active member of the group has an enrollment row for every
     * active group product. New members get enrolled in current products at
     * member-add time (see KikobaGroupMemberController::store); this covers the
     * reverse — a product attached (or reactivated) after members already
     * joined — so schedule generation never silently sees zero enrollments.
     * Never touches an existing row, so an intentionally removed/exited
     * enrollment is left alone.
     */
    private function backfillMemberProductEnrollments(KikobaGroup $group, ?int $onlyGroupProductId = null): int
    {
        $members = KikobaGroupMember::where('kikoba_group_id', $group->id)
            ->where('status', 'active')
            ->get(['id']);

        if ($members->isEmpty()) {
            return 0;
        }

        $groupProductsQuery = KikobaGroupProduct::where('kikoba_group_id', $group->id)
            ->where('status', 'active');

        if ($onlyGroupProductId) {
            $groupProductsQuery->where('id', $onlyGroupProductId);
        }

        $groupProducts = $groupProductsQuery->get();

        if ($groupProducts->isEmpty()) {
            return 0;
        }

        $existingPairs = KikobaGroupMemberProduct::whereIn('kikoba_group_member_id', $members->pluck('id'))
            ->whereIn('kikoba_group_product_id', $groupProducts->pluck('id'))
            ->get(['kikoba_group_member_id', 'kikoba_group_product_id'])
            ->map(fn ($row) => "{$row->kikoba_group_member_id}:{$row->kikoba_group_product_id}")
            ->flip();

        $today = now()->toDateString();
        $created = 0;

        foreach ($members as $member) {
            foreach ($groupProducts as $groupProduct) {
                if (isset($existingPairs["{$member->id}:{$groupProduct->id}"])) {
                    continue;
                }

                KikobaGroupMemberProduct::create([
                    'kikoba_group_member_id' => $member->id,
                    'kikoba_group_product_id' => $groupProduct->id,
                    'units' => $groupProduct->effective_min_unit ?? 1,
                    'enrolled_date' => $today,
                    'status' => 'active',
                ]);

                $created++;
            }
        }

        return $created;
    }

    /* public function closeFinancialYear(int $groupId, int $groupFinancialYearId)
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
    } */

    public function closeFinancialYear(int $groupId, int $groupFinancialYearId)
    {
        $group = $this->findGroup($groupId);

        if (! $group) {
            return $this->errorResponse('Group not found', 404);
        }

        Log::info("Group found  : \n " . $group . " \n ******************************** ");

        $gfy = KikobaGroupFinancialYear::where('kikoba_group_id', $group->id)->find($groupFinancialYearId);

        if (! $gfy) {
            return $this->errorResponse('Group financial year cycle not found', 404);
        }

        Log::info("Group financial year : \n " . $gfy . " \n ******************************** ");

        if ($gfy->status === 'closed') {
            return $this->errorResponse('This financial year cycle is already closed', 422);
        }

        DB::transaction(function () use ($gfy) {
            // A draft payout report is ready to review the moment the cycle
            // closes, but locking it is always a separate, explicit action
            // (see KikobaReportController::finalizeCloseReport) — closing
            // the cycle itself must never silently lock the payout figures.
            $this->closeReportService->generate($gfy, $this->getUserId());

            $gfy->update(['status' => 'closed']);
        });

        return $this->successResponse(
            $gfy->fresh()->load('closeReports.groupMember.member'),
            'Financial year cycle closed and a draft payout report generated'
        );
    }

    protected function findGroup(int $groupId): ?KikobaGroup
    {
        return KikobaGroup::where('company_id', $this->getCompanyId())->find($groupId);
    }

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
            ]);
        } catch (ValidationException $e) {
            return $this->validationErrorResponse($e);
        }

        // Check if already allocated
        $exists = KikobaGroupFinancialYear::where('kikoba_group_id', $groupId)
            ->where('kikoba_financial_year_id', $data['kikoba_financial_year_id'])
            ->exists();

        if ($exists) {
            Log::info('Financial year already allocated to this group');
            return $this->errorResponse('Financial year already allocated to this group', 422);
        }

        // A freshly-created financial year sits in "upcoming" status until someone
        // explicitly activates it — but it must still be allocatable to a group at
        // that point, otherwise it could never reach "active" via this flow. Match
        // the same "not closed" rule used by getAvailableFinancialYears() so a year
        // shown as available here never turns out to be un-allocatable.
        $financialYear = KikobaFinancialYear::where('company_id', $this->getCompanyId())
            ->where('id', $data['kikoba_financial_year_id'])
            ->where('status', '!=', 'closed')
            ->first();

        if (! $financialYear) {
            Log::info('Financial year not found or already closed', ['id' => $data['kikoba_financial_year_id']]);
            return $this->errorResponse('Financial year not found or already closed', 404);
        }

        // If dates not provided, use financial year dates
        $startDate = $financialYear->start_date;
        $endDate = $financialYear->end_date;

        // Create group financial year allocation
        $groupFinancialYear = KikobaGroupFinancialYear::create([
            'kikoba_group_id' => $groupId,
            'kikoba_financial_year_id' => $data['kikoba_financial_year_id'],
            'start_date' => $startDate,
            'end_date' => $endDate,
            'status' => 'pending',
        ]);

        Log::info('Group financial year created: ' . $groupFinancialYear);

        // Update group financial year pivot status if needed
        /* $financialYear->groups()->syncWithoutDetaching([
            $groupId => [
                'start_date' => $startDate,
                'end_date' => $endDate,
                'status' => 'active',
            ]
        ]); */

        /* Log::info('Group financial year pivot updated successfully'); */

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

    public function groupUnfilledPayments(int $groupId)
    {
        $group = $this->findGroup($groupId);

        if (!$group) {
            return $this->errorResponse('Group not found', 404);
        }

        $dueDates = KikobaContributionSchedule::where('status', 'pending')
            ->whereHas('groupFinancialYear', function ($query) {
                $query->where('status', 'active');
            })
            ->whereHas('memberProduct.groupMember', function ($query) use ($groupId) {
                $query->where('kikoba_group_id', $groupId);
            })
            ->selectRaw('DATE(due_date) as due_date, group_financial_year_id',)
            ->distinct()
            ->orderBy('due_date')
            ->paginate(12);   // 10 dates per page

        return $this->successResponse($dueDates);
    }

    public function getGroupUnfilledPaymentsByDate(Request $request, int $financialYearId)
    {
        $validated = $request->validate([
            'date' => 'required|date',
        ]);

        $date = $validated['date'];

        $schedules = KikobaContributionSchedule::where('group_financial_year_id', $financialYearId)
            ->whereDate('due_date', $date)
            ->where('status', 'pending')
            ->with([
                'memberProduct.groupMember.member',
                'memberProduct.groupProduct.product'
            ])
            ->get();

        if ($schedules->isEmpty()) {
            return $this->errorResponse('No unfilled payments found', 404);
        }

        // Group by Member
        $grouped = $schedules->groupBy(function ($schedule) {
            return $schedule->memberProduct->groupMember->id;
        });

        $data = $grouped->map(function ($memberSchedules) {
            $first = $memberSchedules->first();
            $member = $first->memberProduct->groupMember->member;

            $products = $memberSchedules->map(function ($schedule) {
                return [
                    'schedule_id'     => $schedule->id,
                    'product_name'    => $schedule->memberProduct->groupProduct->product->name ?? '—',
                    'units'           => 0,
                    //'units'           => $schedule->memberProduct->units,
                    'expected_amount' => $schedule->expected_amount,
                    'paid_amount'     => $schedule->paid_amount,
                    'sequence'        => $schedule->sequence,
                ];
            });

            return [
                'member_id'    => $member->id,
                'member_name'  => trim("{$member->first_name} {$member->middle_name} {$member->last_name}"),
                'member_phone' => $member->phone ?? '—',
                'member_no'    => $member->member_no ?? '—',
                'total_due'    => $memberSchedules->sum('expected_amount'),
                'products'     => $products,
            ];
        })->values();   // Reset keys to 0,1,2...

        return $this->successResponse($data);
    }

    public function processGroupSchedulePayments(Request $request, NotificationService $notificationService)
    {
        $validated = $request->validate([
            'due_date'          => 'required|date',
            'financial_year_id' => 'required|integer|exists:kikoba_contribution_schedules,group_financial_year_id',
            'schedule_data'     => 'required|array|min:1',
            'schedule_data.*.schedule_id' => 'required|integer|exists:kikoba_contribution_schedules,id',
            'schedule_data.*.units'       => 'required|integer|min:0',
        ]);

        $dueDate         = Carbon::parse($validated['due_date'])->format('Y-m-d');
        $financialYearId = $validated['financial_year_id'];
        $scheduleData    = $validated['schedule_data'];

        // Fetch relevant schedules
        $schedules = KikobaContributionSchedule::where('group_financial_year_id', $financialYearId)
            ->whereDate('due_date', $dueDate)
            ->whereIn('id', collect($scheduleData)->pluck('schedule_id'))
            ->where('status', 'pending')
            ->with([
                'memberProduct.groupMember.member',
                'memberProduct.groupMember.group',
                'memberProduct.groupProduct.product'
            ])
            ->get()
            ->keyBy('id');

        $processed = collect();
        $errors    = collect();

        // Per-member running totals for this batch, keyed by kikoba_member_id,
        // so a member with both a share and a savings row processed together
        // gets ONE consolidated SMS afterwards instead of one per row.
        $memberTotals = [];

        foreach ($scheduleData as $item) {
            $scheduleId = $item['schedule_id'];
            $units      = max(0, (int) $item['units']);

            $schedule = $schedules->get($scheduleId);

            if (!$schedule) {
                $errors->push([
                    'schedule_id' => $scheduleId,
                    'error'       => 'Schedule not found, not pending, or does not match the date/financial year.'
                ]);
                continue;
            }

            $expectedPerUnit = $schedule->memberProduct->effective_value ?? 0;
            $paidAmount      = $units * $expectedPerUnit;

            // Update Schedule
            $schedule->update([
                'paid_amount' => $paidAmount,
                'status'      => $paidAmount >= $schedule->expected_amount ? 'paid' : 'partial',
            ]);

            // Record Contribution
            KikobaContribution::updateOrCreate(
                [
                    'contribution_schedule_id' => $schedule->id,
                    'group_member_product_id'  => $schedule->memberProduct->id,
                ],
                [
                    'amount'          => $paidAmount,
                    'paid_date'       => $dueDate,
                    'reference'       => 'GROUP_PAY_' . $dueDate . '_' . $schedule->id,
                    'payment_method'  => 'cash',
                    'received_by'     => Auth::id(),
                    'notes'           => 'Group bulk payment',
                    'units'           => $units,
                    'unit_value'      => $expectedPerUnit,
                ]
            );

            $processed->push([
                'schedule_id'  => $schedule->id,
                'member_name'  => $schedule->memberProduct?->groupMember?->member?->full_name ?? '—',
                'product_name' => $schedule->memberProduct?->groupProduct?->product?->name ?? '—',
                'units_paid'   => $units,
                'amount_paid'  => $paidAmount,
                'new_status'   => $schedule->fresh()->status,
            ]);

            $member = $schedule->memberProduct?->groupMember?->member;

            if ($member && $paidAmount > 0) {
                $memberId = $member->id;

                if (!isset($memberTotals[$memberId])) {
                    $memberTotals[$memberId] = [
                        'name'  => $member->full_name ?? trim(($member->first_name ?? '') . ' ' . ($member->last_name ?? '')),
                        'phone' => $member->phone,
                        'group_name' => $schedule->memberProduct?->groupMember?->group?->name ?? '',
                        'share'   => 0.0,
                        'saving'  => 0.0,
                        'penalty' => 0.0,
                        'other'   => 0.0,
                    ];
                }

                $productType = $schedule->memberProduct?->groupProduct?->product?->product_type;
                $bucket = in_array($productType, ['share', 'saving', 'penalty'], true) ? $productType : 'other';
                $memberTotals[$memberId][$bucket] += $paidAmount;
            }
        }

        if ($processed->isEmpty()) {
            return $this->errorResponse('No valid payments were processed', 400);
        }

        $smsSent = 0;
        $smsFailed = 0;

        foreach ($memberTotals as $totals) {
            if (empty($totals['phone'])) {
                continue;
            }

            $message = $this->buildContributionSmsMessage(
                $totals['name'],
                $dueDate,
                (float) $totals['share'],
                (float) $totals['saving'],
                (float) $totals['penalty'],
                $totals['group_name']
            );

            if ($notificationService->sendSMS($totals['phone'], $message, $totals['group_name'])) {
                $smsSent++;
            } else {
                $smsFailed++;
            }
        }

        return $this->successResponse([
            'message'       => 'Payments processed successfully',
            'processed_count' => $processed->count(),
            'processed'     => $processed,
            'errors'        => $errors,
            'sms_sent'      => $smsSent,
            'sms_failed'    => $smsFailed,
        ]);
    }

    /**
     * Swahili SMS summarizing one member's bulk-payment batch — their share
     * and savings contributions (the two the member actually cares about
     * tracking), a penalty line only when one was collected, and a total.
     */
    private function buildContributionSmsMessage(
        string $name,
        string $date,
        float $share,
        float $saving,
        float $penalty,
        string $groupName
    ): string {
        $formattedDate = Carbon::parse($date)->format('d/m/Y');
        $total = $share + $saving + $penalty;

        $lines = ["Habari {$name},", "Mchango wako wa tarehe {$formattedDate} umepokelewa:"];

        if ($share > 0) {
            $lines[] = 'Hisa: TSh ' . number_format($share, 0);
        }
        if ($saving > 0) {
            $lines[] = 'Akiba: TSh ' . number_format($saving, 0);
        }
        if ($penalty > 0) {
            $lines[] = 'Adhabu: TSh ' . number_format($penalty, 0);
        }

        $lines[] = 'Jumla: TSh ' . number_format($total, 0);
        $lines[] = 'Asante' . ($groupName ? " - {$groupName}" : '') . '.';

        return implode("\n", $lines);
    }
}
