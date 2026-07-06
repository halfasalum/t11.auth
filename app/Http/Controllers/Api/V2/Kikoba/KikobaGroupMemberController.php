<?php

namespace App\Http\Controllers\Api\V2\Kikoba;

use App\Http\Controllers\Api\V2\BaseController;
use App\Models\KikobaGroup;
use App\Models\KikobaGroupMember;
use App\Models\KikobaMember;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class KikobaGroupMemberController extends BaseController
{
    public function index(int $groupId)
    {
        $group = $this->findGroup($groupId);

        if (! $group) {
            return $this->errorResponse('Group not found', 404);
        }

        $members = $group->members()->with('member')->orderBy('role')->get();

        return $this->successResponse($members);
    }

    public function store(Request $request, int $groupId)
    {
        $group = $this->findGroup($groupId);

        if (! $group) {
            return $this->errorResponse('Group not found', 404);
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

        $data['kikoba_group_id'] = $group->id;
        $data['role'] = $data['role'] ?? 'member';
        $data['status'] = 'active';

        $groupMember = KikobaGroupMember::create($data);

        return $this->successResponse($groupMember->load('member'), 'Member added to group successfully', 201);
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
}
