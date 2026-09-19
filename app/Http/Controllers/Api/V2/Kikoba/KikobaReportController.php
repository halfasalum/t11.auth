<?php

namespace App\Http\Controllers\Api\V2\Kikoba;

use App\Http\Controllers\Api\V2\BaseController;
use App\Models\KikobaContribution;
use App\Models\KikobaFinancialYearCloseReport;
use App\Models\KikobaGroup;
use App\Models\KikobaGroupFinancialYear;
use App\Models\KikobaGroupMember;
use App\Models\KikobaLoan;
use App\Models\KikobaLoanRepayment;
use App\Models\KikobaMemberProductYearSummary;
use App\Models\KikobaPenalty;
use App\Services\Kikoba\KikobaFinancialYearCloseReportService;
use App\Services\Kikoba\KikobaMemberProductSummaryService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
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

    /**
     * Reverse finalizeCloseReport(): unlocks this cycle's reports back to
     * draft and gives back whatever loan interest this cycle had claimed,
     * so it becomes claimable again by a future close.
     */
    public function unlockCloseReport(int $groupId, int $groupFinancialYearId)
    {
        $groupFinancialYear = $this->findGroupFinancialYear($groupId, $groupFinancialYearId);

        if (! $groupFinancialYear) {
            return $this->errorResponse('Financial year cycle not found', 404);
        }

        $count = $this->closeReportService->unlock($groupFinancialYear, $this->getUserId());

        return $this->successResponse(['unlocked_count' => $count], 'Close reports unlocked successfully');
    }

    // ---------------------------------------------------------------
    // Member statement — every contribution, loan event, and penalty for
    // one member, in one chronological timeline.
    // ---------------------------------------------------------------

    public function memberStatement(Request $request, int $groupId, int $groupMemberId)
    {
        $group = KikobaGroup::where('company_id', $this->getCompanyId())->find($groupId);

        if (! $group) {
            return $this->errorResponse('Group not found', 404);
        }

        $groupMember = KikobaGroupMember::with('member')
            ->where('kikoba_group_id', $group->id)
            ->find($groupMemberId);

        if (! $groupMember) {
            return $this->errorResponse('Member not found in this group', 404);
        }

        $start = $request->input('start_date');
        $end = $request->input('end_date');

        $contributions = KikobaContribution::whereHas(
            'memberProduct',
            fn ($q) => $q->where('kikoba_group_member_id', $groupMember->id)
        )
            ->with('memberProduct.groupProduct.product')
            ->when($start, fn ($q) => $q->where('paid_date', '>=', $start))
            ->when($end, fn ($q) => $q->where('paid_date', '<=', $end))
            ->get()
            ->map(fn ($c) => [
                'date' => optional($c->paid_date)->toDateString(),
                'type' => 'contribution',
                'description' => ($c->memberProduct?->groupProduct?->product?->name ?? 'Contribution') . ' contribution',
                'amount' => (float) $c->amount,
            ]);

        $loans = KikobaLoan::where('kikoba_group_member_id', $groupMember->id)->get();

        $disbursements = $loans->filter(fn ($l) => $l->disbursed_at !== null)->map(fn ($l) => [
            'date' => $l->disbursed_at->toDateString(),
            'type' => 'loan_disbursement',
            'description' => "Loan disbursed — {$l->loan_number}",
            'amount' => (float) ($l->disbursement_amount ?? $l->approved_amount),
        ])->when($start, fn ($c) => $c->filter(fn ($e) => $e['date'] >= $start))
          ->when($end, fn ($c) => $c->filter(fn ($e) => $e['date'] <= $end));

        $repayments = KikobaLoanRepayment::whereIn('kikoba_loan_id', $loans->pluck('id'))
            ->when($start, fn ($q) => $q->where('paid_date', '>=', $start))
            ->when($end, fn ($q) => $q->where('paid_date', '<=', $end))
            ->get()
            ->map(fn ($r) => [
                'date' => optional($r->paid_date)->toDateString(),
                'type' => 'loan_repayment',
                'description' => 'Loan repayment',
                'amount' => (float) $r->amount,
            ]);

        $penalties = KikobaPenalty::where('kikoba_group_member_id', $groupMember->id)
            ->when($start, fn ($q) => $q->where('issued_date', '>=', $start))
            ->when($end, fn ($q) => $q->where('issued_date', '<=', $end))
            ->get()
            ->map(fn ($p) => [
                'date' => optional($p->issued_date)->toDateString(),
                'type' => 'penalty',
                'description' => $p->reason ?: 'Penalty issued',
                'amount' => (float) $p->amount,
                'status' => $p->status,
            ]);

        $timeline = collect()
            ->concat($contributions)
            ->concat($disbursements)
            ->concat($repayments)
            ->concat($penalties)
            ->sortBy('date')
            ->values();

        $member = $groupMember->member;

        return $this->successResponse([
            'group' => ['id' => $group->id, 'name' => $group->name],
            'member' => [
                'group_member_id' => $groupMember->id,
                'name' => trim(($member->first_name ?? '') . ' ' . ($member->last_name ?? '')),
                'member_no' => $member->member_no ?? null,
                'phone' => $member->phone ?? null,
                'joined_date' => optional($groupMember->joined_date)->toDateString(),
                'status' => $groupMember->status,
            ],
            'summary' => [
                'total_contributed' => round($contributions->sum('amount'), 2),
                'total_borrowed' => round($disbursements->sum('amount'), 2),
                'total_repaid' => round($repayments->sum('amount'), 2),
                'total_penalties' => round($penalties->sum('amount'), 2),
                'penalties_outstanding' => round($penalties->where('status', 'pending')->sum('amount'), 2),
            ],
            'timeline' => $timeline,
        ]);
    }

    // ---------------------------------------------------------------
    // Loan portfolio — every loan across the company, filterable.
    // ---------------------------------------------------------------

    public function loanPortfolio(Request $request)
    {
        $query = KikobaLoan::where('company_id', $this->getCompanyId())
            ->with(['group:id,name', 'groupMember.member', 'loanProduct:id,name', 'schedules']);

        if ($request->filled('kikoba_group_id')) {
            $query->where('kikoba_group_id', $request->integer('kikoba_group_id'));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }
        if ($request->filled('kikoba_loan_product_id')) {
            $query->where('kikoba_loan_product_id', $request->integer('kikoba_loan_product_id'));
        }
        if ($request->filled('start_date')) {
            $query->whereDate('created_at', '>=', $request->input('start_date'));
        }
        if ($request->filled('end_date')) {
            $query->whereDate('created_at', '<=', $request->input('end_date'));
        }

        $loans = $query->orderByDesc('created_at')->paginate((int) $request->input('per_page', 20));

        // Totals across the FULL filtered set, not just this page.
        $totalsQuery = clone $query;
        $all = $totalsQuery->get();

        return $this->successResponse([
            'loans' => $this->paginateResponse($loans),
            'totals' => [
                'count' => $all->count(),
                'total_requested' => round((float) $all->sum('requested_amount'), 2),
                'total_approved' => round((float) $all->sum('approved_amount'), 2),
                'total_disbursed' => round((float) $all->whereNotNull('disbursed_at')->sum('approved_amount'), 2),
                'total_outstanding' => round((float) $all->sum(fn ($l) => $l->balance ?? 0), 2),
            ],
        ]);
    }

    // ---------------------------------------------------------------
    // Contribution collection report — every contribution, filterable.
    // ---------------------------------------------------------------

    public function contributionReport(Request $request)
    {
        $companyId = $this->getCompanyId();

        $query = KikobaContribution::query()
            ->join('kikoba_group_member_products as gmp', 'gmp.id', '=', 'kikoba_contributions.group_member_product_id')
            ->join('kikoba_group_members as gm', 'gm.id', '=', 'gmp.kikoba_group_member_id')
            ->join('kikoba_groups as g', 'g.id', '=', 'gm.kikoba_group_id')
            ->join('kikoba_group_products as gp', 'gp.id', '=', 'gmp.kikoba_group_product_id')
            ->join('kikoba_products as p', 'p.id', '=', 'gp.kikoba_product_id')
            ->join('kikoba_members as m', 'm.id', '=', 'gm.kikoba_member_id')
            ->where('g.company_id', $companyId)
            ->whereNull('kikoba_contributions.deleted_at');

        if ($request->filled('kikoba_group_id')) {
            $query->where('g.id', $request->integer('kikoba_group_id'));
        }
        if ($request->filled('product_type')) {
            $query->where('p.product_type', $request->string('product_type'));
        }
        if ($request->filled('start_date')) {
            $query->where('kikoba_contributions.paid_date', '>=', $request->input('start_date'));
        }
        if ($request->filled('end_date')) {
            $query->where('kikoba_contributions.paid_date', '<=', $request->input('end_date'));
        }

        $rows = (clone $query)
            ->orderByDesc('kikoba_contributions.paid_date')
            ->select([
                'kikoba_contributions.id',
                'kikoba_contributions.amount',
                'kikoba_contributions.units',
                'kikoba_contributions.paid_date',
                'kikoba_contributions.payment_method',
                'kikoba_contributions.reference',
                DB::raw('TRIM(CONCAT(m.first_name, " ", COALESCE(m.middle_name, ""), " ", m.last_name)) as member_name'),
                'g.name as group_name',
                'p.name as product_name',
                'p.product_type as product_type',
            ])
            ->paginate((int) $request->input('per_page', 20));

        $totals = (clone $query)->selectRaw('COALESCE(SUM(kikoba_contributions.amount), 0) as total, COUNT(*) as count')->first();

        return $this->successResponse([
            'contributions' => $this->paginateResponse($rows),
            'totals' => [
                'count' => (int) $totals->count,
                'total_amount' => round((float) $totals->total, 2),
            ],
        ]);
    }

    // ---------------------------------------------------------------
    // Loan aging — overdue loan installments bucketed by days late.
    // ---------------------------------------------------------------

    public function loanAgingReport(Request $request)
    {
        $companyId = $this->getCompanyId();
        $today = Carbon::today()->toDateString();

        $query = DB::table('kikoba_loan_schedules as ls')
            ->join('kikoba_loans as l', 'l.id', '=', 'ls.kikoba_loan_id')
            ->join('kikoba_group_members as gm', 'gm.id', '=', 'l.kikoba_group_member_id')
            ->join('kikoba_members as m', 'm.id', '=', 'gm.kikoba_member_id')
            ->join('kikoba_groups as g', 'g.id', '=', 'l.kikoba_group_id')
            ->where('l.company_id', $companyId)
            ->where('l.status', 'active')
            ->where('ls.due_date', '<', $today)
            ->whereIn('ls.status', ['pending', 'partial', 'overdue']);

        if ($request->filled('kikoba_group_id')) {
            $query->where('l.kikoba_group_id', $request->integer('kikoba_group_id'));
        }

        $rows = (clone $query)
            ->selectRaw('
                l.id as loan_id, l.loan_number, l.kikoba_group_member_id,
                TRIM(CONCAT(m.first_name, " ", COALESCE(m.middle_name, ""), " ", m.last_name)) as member_name,
                m.phone as phone,
                g.name as group_name,
                (ls.total_amount - ls.paid_amount) as balance,
                DATEDIFF(?, ls.due_date) as days_overdue
            ', [$today])
            ->get();

        $buckets = ['0_30' => 0.0, '31_60' => 0.0, '61_90' => 0.0, 'over_90' => 0.0];
        $byLoan = [];

        foreach ($rows as $row) {
            $bucket = $row->days_overdue <= 30 ? '0_30' : ($row->days_overdue <= 60 ? '31_60' : ($row->days_overdue <= 90 ? '61_90' : 'over_90'));
            $buckets[$bucket] = round($buckets[$bucket] + (float) $row->balance, 2);

            if (! isset($byLoan[$row->loan_id])) {
                $byLoan[$row->loan_id] = [
                    'loan_id' => $row->loan_id,
                    'loan_number' => $row->loan_number,
                    'member_name' => $row->member_name,
                    'phone' => $row->phone,
                    'group_name' => $row->group_name,
                    'balance' => 0.0,
                    'max_days_overdue' => 0,
                    'bucket' => '0_30',
                ];
            }

            $byLoan[$row->loan_id]['balance'] = round($byLoan[$row->loan_id]['balance'] + (float) $row->balance, 2);
            if ($row->days_overdue > $byLoan[$row->loan_id]['max_days_overdue']) {
                $byLoan[$row->loan_id]['max_days_overdue'] = (int) $row->days_overdue;
                $byLoan[$row->loan_id]['bucket'] = $bucket;
            }
        }

        $loans = collect($byLoan)->values()->sortByDesc('balance')->values();

        return $this->successResponse([
            'buckets' => $buckets,
            'total_overdue' => round(array_sum($buckets), 2),
            'loans' => $loans,
        ]);
    }

    // ---------------------------------------------------------------
    // Income statement — interest + penalty + product income over a period.
    // ---------------------------------------------------------------

    public function incomeStatement(Request $request)
    {
        $companyId = $this->getCompanyId();
        $groupId = $request->filled('kikoba_group_id') ? $request->integer('kikoba_group_id') : null;
        $start = $request->input('start_date');
        $end = $request->input('end_date');

        $groupIds = $groupId
            ? [$groupId]
            : KikobaGroup::where('company_id', $companyId)->pluck('id')->all();

        // Upfront interest: the interest_income ledger leg, date-scoped by
        // when it was actually posted.
        $upfront = (float) DB::table('kikoba_account_transactions')
            ->whereIn('kikoba_group_id', $groupIds)
            ->where('source', 'interest_income')
            ->when($start, fn ($q) => $q->where('transaction_date', '>=', $start))
            ->when($end, fn ($q) => $q->where('transaction_date', '<=', $end))
            ->sum('amount');

        // Installment interest: the interest portion of repayments actually
        // paid in the period, proportional to each schedule row's split.
        $installment = (float) DB::table('kikoba_loan_repayments as r')
            ->join('kikoba_loan_schedules as ls', 'ls.id', '=', 'r.kikoba_loan_schedule_id')
            ->join('kikoba_loans as l', 'l.id', '=', 'r.kikoba_loan_id')
            ->whereIn('l.kikoba_group_id', $groupIds)
            ->where('ls.total_amount', '>', 0)
            ->when($start, fn ($q) => $q->where('r.paid_date', '>=', $start))
            ->when($end, fn ($q) => $q->where('r.paid_date', '<=', $end))
            ->selectRaw('COALESCE(SUM(r.amount * ls.interest_amount / ls.total_amount), 0) as v')
            ->value('v');

        // Penalty income actually collected in the period.
        $penaltyIncome = (float) DB::table('kikoba_penalties as pen')
            ->join('kikoba_group_members as gm', 'gm.id', '=', 'pen.kikoba_group_member_id')
            ->whereIn('gm.kikoba_group_id', $groupIds)
            ->where('pen.status', 'paid')
            ->when($start, fn ($q) => $q->where('pen.paid_date', '>=', $start))
            ->when($end, fn ($q) => $q->where('pen.paid_date', '<=', $end))
            ->sum('pen.amount');

        // Other "used_as_income" product contributions (e.g. registration
        // fees) collected in the period.
        $otherIncomeRows = DB::table('kikoba_contributions as c')
            ->join('kikoba_group_member_products as gmp', 'gmp.id', '=', 'c.group_member_product_id')
            ->join('kikoba_group_members as gm', 'gm.id', '=', 'gmp.kikoba_group_member_id')
            ->join('kikoba_group_products as gp', 'gp.id', '=', 'gmp.kikoba_group_product_id')
            ->join('kikoba_products as p', 'p.id', '=', 'gp.kikoba_product_id')
            ->whereIn('gm.kikoba_group_id', $groupIds)
            ->where('p.used_as_income', true)
            ->when($start, fn ($q) => $q->where('c.paid_date', '>=', $start))
            ->when($end, fn ($q) => $q->where('c.paid_date', '<=', $end))
            ->groupBy('p.id', 'p.name')
            ->selectRaw('p.name as product_name, COALESCE(SUM(c.amount), 0) as amount')
            ->get();

        $otherIncome = round((float) $otherIncomeRows->sum('amount'), 2);

        return $this->successResponse([
            'period' => ['start_date' => $start, 'end_date' => $end],
            'income' => [
                'loan_interest_upfront' => round($upfront, 2),
                'loan_interest_installment' => round($installment, 2),
                'loan_interest_total' => round($upfront + $installment, 2),
                'penalty_income' => round($penaltyIncome, 2),
                'other_product_income' => $otherIncome,
                'other_product_income_breakdown' => $otherIncomeRows->map(fn ($r) => [
                    'product_name' => $r->product_name,
                    'amount' => round((float) $r->amount, 2),
                ])->values(),
            ],
            'total_income' => round($upfront + $installment + $penaltyIncome + $otherIncome, 2),
        ]);
    }

    // ---------------------------------------------------------------
    // Share register — every member's share holdings across groups.
    // ---------------------------------------------------------------

    public function shareRegister(Request $request)
    {
        $companyId = $this->getCompanyId();

        // gmp.units is only the enrollment-time value (typically 1) and
        // doesn't track what's actually been paid in since — current share
        // holdings are the running sum of contribution units against that
        // enrollment, same as KikobaGroupMemberController::index() computes
        // "share_units_contributed". A left join keeps a member who enrolled
        // but hasn't contributed yet on the register, at 0 units.
        $query = DB::table('kikoba_group_member_products as gmp')
            ->join('kikoba_group_members as gm', 'gm.id', '=', 'gmp.kikoba_group_member_id')
            ->join('kikoba_members as m', 'm.id', '=', 'gm.kikoba_member_id')
            ->join('kikoba_group_products as gp', 'gp.id', '=', 'gmp.kikoba_group_product_id')
            ->join('kikoba_products as p', 'p.id', '=', 'gp.kikoba_product_id')
            ->join('kikoba_groups as g', 'g.id', '=', 'gm.kikoba_group_id')
            ->leftJoin('kikoba_contributions as c', 'c.group_member_product_id', '=', 'gmp.id')
            ->where('g.company_id', $companyId)
            ->where('p.product_type', 'share')
            ->where('gm.status', 'active');

        if ($request->filled('kikoba_group_id')) {
            $query->where('g.id', $request->integer('kikoba_group_id'));
        }

        $rows = $query
            ->groupBy('gm.id', 'm.first_name', 'm.middle_name', 'm.last_name', 'm.member_no', 'g.id', 'g.name', 'gp.value_override', 'p.value')
            ->selectRaw('
                gm.id as kikoba_group_member_id,
                TRIM(CONCAT(m.first_name, " ", COALESCE(m.middle_name, ""), " ", m.last_name)) as member_name,
                m.member_no as member_no,
                g.id as group_id,
                g.name as group_name,
                COALESCE(SUM(c.units), 0) as units,
                COALESCE(gp.value_override, p.value) as unit_value
            ')
            ->orderBy('g.name')
            ->orderByDesc('units')
            ->get()
            ->map(fn ($r) => [
                'kikoba_group_member_id' => $r->kikoba_group_member_id,
                'member_name' => $r->member_name,
                'member_no' => $r->member_no,
                'group_id' => $r->group_id,
                'group_name' => $r->group_name,
                'units' => (int) $r->units,
                'unit_value' => (float) $r->unit_value,
                'share_value' => round((int) $r->units * (float) $r->unit_value, 2),
            ]);

        return $this->successResponse([
            'members' => $rows,
            'totals' => [
                'total_units' => $rows->sum('units'),
                'total_share_value' => round($rows->sum('share_value'), 2),
            ],
        ]);
    }

    // ---------------------------------------------------------------
    // Penalty report — every penalty, filterable.
    // ---------------------------------------------------------------

    public function penaltyReport(Request $request)
    {
        $companyId = $this->getCompanyId();

        $query = KikobaPenalty::query()
            ->join('kikoba_group_members as gm', 'gm.id', '=', 'kikoba_penalties.kikoba_group_member_id')
            ->join('kikoba_members as m', 'm.id', '=', 'gm.kikoba_member_id')
            ->join('kikoba_groups as g', 'g.id', '=', 'gm.kikoba_group_id')
            ->where('g.company_id', $companyId);

        if ($request->filled('kikoba_group_id')) {
            $query->where('g.id', $request->integer('kikoba_group_id'));
        }
        if ($request->filled('status')) {
            $query->where('kikoba_penalties.status', $request->string('status'));
        }
        if ($request->filled('start_date')) {
            $query->where('kikoba_penalties.issued_date', '>=', $request->input('start_date'));
        }
        if ($request->filled('end_date')) {
            $query->where('kikoba_penalties.issued_date', '<=', $request->input('end_date'));
        }

        $rows = (clone $query)
            ->orderByDesc('kikoba_penalties.issued_date')
            ->select([
                'kikoba_penalties.id',
                'kikoba_penalties.amount',
                'kikoba_penalties.issued_date',
                'kikoba_penalties.paid_date',
                'kikoba_penalties.status',
                'kikoba_penalties.reason',
                DB::raw('TRIM(CONCAT(m.first_name, " ", COALESCE(m.middle_name, ""), " ", m.last_name)) as member_name'),
                'g.name as group_name',
            ])
            ->paginate((int) $request->input('per_page', 20));

        $totalsRow = (clone $query)->selectRaw('
            COALESCE(SUM(CASE WHEN kikoba_penalties.status = "pending" THEN kikoba_penalties.amount ELSE 0 END), 0) as outstanding,
            COALESCE(SUM(CASE WHEN kikoba_penalties.status = "paid" THEN kikoba_penalties.amount ELSE 0 END), 0) as paid,
            COALESCE(SUM(CASE WHEN kikoba_penalties.status = "waived" THEN kikoba_penalties.amount ELSE 0 END), 0) as waived,
            COUNT(*) as count
        ')->first();

        return $this->successResponse([
            'penalties' => $this->paginateResponse($rows),
            'totals' => [
                'count' => (int) $totalsRow->count,
                'outstanding_amount' => round((float) $totalsRow->outstanding, 2),
                'paid_amount' => round((float) $totalsRow->paid, 2),
                'waived_amount' => round((float) $totalsRow->waived, 2),
            ],
        ]);
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
