<?php

namespace App\Http\Controllers\Api\V2\Kikoba;

use App\Http\Controllers\Api\V2\BaseController;
use App\Models\KikobaFinancialYearCloseReport;
use App\Models\KikobaGroup;
use App\Models\KikobaGroupFinancialYear;
use App\Models\KikobaMemberProductYearSummary;
use App\Services\Kikoba\KikobaFinancialYearCloseReportService;
use App\Services\Kikoba\KikobaMemberProductSummaryService;
use Illuminate\Http\Request;
use InvalidArgumentException;

class KikobaReportController extends BaseController
{
    public function __construct(
        protected KikobaMemberProductSummaryService $summaryService,
        protected KikobaFinancialYearCloseReportService $closeReportService,
    ) {
    }

    // ---------------------------------------------------------------
    // Product-year summaries
    // ---------------------------------------------------------------

    /**
     * (Re)generate the per-member, per-product summary for this cycle.
     * Safe to call any time during the year for an up-to-date snapshot.
     */
    public function generateProductSummary(int $groupId, int $groupFinancialYearId)
    {
        $groupFinancialYear = $this->findGroupFinancialYear($groupId, $groupFinancialYearId);

        if (! $groupFinancialYear) {
            return $this->errorResponse('Financial year cycle not found', 404);
        }

        $summaries = $this->summaryService->generate($groupFinancialYear);

        return $this->successResponse($summaries, 'Product-year summary generated successfully');
    }

    public function productSummary(Request $request, int $groupId, int $groupFinancialYearId)
    {
        $groupFinancialYear = $this->findGroupFinancialYear($groupId, $groupFinancialYearId);

        if (! $groupFinancialYear) {
            return $this->errorResponse('Financial year cycle not found', 404);
        }

        $query = KikobaMemberProductYearSummary::where('group_financial_year_id', $groupFinancialYear->id)
            ->with(['groupMember.member', 'groupProduct.product']);

        if ($request->filled('kikoba_group_member_id')) {
            $query->where('kikoba_group_member_id', $request->integer('kikoba_group_member_id'));
        }

        if ($request->filled('product_type')) {
            $query->where('product_type', $request->string('product_type'));
        }

        $summaries = $query->get();

        return $this->successResponse($summaries);
    }

    // ---------------------------------------------------------------
    // Financial-year close reports
    // ---------------------------------------------------------------

    /**
     * Compute (or recompute) the close report — savings + profit payout —
     * for every active member in this cycle. Leaves reports in "draft"
     * status so they can be reviewed before finalizing.
     */
    public function generateCloseReport(int $groupId, int $groupFinancialYearId)
    {
        $groupFinancialYear = $this->findGroupFinancialYear($groupId, $groupFinancialYearId);

        if (! $groupFinancialYear) {
            return $this->errorResponse('Financial year cycle not found', 404);
        }

        try {
            $reports = $this->closeReportService->generate($groupFinancialYear, $this->getUserId());
        } catch (InvalidArgumentException $e) {
            return $this->errorResponse($e->getMessage(), 422);
        }

        return $this->successResponse($reports, 'Close report generated successfully');
    }

    public function closeReport(Request $request, int $groupId, int $groupFinancialYearId)
    {
        $groupFinancialYear = $this->findGroupFinancialYear($groupId, $groupFinancialYearId);

        if (! $groupFinancialYear) {
            return $this->errorResponse('Financial year cycle not found', 404);
        }

        $query = KikobaFinancialYearCloseReport::where('group_financial_year_id', $groupFinancialYear->id)
            ->with('groupMember.member');

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        $reports = $query->orderByDesc('total_payout')->get();

        return $this->successResponse([
            'reports' => $reports,
            'totals' => [
                'total_savings_amount' => round($reports->sum('total_savings_amount'), 2),
                'total_profit_amount' => round($reports->sum('profit_amount'), 2),
                'total_payout' => round($reports->sum('total_payout'), 2),
            ],
            'is_finalized' => $this->closeReportService->isFinalized($groupFinancialYear),
        ]);
    }

    
    /**
     * Lock in the draft reports for this cycle, e.g. once the committee has
     * reviewed and approved the payout figures.
     */
    public function finalizeCloseReport(int $groupId, int $groupFinancialYearId)
    {
        $groupFinancialYear = $this->findGroupFinancialYear($groupId, $groupFinancialYearId);

        if (! $groupFinancialYear) {
            return $this->errorResponse('Financial year cycle not found', 404);
        }

        $count = $this->closeReportService->finalize($groupFinancialYear, $this->getUserId());

        return $this->successResponse(['finalized_count' => $count], 'Close reports finalized successfully');
    }

    protected function findGroupFinancialYear(int $groupId, int $groupFinancialYearId): ?KikobaGroupFinancialYear
    {
        $group = KikobaGroup::where('company_id', $this->getCompanyId())->find($groupId);

        if (! $group) {
            return null;
        }

        return KikobaGroupFinancialYear::where('kikoba_group_id', $group->id)->find($groupFinancialYearId);
    }
}
