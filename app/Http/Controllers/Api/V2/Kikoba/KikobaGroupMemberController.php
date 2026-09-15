<?php

namespace App\Http\Controllers\Api\V2\Kikoba;

use App\Http\Controllers\Api\V2\BaseController;
use App\Models\KikobaContribution;
use App\Models\KikobaGroup;
use App\Models\KikobaGroupFinancialYear;
use App\Models\KikobaGroupMember;
use App\Models\KikobaGroupMemberProduct;
use App\Models\KikobaGroupProduct;
use App\Models\KikobaMember;
use App\Models\KikobaProduct;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class KikobaGroupMemberController extends BaseController
{
    /* public function index(int $groupId)
    {
        $group = $this->findGroup($groupId);

        if (! $group) {
            return $this->errorResponse('Group not found', 404);
        }

        $members = $group->members()
            ->with([
                'member',
                'memberProducts.groupProduct.product',
                'memberProducts.contributions', // pull actual payments, not just enrollment
            ])
            ->orderBy('role')
            ->get()
            ->map(function (KikobaGroupMember $groupMember) {
                $shareMemberProducts = $groupMember->memberProducts->filter(
                    fn($memberProduct) => $memberProduct->groupProduct->product->product_type === 'share'
                );

                $shareContributions = $shareMemberProducts->flatMap->contributions;

                $groupMember->setAttribute('share_units_contributed', $shareContributions->sum('units'));
                $groupMember->setAttribute('share_amount_contributed', $shareContributions->sum('amount'));

                return $groupMember;
            });

        return $this->successResponse($members);
    }
 */


    public function index(int $groupId)
    {
        $group = $this->findGroup($groupId);

        if (! $group) {
            return $this->errorResponse('Group not found', 404);
        }

        $members = $group->members()->with('member')->orderBy('role')->get();

        // Single aggregate query for the ENTIRE group — cost depends on total
        // row count, not on how many years of history each member has, and
        // returns only the two numbers we actually need per member.
        $shareTotals = DB::table('kikoba_contributions as c')
            ->join('kikoba_group_member_products as gmp', 'gmp.id', '=', 'c.group_member_product_id')
            ->join('kikoba_group_products as gp', 'gp.id', '=', 'gmp.kikoba_group_product_id')
            ->join('kikoba_products as p', 'p.id', '=', 'gp.kikoba_product_id')
            ->whereIn('gmp.kikoba_group_member_id', $members->pluck('id'))
            ->where('p.product_type', 'share')
            ->groupBy('gmp.kikoba_group_member_id')
            ->select(
                'gmp.kikoba_group_member_id as group_member_id',
                DB::raw('SUM(c.units) as total_units'),
                DB::raw('SUM(c.amount) as total_amount')
            )
            ->get()
            ->keyBy('group_member_id');

        $members->each(function (KikobaGroupMember $groupMember) use ($shareTotals) {
            $totals = $shareTotals->get($groupMember->id);

            $groupMember->setAttribute('share_units_contributed', (int) ($totals->total_units ?? 0));
            $groupMember->setAttribute('share_amount_contributed', (float) ($totals->total_amount ?? 0));
        });

        return $this->successResponse($members);
    }

    

    public function store(Request $request, int $groupId)
    {
        $group = $this->findGroup($groupId);

        if (! $group) {
            return $this->errorResponse('Group not found', 404);
        }

        // New members can only join between cycles — while any financial year
        // is still open (allocated/pending or active) for this group, adding a
        // member would leave them without a schedule for the running cycle (or
        // require special mid-cycle handling we don't support), so block it
        // until every cycle has been closed.
        if ($this->groupHasOpenFinancialYear($group->id)) {
            return $this->errorResponse(
                'Cannot add a new member while this group has an open financial year cycle. Close all financial years for this group before adding members.',
                422
            );
        }

        try {
            $data = $request->validate([
                'kikoba_member_id' => 'required|integer|exists:kikoba_members,id',
                'role' => 'nullable|in:chairperson,secretary,treasurer,member',
                'joined_date' => 'required|date',
            ]);
        } catch (ValidationException $e) {
            return $this->validationErrorResponse($e);
        }

        $member = KikobaMember::where('company_id', $this->getCompanyId())->find($data['kikoba_member_id']);

        if (! $member) {
            return $this->errorResponse('Member not found for this company', 404);
        }

        // Prevent adding a member who is already active in this group
        $alreadyExists = KikobaGroupMember::where('kikoba_group_id', $group->id)
            ->where('kikoba_member_id', $data['kikoba_member_id'])
            ->where('status', 'active')
            ->exists();

        if ($alreadyExists) {
            return $this->errorResponse('Member is already an active member of this group', 422);
        }

        $data['kikoba_group_id'] = $group->id;
        $data['role'] = $data['role'] ?? 'member';
        $data['status'] = 'active';

        $groupMember = KikobaGroupMember::create($data);

        // Auto-assign all active group products to the new member
        $activeGroupProducts = KikobaGroupProduct::where('kikoba_group_id', $group->id)
            ->where('status', 'active')
            ->get();


        foreach ($activeGroupProducts as $groupProduct) {
            KikobaGroupMemberProduct::create([
                'kikoba_group_member_id' => $groupMember->id,
                'kikoba_group_product_id' => $groupProduct->id,
                'units' => $groupProduct->effective_min_unit ?? 1,
                'enrolled_date' => $data['joined_date'],
                'status' => 'active',
            ]);
        }

        return $this->successResponse(
            $groupMember->load(['member', 'memberProducts.groupProduct.product']),
            'Member added to group successfully',
            201
        );
    }


    public function update(Request $request, int $groupId, int $groupMemberId)
    {
        $group = $this->findGroup($groupId);

        if (! $group) {
            return $this->errorResponse('Group not found', 404);
        }

        $groupMember = KikobaGroupMember::where('kikoba_group_id', $group->id)->find($groupMemberId);

        if (! $groupMember) {
            return $this->errorResponse('Group member not found', 404);
        }

        try {
            $data = $request->validate([
                'role' => 'sometimes|in:chairperson,secretary,treasurer,member',
                'status' => 'sometimes|in:active,inactive,exited',
                'exit_date' => 'nullable|date',
            ]);
        } catch (ValidationException $e) {
            return $this->validationErrorResponse($e);
        }

        // Re-activating a previously inactive/exited member is effectively
        // re-assigning them to the group — subject to the same "no open cycle"
        // rule as adding a brand new member.
        $isReactivating = ($data['status'] ?? null) === 'active' && $groupMember->status !== 'active';

        if ($isReactivating && $this->groupHasOpenFinancialYear($group->id)) {
            return $this->errorResponse(
                'Cannot reactivate a member while this group has an open financial year cycle. Close all financial years for this group before adding members.',
                422
            );
        }

        $groupMember->update($data);

        return $this->successResponse($groupMember, 'Group member updated successfully');
    }

    public function destroy(int $groupId, int $groupMemberId)
    {
        $group = $this->findGroup($groupId);

        if (! $group) {
            return $this->errorResponse('Group not found', 404);
        }

        $groupMember = KikobaGroupMember::where('kikoba_group_id', $group->id)->find($groupMemberId);

        if (! $groupMember) {
            return $this->errorResponse('Group member not found', 404);
        }

        if ($groupMember->memberProducts()->where('status', 'active')->exists()) {
            return $this->errorResponse('Cannot remove a member with active product enrollments. Exit them from products first.', 422);
        }

        $groupMember->delete();

        return $this->successResponse(null, 'Member removed from group successfully');
    }

    protected function findGroup(int $groupId): ?KikobaGroup
    {
        return KikobaGroup::where('company_id', $this->getCompanyId())->find($groupId);
    }

    /**
     * Whether this group has any financial-year cycle that isn't closed yet
     * (upcoming/pending/active/terminated). A group with no cycles at all
     * counts as "all closed" — nothing to block.
     */
    protected function groupHasOpenFinancialYear(int $groupId): bool
    {
        return KikobaGroupFinancialYear::where('kikoba_group_id', $groupId)
            ->where('status', '!=', 'closed')
            ->exists();
    }
}
