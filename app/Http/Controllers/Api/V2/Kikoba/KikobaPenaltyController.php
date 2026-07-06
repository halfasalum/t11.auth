<?php

namespace App\Http\Controllers\Api\V2\Kikoba;

use App\Http\Controllers\Api\V2\BaseController;
use App\Models\KikobaPenalty;
use App\Services\Kikoba\KikobaPenaltyService;
use Illuminate\Http\Request;

class KikobaPenaltyController extends BaseController
{
    public function __construct(protected KikobaPenaltyService $penaltyService)
    {
    }

    public function index(Request $request)
    {
        $query = KikobaPenalty::query()
            ->whereHas('groupMember.group', fn ($q) => $q->where('company_id', $this->getCompanyId()))
            ->with(['groupMember.member', 'penaltyProduct.product', 'schedule']);

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        if ($request->filled('kikoba_group_id')) {
            $query->whereHas('groupMember', fn ($q) => $q->where('kikoba_group_id', $request->integer('kikoba_group_id')));
        }

        $penalties = $query->orderByDesc('issued_date')->paginate((int) $request->input('per_page', 20));

        return $this->successResponse($this->paginateResponse($penalties));
    }

    public function waive(int $id)
    {
        $penalty = $this->findPenalty($id);

        if (! $penalty) {
            return $this->errorResponse('Penalty not found', 404);
        }

        $penalty->update(['status' => 'waived']);

        return $this->successResponse($penalty, 'Penalty waived successfully');
    }

    public function markPaid(Request $request, int $id)
    {
        $penalty = $this->findPenalty($id);

        if (! $penalty) {
            return $this->errorResponse('Penalty not found', 404);
        }

        $penalty->update([
            'status' => 'paid',
            'paid_date' => $request->input('paid_date', now()->toDateString()),
        ]);

        return $this->successResponse($penalty, 'Penalty marked as paid');
    }

    /**
     * Manually trigger overdue detection for this company (in addition to
     * the daily scheduled command). Useful for on-demand checks.
     */
    public function runDetection()
    {
        $count = $this->penaltyService->detectAndApplyPenalties($this->getCompanyId());

        return $this->successResponse(['penalties_created' => $count], 'Penalty detection run completed');
    }

    protected function findPenalty(int $id): ?KikobaPenalty
    {
        return KikobaPenalty::whereHas(
            'groupMember.group',
            fn ($q) => $q->where('company_id', $this->getCompanyId())
        )->find($id);
    }
}
