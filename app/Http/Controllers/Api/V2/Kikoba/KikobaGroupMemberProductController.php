<?php

namespace App\Http\Controllers\Api\V2\Kikoba;

use App\Http\Controllers\Api\V2\BaseController;
use App\Models\KikobaGroup;
use App\Models\KikobaGroupMember;
use App\Models\KikobaGroupMemberProduct;
use App\Models\KikobaGroupProduct;
use App\Services\Kikoba\KikobaScheduleGeneratorService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class KikobaGroupMemberProductController extends BaseController
{
    public function __construct(protected KikobaScheduleGeneratorService $scheduleGenerator)
    {
    }

    public function index(int $groupId, int $groupMemberId)
    {
        $groupMember = $this->findGroupMember($groupId, $groupMemberId);

        if (! $groupMember) {
            return $this->errorResponse('Group member not found', 404);
        }

        $products = $groupMember->memberProducts()->with('groupProduct.product')->get();

        return $this->successResponse($products);
    }

    /**
     * Enroll a member into a group product with a unit count. If the group
     * currently has an active financial-year cycle and the product is
     * mandatory, the contribution schedule is generated immediately.
     */
    public function store(Request $request, int $groupId, int $groupMemberId)
    {
        $groupMember = $this->findGroupMember($groupId, $groupMemberId);

        if (! $groupMember) {
            return $this->errorResponse('Group member not found', 404);
        }

        try {
            $data = $request->validate([
                'kikoba_group_product_id' => 'required|integer|exists:kikoba_group_products,id',
                'units' => 'required|integer|min:1',
                'enrolled_date' => 'required|date',
            ]);
        } catch (ValidationException $e) {
            return $this->validationErrorResponse($e);
        }

        $groupProduct = KikobaGroupProduct::where('kikoba_group_id', $groupMember->kikoba_group_id)
            ->find($data['kikoba_group_product_id']);

        if (! $groupProduct) {
            return $this->errorResponse('This product is not assigned to the group', 404);
        }

        if ($data['units'] < $groupProduct->effective_min_unit) {
            return $this->errorResponse("Units must be at least {$groupProduct->effective_min_unit}", 422);
        }

        if ($groupProduct->effective_max_unit && $data['units'] > $groupProduct->effective_max_unit) {
            return $this->errorResponse("Units cannot exceed {$groupProduct->effective_max_unit}", 422);
        }

        $memberProduct = DB::transaction(function () use ($groupMember, $groupProduct, $data) {
            /** @var KikobaGroupMemberProduct $memberProduct */
            $memberProduct = KikobaGroupMemberProduct::create([
                'kikoba_group_member_id' => $groupMember->id,
                'kikoba_group_product_id' => $groupProduct->id,
                'units' => $data['units'],
                'enrolled_date' => $data['enrolled_date'],
                'status' => 'active',
            ]);

            $activeGroupFinancialYear = $groupMember->group->groupFinancialYears()
                ->where('status', 'active')
                ->latest('id')
                ->first();

            if ($activeGroupFinancialYear) {
                $this->scheduleGenerator->generate($memberProduct, $activeGroupFinancialYear);
            }

            return $memberProduct;
        });

        return $this->successResponse(
            $memberProduct->load('groupProduct.product', 'schedules'),
            'Member enrolled in product successfully',
            201
        );
    }

    public function update(Request $request, int $groupId, int $groupMemberId, int $memberProductId)
    {
        $groupMember = $this->findGroupMember($groupId, $groupMemberId);

        if (! $groupMember) {
            return $this->errorResponse('Group member not found', 404);
        }

        $memberProduct = KikobaGroupMemberProduct::where('kikoba_group_member_id', $groupMember->id)->find($memberProductId);

        if (! $memberProduct) {
            return $this->errorResponse('Product enrollment not found', 404);
        }

        try {
            $data = $request->validate([
                'units' => 'sometimes|required|integer|min:1',
                'status' => 'sometimes|in:active,inactive',
                'exit_date' => 'nullable|date',
            ]);
        } catch (ValidationException $e) {
            return $this->validationErrorResponse($e);
        }

        $unitsChanged = isset($data['units']) && $data['units'] !== $memberProduct->units;

        $memberProduct = DB::transaction(function () use ($memberProduct, $data, $unitsChanged, $groupMember) {
            $memberProduct->update($data);

            if ($unitsChanged) {
                $activeGroupFinancialYear = $groupMember->group->groupFinancialYears()
                    ->where('status', 'active')
                    ->latest('id')
                    ->first();

                if ($activeGroupFinancialYear) {
                    $this->scheduleGenerator->regenerateFuture($memberProduct, $activeGroupFinancialYear);
                }
            }

            return $memberProduct;
        });

        return $this->successResponse($memberProduct->fresh('schedules'), 'Product enrollment updated successfully');
    }

    public function destroy(int $groupId, int $groupMemberId, int $memberProductId)
    {
        $groupMember = $this->findGroupMember($groupId, $groupMemberId);

        if (! $groupMember) {
            return $this->errorResponse('Group member not found', 404);
        }

        $memberProduct = KikobaGroupMemberProduct::where('kikoba_group_member_id', $groupMember->id)->find($memberProductId);

        if (! $memberProduct) {
            return $this->errorResponse('Product enrollment not found', 404);
        }

        if ($memberProduct->contributions()->exists()) {
            return $this->errorResponse('Cannot delete an enrollment with recorded contributions. Set status to inactive instead.', 422);
        }

        $memberProduct->schedules()->where('status', 'pending')->delete();
        $memberProduct->delete();

        return $this->successResponse(null, 'Product enrollment removed successfully');
    }

    public function schedules(int $groupId, int $groupMemberId, int $memberProductId, Request $request)
    {
        $groupMember = $this->findGroupMember($groupId, $groupMemberId);

        if (! $groupMember) {
            return $this->errorResponse('Group member not found', 404);
        }

        $memberProduct = KikobaGroupMemberProduct::where('kikoba_group_member_id', $groupMember->id)->find($memberProductId);

        if (! $memberProduct) {
            return $this->errorResponse('Product enrollment not found', 404);
        }

        $query = $memberProduct->schedules()->orderBy('due_date');

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        return $this->successResponse($query->get());
    }

    protected function findGroupMember(int $groupId, int $groupMemberId): ?KikobaGroupMember
    {
        $group = KikobaGroup::where('company_id', $this->getCompanyId())->find($groupId);

        if (! $group) {
            return null;
        }

        return KikobaGroupMember::where('kikoba_group_id', $group->id)->find($groupMemberId);
    }
}
