<?php

namespace App\Http\Controllers\Api\V2\Kikoba;

use App\Http\Controllers\Api\V2\BaseController;
use App\Models\KikobaContribution;
use App\Models\KikobaGroupMemberProduct;
use App\Services\Kikoba\KikobaContributionService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class KikobaContributionController extends BaseController
{
    public function __construct(protected KikobaContributionService $contributionService)
    {
    }

    public function index(Request $request)
    {
        $query = KikobaContribution::query()
            ->whereHas(
                'memberProduct.groupMember.group',
                fn ($q) => $q->where('company_id', $this->getCompanyId())
            )
            ->with(['memberProduct.groupMember.member', 'memberProduct.groupProduct.product', 'schedule']);

        if ($request->filled('kikoba_group_member_product_id')) {
            $query->where('kikoba_group_member_product_id', $request->integer('kikoba_group_member_product_id'));
        }

        if ($request->filled('kikoba_group_id')) {
            $query->whereHas(
                'memberProduct.groupMember',
                fn ($q) => $q->where('kikoba_group_id', $request->integer('kikoba_group_id'))
            );
        }

        if ($request->filled('from_date')) {
            $query->whereDate('paid_date', '>=', $request->date('from_date'));
        }

        if ($request->filled('to_date')) {
            $query->whereDate('paid_date', '<=', $request->date('to_date'));
        }

        $contributions = $query->orderByDesc('paid_date')->paginate((int) $request->input('per_page', 20));

        return $this->successResponse($this->paginateResponse($contributions));
    }

    public function store(Request $request)
    {
        try {
            $data = $request->validate([
                'kikoba_group_member_product_id' => 'required|integer|exists:kikoba_group_member_products,id',
                'kikoba_contribution_schedule_id' => 'nullable|integer|exists:kikoba_contribution_schedules,id',
                'amount' => 'required|numeric|min:0.01',
                'paid_date' => 'nullable|date',
                'reference' => 'nullable|string|max:100',
                'payment_method' => 'nullable|string|max:50',
                'notes' => 'nullable|string',
            ]);
        } catch (ValidationException $e) {
            return $this->validationErrorResponse($e);
        }

        $memberProduct = KikobaGroupMemberProduct::whereHas(
            'groupMember.group',
            fn ($q) => $q->where('company_id', $this->getCompanyId())
        )->find($data['kikoba_group_member_product_id']);

        if (! $memberProduct) {
            return $this->errorResponse('Product enrollment not found for this company', 404);
        }

        $data['received_by'] = $this->getUserId();

        try {
            $contribution = $this->contributionService->recordPayment($data);
        } catch (InvalidArgumentException $e) {
            return $this->errorResponse($e->getMessage(), 422);
        }

        return $this->successResponse(
            $contribution->load('schedule', 'memberProduct.groupProduct.product'),
            'Contribution recorded successfully',
            201
        );
    }

    public function show(int $id)
    {
        $contribution = KikobaContribution::whereHas(
            'memberProduct.groupMember.group',
            fn ($q) => $q->where('company_id', $this->getCompanyId())
        )->with(['memberProduct.groupMember.member', 'schedule', 'receiver'])->find($id);

        if (! $contribution) {
            return $this->errorResponse('Contribution not found', 404);
        }

        return $this->successResponse($contribution);
    }
}
