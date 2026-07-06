<?php

namespace App\Http\Controllers\Api\V2\Kikoba;

use App\Http\Controllers\Api\V2\BaseController;
use App\Models\KikobaMember;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class KikobaMemberController extends BaseController
{
    public function index(Request $request)
    {
        $query = KikobaMember::query()->where('company_id', $this->getCompanyId());

        if ($request->filled('search')) {
            $search = $request->string('search');
            $query->where(function ($q) use ($search) {
                $q->where('first_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%")
                    ->orWhere('member_no', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")
                    ->orWhere('id_number', 'like', "%{$search}%");
            });
        }

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        $members = $query->orderByDesc('id')->paginate((int) $request->input('per_page', 20));

        return $this->successResponse($this->paginateResponse($members));
    }

    public function store(Request $request)
    {
        try {
            $data = $request->validate([
                'customer_id' => 'nullable|integer|exists:customers,id',
                'member_no' => 'nullable|string|max:50',
                'first_name' => 'required|string|max:100',
                'middle_name' => 'nullable|string|max:100',
                'last_name' => 'required|string|max:100',
                'gender' => 'nullable|in:male,female,other',
                'date_of_birth' => 'nullable|date',
                'phone' => 'nullable|string|max:20',
                'email' => 'nullable|email|max:150',
                'address' => 'nullable|string',
                'id_type' => 'nullable|string|max:50',
                'id_number' => 'nullable|string|max:50',
                'next_of_kin_name' => 'nullable|string|max:150',
                'next_of_kin_phone' => 'nullable|string|max:20',
                'next_of_kin_relationship' => 'nullable|string|max:50',
                'photo_path' => 'nullable|string',
            ]);
        } catch (ValidationException $e) {
            return $this->validationErrorResponse($e);
        }

        $data['company_id'] = $this->getCompanyId();
        $data['status'] = 'active';

        $member = KikobaMember::create($data);

        return $this->successResponse($member, 'Member registered successfully', 201);
    }

    public function show(int $id)
    {
        $member = KikobaMember::where('company_id', $this->getCompanyId())
            ->with('groupMemberships.group')
            ->find($id);

        if (! $member) {
            return $this->errorResponse('Member not found', 404);
        }

        return $this->successResponse($member);
    }

    public function update(Request $request, int $id)
    {
        $member = KikobaMember::where('company_id', $this->getCompanyId())->find($id);

        if (! $member) {
            return $this->errorResponse('Member not found', 404);
        }

        try {
            $data = $request->validate([
                'customer_id' => 'nullable|integer|exists:customers,id',
                'member_no' => 'nullable|string|max:50',
                'first_name' => 'sometimes|required|string|max:100',
                'middle_name' => 'nullable|string|max:100',
                'last_name' => 'sometimes|required|string|max:100',
                'gender' => 'nullable|in:male,female,other',
                'date_of_birth' => 'nullable|date',
                'phone' => 'nullable|string|max:20',
                'email' => 'nullable|email|max:150',
                'address' => 'nullable|string',
                'id_type' => 'nullable|string|max:50',
                'id_number' => 'nullable|string|max:50',
                'next_of_kin_name' => 'nullable|string|max:150',
                'next_of_kin_phone' => 'nullable|string|max:20',
                'next_of_kin_relationship' => 'nullable|string|max:50',
                'photo_path' => 'nullable|string',
                'status' => 'sometimes|in:active,inactive,blacklisted',
            ]);
        } catch (ValidationException $e) {
            return $this->validationErrorResponse($e);
        }

        $member->update($data);

        return $this->successResponse($member, 'Member updated successfully');
    }

    public function destroy(int $id)
    {
        $member = KikobaMember::where('company_id', $this->getCompanyId())->find($id);

        if (! $member) {
            return $this->errorResponse('Member not found', 404);
        }

        if ($member->groupMemberships()->where('status', 'active')->exists()) {
            return $this->errorResponse('Cannot delete a member with active group memberships', 422);
        }

        $member->delete();

        return $this->successResponse(null, 'Member deleted successfully');
    }
}
