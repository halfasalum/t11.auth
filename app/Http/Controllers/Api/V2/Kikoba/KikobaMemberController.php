<?php

namespace App\Http\Controllers\Api\V2\Kikoba;

use App\Http\Controllers\Api\V2\BaseController;
use App\Models\Customers;
use App\Models\KikobaMember;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class KikobaMemberController extends BaseController
{
    /**
     * Customers belonging to this company (via their zone assignment, same
     * scoping rule CustomerController uses) that have not already been
     * imported as a Kikoba member.
     */
    public function importableCustomers(Request $request)
    {
        $companyId = $this->getCompanyId();

        $alreadyImportedCustomerIds = KikobaMember::where('company_id', $companyId)
            ->whereNotNull('customer_id')
            ->pluck('customer_id');

        $query = Customers::query()
            ->whereHas('zoneAssignment', function ($q) use ($companyId) {
                $q->where('company_id', $companyId)
                    ->where('status', '!=', Customers::STATUS_DELETED)
                    ->where('status', '!=', Customers::STATUS_REJECTED);
            })
            ->whereNotIn('id', $alreadyImportedCustomerIds);

        if ($request->filled('search')) {
            $search = $request->string('search');
            $query->where(function ($q) use ($search) {
                $q->where('fullname', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")
                    ->orWhere('customer_phone', 'like', "%{$search}%")
                    ->orWhere('nida', 'like', "%{$search}%");
            });
        }

        $customers = $query->orderBy('fullname')
            ->paginate(
                (int) $request->input('per_page', 20),
                ['id', 'fullname', 'phone', 'customer_phone', 'email', 'nida', 'gender', 'date_of_birth', 'address']
            );

        return $this->successResponse($this->paginateResponse($customers));
    }

    /**
     * Create a Kikoba member for each selected customer (skipping any that
     * are already imported, or that don't belong to this company).
     */
    public function importFromCustomers(Request $request)
    {
        try {
            $data = $request->validate([
                'customer_ids' => 'required|array|min:1',
                'customer_ids.*' => 'integer|exists:customers,id',
            ]);
        } catch (ValidationException $e) {
            return $this->validationErrorResponse($e);
        }

        $companyId = $this->getCompanyId();
        $requestedIds = $data['customer_ids'];

        $companyCustomers = Customers::query()
            ->whereHas('zoneAssignment', function ($q) use ($companyId) {
                $q->where('company_id', $companyId)
                    ->where('status', '!=', Customers::STATUS_DELETED)
                    ->where('status', '!=', Customers::STATUS_REJECTED);
            })
            ->whereIn('id', $requestedIds)
            ->get()
            ->keyBy('id');

        $alreadyImported = KikobaMember::where('company_id', $companyId)
            ->whereIn('customer_id', $requestedIds)
            ->pluck('customer_id')
            ->all();

        $imported = [];
        $skipped = [];

        foreach ($requestedIds as $customerId) {
            if (! $companyCustomers->has($customerId)) {
                $skipped[] = ['customer_id' => $customerId, 'reason' => 'Customer not found for this company'];
                continue;
            }

            if (in_array($customerId, $alreadyImported, true)) {
                $skipped[] = ['customer_id' => $customerId, 'reason' => 'Already imported'];
                continue;
            }

            try {
                $member = KikobaMember::create(
                    $this->mapCustomerToMember($companyCustomers->get($customerId), $companyId)
                );
                $imported[] = $member;
            } catch (\Throwable $e) {
                $skipped[] = ['customer_id' => $customerId, 'reason' => 'Could not import (likely a duplicate ID number)'];
            }
        }

        $message = count($imported) . ' member(s) imported';
        if (count($skipped) > 0) {
            $message .= ', ' . count($skipped) . ' skipped';
        }

        return $this->successResponse([
            'imported' => $imported,
            'imported_count' => count($imported),
            'skipped' => $skipped,
        ], $message);
    }

    private function mapCustomerToMember(Customers $customer, int $companyId): array
    {
        $parts = preg_split('/\s+/', trim($customer->fullname), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $firstName = $parts[0] ?? $customer->fullname;
        $lastName = count($parts) > 1 ? end($parts) : $firstName;
        $middleName = count($parts) > 2 ? implode(' ', array_slice($parts, 1, -1)) : null;

        $genderMap = [
            Customers::GENDER_MALE => 'male',
            Customers::GENDER_FEMALE => 'female',
        ];

        return [
            'company_id' => $companyId,
            'customer_id' => $customer->id,
            'first_name' => $firstName,
            'middle_name' => $middleName,
            'last_name' => $lastName,
            'gender' => $genderMap[$customer->gender] ?? null,
            'date_of_birth' => $customer->date_of_birth,
            'phone' => $customer->phone ?: $customer->customer_phone,
            'email' => $customer->email,
            'address' => $customer->address,
            'id_type' => $customer->nida ? 'NIDA' : null,
            'id_number' => $customer->nida,
            'status' => 'active',
        ];
    }
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
