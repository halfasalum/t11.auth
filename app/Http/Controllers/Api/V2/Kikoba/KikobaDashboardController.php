<?php

namespace App\Http\Controllers\Api\V2\Kikoba;

use App\Http\Controllers\Api\V2\BaseController;
use App\Models\KikobaGroup;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Analytical dashboard for the Kikoba module. Everything is company-scoped and
 * read-only. An optional ?group_id= narrows every figure to a single group.
 */
class KikobaDashboardController extends BaseController
{
    public function index(Request $request)
    {
        try {
            $companyId = $this->getCompanyId();
            $today = Carbon::today();

            $groups = KikobaGroup::where('company_id', $companyId)
                ->orderBy('name')
                ->get(['id', 'name', 'status']);

            $groupIds = $groups->pluck('id')->all();

            $selectedGroupId = $request->filled('group_id') ? (int) $request->input('group_id') : null;
            if ($selectedGroupId && ! in_array($selectedGroupId, $groupIds, true)) {
                $selectedGroupId = null;
            }

            // The set of group ids every query is restricted to.
            $scopeIds = $selectedGroupId ? [$selectedGroupId] : $groupIds;

            if (empty($scopeIds)) {
                return $this->successResponse($this->emptyPayload($groups));
            }

            $summary       = $this->summary($scopeIds, $today);
            $trend         = $this->collectionTrend($scopeIds, $today);
            $byType        = $this->productTypeBreakdown($scopeIds, $today);
            $groupPerf     = $this->groupPerformance($selectedGroupId ? [$selectedGroupId] : $groupIds, $today);
            $atRisk        = $this->atRiskMembers($scopeIds, $today);
            $upcoming      = $this->upcomingDues($scopeIds, $today);
            $recent        = $this->recentContributions($scopeIds);
            $penalties     = $this->penalties($scopeIds);
            $membersGrowth = $this->membersGrowth($scopeIds, $today);
            $cycles        = $this->financialYearStatus($companyId, $scopeIds, $today);

            $insights = $this->buildInsights($summary, $trend, $groupPerf, $atRisk, $penalties, $upcoming, $cycles, $membersGrowth);

            return $this->successResponse([
                'filters' => [
                    'groups' => $groups->map(fn ($g) => ['id' => $g->id, 'name' => $g->name])->values(),
                    'selected_group_id' => $selectedGroupId,
                    'generated_at' => now()->toIso8601String(),
                ],
                'summary' => $summary,
                'collection_trend' => $trend,
                'product_type_breakdown' => $byType,
                'group_performance' => $groupPerf,
                'at_risk_members' => $atRisk,
                'upcoming_dues' => $upcoming,
                'recent_contributions' => $recent,
                'penalties' => $penalties,
                'members_growth' => $membersGrowth,
                'financial_year_status' => $cycles,
                'insights' => $insights,
            ]);
        } catch (\Throwable $e) {
            Log::error('Kikoba dashboard error: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);

            return $this->errorResponse('Failed to load Kikoba dashboard: ' . $e->getMessage(), 500);
        }
    }

    /* ───────────────────────────────────────────────────────────────────── */
    /*  Query helpers                                                         */
    /* ───────────────────────────────────────────────────────────────────── */

    /**
     * Base query over contribution schedules, joined all the way up to the
     * owning group and restricted to $groupIds.
     */
    private function scheduleQuery(array $groupIds)
    {
        return DB::table('kikoba_contribution_schedules as s')
            ->join('kikoba_group_member_products as gmp', 'gmp.id', '=', 's.group_member_product_id')
            ->join('kikoba_group_members as gm', 'gm.id', '=', 'gmp.kikoba_group_member_id')
            ->join('kikoba_groups as g', 'g.id', '=', 'gm.kikoba_group_id')
            ->whereNull('gm.deleted_at')
            ->whereNull('g.deleted_at')
            ->whereIn('gm.kikoba_group_id', $groupIds);
    }

    private function contributionQuery(array $groupIds)
    {
        return DB::table('kikoba_contributions as c')
            ->join('kikoba_group_member_products as gmp', 'gmp.id', '=', 'c.group_member_product_id')
            ->join('kikoba_group_members as gm', 'gm.id', '=', 'gmp.kikoba_group_member_id')
            ->join('kikoba_groups as g', 'g.id', '=', 'gm.kikoba_group_id')
            ->whereNull('c.deleted_at')
            ->whereNull('gm.deleted_at')
            ->whereNull('g.deleted_at')
            ->whereIn('gm.kikoba_group_id', $groupIds);
    }

    private function summary(array $groupIds, Carbon $today): array
    {
        $t = $today->toDateString();

        $sched = $this->scheduleQuery($groupIds)
            ->selectRaw('
                COALESCE(SUM(s.expected_amount), 0) as total_expected,
                COALESCE(SUM(s.paid_amount), 0) as total_paid,
                COALESCE(SUM(CASE WHEN s.due_date <= ? THEN s.expected_amount ELSE 0 END), 0) as due_expected,
                COALESCE(SUM(CASE WHEN s.due_date <= ? THEN LEAST(s.paid_amount, s.expected_amount) ELSE 0 END), 0) as due_collected,
                COALESCE(SUM(CASE WHEN s.due_date < ? AND s.status IN ("pending","partial","overdue")
                    THEN s.expected_amount - s.paid_amount ELSE 0 END), 0) as overdue_amount,
                SUM(CASE WHEN s.due_date < ? AND s.status IN ("pending","partial","overdue") THEN 1 ELSE 0 END) as overdue_schedules,
                COUNT(*) as total_schedules,
                SUM(CASE WHEN s.status = "paid" THEN 1 ELSE 0 END) as paid_schedules
            ', [$t, $t, $t, $t])
            ->first();

        $dueExpected = (float) $sched->due_expected;
        $dueCollected = (float) $sched->due_collected;

        $totalContributed = (float) $this->contributionQuery($groupIds)->sum('c.amount');

        // Member counts
        $memberStats = DB::table('kikoba_group_members as gm')
            ->join('kikoba_members as m', 'm.id', '=', 'gm.kikoba_member_id')
            ->whereNull('gm.deleted_at')
            ->whereNull('m.deleted_at')
            ->whereIn('gm.kikoba_group_id', $groupIds)
            ->selectRaw('
                COUNT(DISTINCT gm.kikoba_member_id) as total_members,
                COUNT(DISTINCT CASE WHEN gm.status = "active" THEN gm.kikoba_member_id END) as active_members,
                COUNT(DISTINCT CASE WHEN gm.joined_date >= ? THEN gm.kikoba_member_id END) as new_members_month
            ', [$today->copy()->startOfMonth()->toDateString()])
            ->first();

        $groupCounts = DB::table('kikoba_groups')
            ->whereNull('deleted_at')
            ->whereIn('id', $groupIds)
            ->selectRaw('COUNT(*) as total, SUM(CASE WHEN status = "active" THEN 1 ELSE 0 END) as active')
            ->first();

        $collectionRate = $dueExpected > 0 ? round(($dueCollected / $dueExpected) * 100, 1) : 0.0;

        return [
            'total_groups' => (int) $groupCounts->total,
            'active_groups' => (int) $groupCounts->active,
            'total_members' => (int) $memberStats->total_members,
            'active_members' => (int) $memberStats->active_members,
            'new_members_this_month' => (int) $memberStats->new_members_month,

            'total_expected' => round((float) $sched->total_expected, 2),
            'total_contributed' => round($totalContributed, 2),
            'due_expected' => round($dueExpected, 2),
            'due_collected' => round($dueCollected, 2),
            'collection_rate' => $collectionRate,
            'outstanding_amount' => round(max($dueExpected - $dueCollected, 0), 2),
            'overdue_amount' => round((float) $sched->overdue_amount, 2),
            'overdue_schedules' => (int) $sched->overdue_schedules,

            'total_schedules' => (int) $sched->total_schedules,
            'paid_schedules' => (int) $sched->paid_schedules,
        ];
    }

    private function collectionTrend(array $groupIds, Carbon $today): array
    {
        $start = $today->copy()->startOfMonth()->subMonths(5);

        $rows = $this->scheduleQuery($groupIds)
            ->where('s.due_date', '>=', $start->toDateString())
            ->where('s.due_date', '<=', $today->copy()->endOfMonth()->toDateString())
            ->groupByRaw("DATE_FORMAT(s.due_date, '%Y-%m')")
            ->selectRaw("
                DATE_FORMAT(s.due_date, '%Y-%m') as ym,
                COALESCE(SUM(s.expected_amount), 0) as expected,
                COALESCE(SUM(LEAST(s.paid_amount, s.expected_amount)), 0) as collected
            ")
            ->pluck('collected', 'ym');

        $expectedRows = $this->scheduleQuery($groupIds)
            ->where('s.due_date', '>=', $start->toDateString())
            ->where('s.due_date', '<=', $today->copy()->endOfMonth()->toDateString())
            ->groupByRaw("DATE_FORMAT(s.due_date, '%Y-%m')")
            ->selectRaw("DATE_FORMAT(s.due_date, '%Y-%m') as ym, COALESCE(SUM(s.expected_amount),0) as expected")
            ->pluck('expected', 'ym');

        $out = [];
        for ($i = 0; $i < 6; $i++) {
            $m = $start->copy()->addMonths($i);
            $key = $m->format('Y-m');
            $expected = round((float) ($expectedRows[$key] ?? 0), 2);
            $collected = round((float) ($rows[$key] ?? 0), 2);
            $out[] = [
                'month' => $m->format('M Y'),
                'expected' => $expected,
                'collected' => $collected,
                'rate' => $expected > 0 ? round(($collected / $expected) * 100, 1) : 0.0,
            ];
        }

        return $out;
    }

    private function productTypeBreakdown(array $groupIds, Carbon $today): array
    {
        $rows = $this->contributionQuery($groupIds)
            ->join('kikoba_group_products as gp', 'gp.id', '=', 'gmp.kikoba_group_product_id')
            ->join('kikoba_products as p', 'p.id', '=', 'gp.kikoba_product_id')
            ->whereNull('p.deleted_at')
            ->groupBy('p.product_type')
            ->selectRaw('p.product_type as type, COALESCE(SUM(c.amount), 0) as collected, COUNT(*) as payments')
            ->get()
            ->keyBy('type');

        $types = ['share', 'saving', 'penalty'];
        $out = [];
        foreach ($types as $type) {
            $r = $rows->get($type);
            $out[] = [
                'type' => $type,
                'collected' => round((float) ($r->collected ?? 0), 2),
                'payments' => (int) ($r->payments ?? 0),
            ];
        }

        return $out;
    }

    private function groupPerformance(array $groupIds, Carbon $today): array
    {
        if (empty($groupIds)) {
            return [];
        }

        $t = $today->toDateString();

        $sched = $this->scheduleQuery($groupIds)
            ->groupBy('gm.kikoba_group_id')
            ->selectRaw('
                gm.kikoba_group_id as group_id,
                COALESCE(SUM(CASE WHEN s.due_date <= ? THEN s.expected_amount ELSE 0 END), 0) as due_expected,
                COALESCE(SUM(CASE WHEN s.due_date <= ? THEN LEAST(s.paid_amount, s.expected_amount) ELSE 0 END), 0) as due_collected,
                COALESCE(SUM(CASE WHEN s.due_date < ? AND s.status IN ("pending","partial","overdue")
                    THEN s.expected_amount - s.paid_amount ELSE 0 END), 0) as overdue_amount
            ', [$t, $t, $t])
            ->get()
            ->keyBy('group_id');

        $members = DB::table('kikoba_group_members')
            ->whereNull('deleted_at')
            ->whereIn('kikoba_group_id', $groupIds)
            ->where('status', 'active')
            ->groupBy('kikoba_group_id')
            ->selectRaw('kikoba_group_id as group_id, COUNT(*) as members')
            ->pluck('members', 'group_id');

        $groups = KikobaGroup::whereIn('id', $groupIds)->orderBy('name')->get(['id', 'name', 'status']);

        $out = [];
        foreach ($groups as $g) {
            $s = $sched->get($g->id);
            $dueExpected = (float) ($s->due_expected ?? 0);
            $dueCollected = (float) ($s->due_collected ?? 0);
            $out[] = [
                'id' => $g->id,
                'name' => $g->name,
                'status' => $g->status,
                'members' => (int) ($members[$g->id] ?? 0),
                'due_expected' => round($dueExpected, 2),
                'due_collected' => round($dueCollected, 2),
                'collection_rate' => $dueExpected > 0 ? round(($dueCollected / $dueExpected) * 100, 1) : 0.0,
                'overdue_amount' => round((float) ($s->overdue_amount ?? 0), 2),
            ];
        }

        // Worst collection rate first — that's where attention is needed. Groups
        // with nothing due yet sink to the bottom rather than dominating the top.
        usort($out, function ($a, $b) {
            $aActive = $a['due_expected'] > 0 ? 0 : 1;
            $bActive = $b['due_expected'] > 0 ? 0 : 1;

            return [$aActive, $a['collection_rate']] <=> [$bActive, $b['collection_rate']];
        });

        return $out;
    }

    private function atRiskMembers(array $groupIds, Carbon $today): array
    {
        $t = $today->toDateString();

        $rows = $this->scheduleQuery($groupIds)
            ->join('kikoba_members as m', 'm.id', '=', 'gm.kikoba_member_id')
            ->join('kikoba_groups as g2', 'g2.id', '=', 'gm.kikoba_group_id')
            ->whereNull('m.deleted_at')
            ->where('s.due_date', '<', $t)
            ->whereIn('s.status', ['pending', 'partial', 'overdue'])
            ->groupBy('m.id', 'm.first_name', 'm.middle_name', 'm.last_name', 'm.phone', 'g2.name')
            ->selectRaw('
                m.id as member_id,
                TRIM(CONCAT(m.first_name, " ", COALESCE(m.middle_name, ""), " ", m.last_name)) as name,
                m.phone as phone,
                g2.name as group_name,
                COALESCE(SUM(s.expected_amount - s.paid_amount), 0) as overdue_amount,
                COUNT(*) as missed_count,
                MIN(s.due_date) as oldest_due_date
            ')
            ->orderByDesc('overdue_amount')
            ->limit(10)
            ->get();

        return $rows->map(fn ($r) => [
            'member_id' => (int) $r->member_id,
            'name' => $r->name,
            'phone' => $r->phone,
            'group_name' => $r->group_name,
            'overdue_amount' => round((float) $r->overdue_amount, 2),
            'missed_count' => (int) $r->missed_count,
            'oldest_due_date' => $r->oldest_due_date,
            'days_overdue' => $r->oldest_due_date ? (int) abs($today->diffInDays(Carbon::parse($r->oldest_due_date))) : 0,
        ])->all();
    }

    private function upcomingDues(array $groupIds, Carbon $today): array
    {
        $t = $today->toDateString();

        $row = $this->scheduleQuery($groupIds)
            ->whereIn('s.status', ['pending', 'partial'])
            ->where('s.due_date', '>=', $t)
            ->selectRaw('
                COALESCE(SUM(CASE WHEN s.due_date <= ? THEN s.expected_amount - s.paid_amount ELSE 0 END), 0) as next_7,
                COALESCE(SUM(CASE WHEN s.due_date <= ? THEN s.expected_amount - s.paid_amount ELSE 0 END), 0) as next_14,
                COALESCE(SUM(CASE WHEN s.due_date <= ? THEN s.expected_amount - s.paid_amount ELSE 0 END), 0) as next_30,
                SUM(CASE WHEN s.due_date <= ? THEN 1 ELSE 0 END) as schedules_30
            ', [
                $today->copy()->addDays(7)->toDateString(),
                $today->copy()->addDays(14)->toDateString(),
                $today->copy()->addDays(30)->toDateString(),
                $today->copy()->addDays(30)->toDateString(),
            ])
            ->first();

        return [
            'next_7_days' => round((float) $row->next_7, 2),
            'next_14_days' => round((float) $row->next_14, 2),
            'next_30_days' => round((float) $row->next_30, 2),
            'schedules_next_30_days' => (int) $row->schedules_30,
        ];
    }

    private function recentContributions(array $groupIds): array
    {
        $rows = $this->contributionQuery($groupIds)
            ->join('kikoba_members as m', 'm.id', '=', 'gm.kikoba_member_id')
            ->join('kikoba_group_products as gp', 'gp.id', '=', 'gmp.kikoba_group_product_id')
            ->join('kikoba_products as p', 'p.id', '=', 'gp.kikoba_product_id')
            ->orderByDesc('c.paid_date')
            ->orderByDesc('c.id')
            ->limit(10)
            ->selectRaw('
                c.id as id,
                TRIM(CONCAT(m.first_name, " ", COALESCE(m.middle_name, ""), " ", m.last_name)) as member_name,
                g.name as group_name,
                p.name as product_name,
                p.product_type as product_type,
                c.amount as amount,
                c.paid_date as paid_date
            ')
            ->get();

        return $rows->map(fn ($r) => [
            'id' => (int) $r->id,
            'member_name' => $r->member_name,
            'group_name' => $r->group_name,
            'product_name' => $r->product_name,
            'product_type' => $r->product_type,
            'amount' => round((float) $r->amount, 2),
            'paid_date' => $r->paid_date,
        ])->all();
    }

    private function penalties(array $groupIds): array
    {
        $row = DB::table('kikoba_penalties as pen')
            ->join('kikoba_group_members as gm', 'gm.id', '=', 'pen.kikoba_group_member_id')
            ->join('kikoba_groups as g', 'g.id', '=', 'gm.kikoba_group_id')
            ->whereNull('gm.deleted_at')
            ->whereNull('g.deleted_at')
            ->whereIn('gm.kikoba_group_id', $groupIds)
            ->selectRaw('
                COALESCE(SUM(CASE WHEN pen.status = "pending" THEN pen.amount ELSE 0 END), 0) as outstanding_amount,
                SUM(CASE WHEN pen.status = "pending" THEN 1 ELSE 0 END) as outstanding_count,
                COALESCE(SUM(CASE WHEN pen.status = "paid" THEN pen.amount ELSE 0 END), 0) as paid_amount,
                SUM(CASE WHEN pen.status = "waived" THEN 1 ELSE 0 END) as waived_count
            ')
            ->first();

        return [
            'outstanding_amount' => round((float) $row->outstanding_amount, 2),
            'outstanding_count' => (int) $row->outstanding_count,
            'paid_amount' => round((float) $row->paid_amount, 2),
            'waived_count' => (int) $row->waived_count,
        ];
    }

    private function membersGrowth(array $groupIds, Carbon $today): array
    {
        $start = $today->copy()->startOfMonth()->subMonths(5);

        $rows = DB::table('kikoba_group_members as gm')
            ->whereNull('gm.deleted_at')
            ->whereIn('gm.kikoba_group_id', $groupIds)
            ->where('gm.joined_date', '>=', $start->toDateString())
            ->groupByRaw("DATE_FORMAT(gm.joined_date, '%Y-%m')")
            ->selectRaw("DATE_FORMAT(gm.joined_date, '%Y-%m') as ym, COUNT(DISTINCT gm.kikoba_member_id) as joined")
            ->pluck('joined', 'ym');

        $out = [];
        for ($i = 0; $i < 6; $i++) {
            $m = $start->copy()->addMonths($i);
            $out[] = [
                'month' => $m->format('M Y'),
                'new_members' => (int) ($rows[$m->format('Y-m')] ?? 0),
            ];
        }

        return $out;
    }

    private function financialYearStatus(int $companyId, array $groupIds, Carbon $today): array
    {
        $activeCycles = DB::table('kikoba_group_financial_years as gfy')
            ->join('kikoba_financial_years as fy', 'fy.id', '=', 'gfy.kikoba_financial_year_id')
            ->join('kikoba_groups as g', 'g.id', '=', 'gfy.kikoba_group_id')
            ->whereNull('g.deleted_at')
            ->whereNull('fy.deleted_at')
            ->whereIn('gfy.kikoba_group_id', $groupIds)
            ->where('gfy.status', 'active')
            ->selectRaw('
                COUNT(*) as active_cycles,
                MIN(fy.end_date) as earliest_end_date
            ')
            ->first();

        $endingSoon = DB::table('kikoba_group_financial_years as gfy')
            ->join('kikoba_financial_years as fy', 'fy.id', '=', 'gfy.kikoba_financial_year_id')
            ->whereIn('gfy.kikoba_group_id', $groupIds)
            ->where('gfy.status', 'active')
            ->whereNotNull('fy.end_date')
            ->where('fy.end_date', '<=', $today->copy()->addDays(30)->toDateString())
            ->count();

        return [
            'active_cycles' => (int) $activeCycles->active_cycles,
            'earliest_end_date' => $activeCycles->earliest_end_date,
            'cycles_ending_soon' => (int) $endingSoon,
        ];
    }

    /* ───────────────────────────────────────────────────────────────────── */
    /*  Insight engine                                                        */
    /* ───────────────────────────────────────────────────────────────────── */

    private function buildInsights(array $summary, array $trend, array $groupPerf, array $atRisk, array $penalties, array $upcoming, array $cycles, array $membersGrowth): array
    {
        $insights = [];

        // Collection-rate momentum: compare the last two COMPLETED months (the
        // final trend entry is the current, still-in-progress month).
        $completed = array_slice($trend, 0, -1);
        $months = array_values(array_filter($completed, fn ($m) => $m['expected'] > 0));
        if (count($months) >= 2) {
            $curr = end($months);
            $prev = $months[count($months) - 2];
            $delta = round($curr['rate'] - $prev['rate'], 1);
            if ($delta <= -5) {
                $insights[] = $this->insight('warning', 'Collection rate is slipping',
                    "Collection rate fell {$this->abs($delta)} points, from {$prev['rate']}% ({$prev['month']}) to {$curr['rate']}% ({$curr['month']}). Review the groups falling behind.");
            } elseif ($delta >= 5) {
                $insights[] = $this->insight('success', 'Collections are improving',
                    "Collection rate rose {$delta} points, from {$prev['rate']}% ({$prev['month']}) to {$curr['rate']}% ({$curr['month']}). Keep the momentum going.");
            }
        }

        if ($summary['collection_rate'] > 0 && $summary['collection_rate'] < 60) {
            $insights[] = $this->insight('warning', 'Overall collection rate is low',
                "Only {$summary['collection_rate']}% of amounts due so far have been collected. " .
                $this->money($summary['outstanding_amount']) . ' is still outstanding.');
        } elseif ($summary['collection_rate'] >= 90) {
            $insights[] = $this->insight('success', 'Strong collection performance',
                "{$summary['collection_rate']}% of amounts due have been collected across the selection.");
        }

        // Groups below target
        $weak = array_filter($groupPerf, fn ($g) => $g['due_expected'] > 0 && $g['collection_rate'] < 60);
        if (count($weak) > 0) {
            $names = implode(', ', array_map(fn ($g) => $g['name'], array_slice(array_values($weak), 0, 3)));
            $more = count($weak) > 3 ? ' and ' . (count($weak) - 3) . ' more' : '';
            $insights[] = $this->insight('warning', count($weak) . ' group(s) below 60% collection',
                "{$names}{$more} need follow-up on contributions.");
        }

        // Overdue concentration
        if ($summary['overdue_amount'] > 0) {
            $memberCount = count($atRisk);
            $oldest = $atRisk[0]['days_overdue'] ?? 0;
            $insights[] = $this->insight('danger', $this->money($summary['overdue_amount']) . ' in overdue contributions',
                "{$summary['overdue_schedules']} scheduled contributions are past due across roughly {$memberCount} member(s)" .
                ($oldest > 0 ? "; the oldest is {$oldest} days late." : '.'));
        }

        // Penalties
        if ($penalties['outstanding_count'] > 0) {
            $insights[] = $this->insight('warning', 'Unpaid penalties',
                "{$penalties['outstanding_count']} penalty(ies) worth " . $this->money($penalties['outstanding_amount']) . ' remain unpaid.');
        }

        // Upcoming cash expectation
        if ($upcoming['next_7_days'] > 0) {
            $insights[] = $this->insight('info', 'Contributions due this week',
                $this->money($upcoming['next_7_days']) . " is scheduled to come in over the next 7 days ({$upcoming['schedules_next_30_days']} schedules in 30 days).");
        }

        // Membership growth
        $newThisMonth = $summary['new_members_this_month'];
        if ($newThisMonth > 0) {
            $insights[] = $this->insight('success', "{$newThisMonth} new member(s) this month",
                'New enrolments were added this month. Make sure their contribution schedules have been generated.');
        }

        // Financial year closing
        if ($cycles['cycles_ending_soon'] > 0) {
            $insights[] = $this->insight('info', 'Financial year cycle ending soon',
                "{$cycles['cycles_ending_soon']} active cycle(s) end within 30 days" .
                ($cycles['earliest_end_date'] ? " (earliest {$cycles['earliest_end_date']})" : '') .
                '. Prepare close reports and payouts.');
        }

        if (empty($insights)) {
            $insights[] = $this->insight('success', 'Everything looks healthy',
                'No collection, penalty, or membership issues need attention right now.');
        }

        return $insights;
    }

    private function insight(string $level, string $title, string $message): array
    {
        return ['level' => $level, 'title' => $title, 'message' => $message];
    }

    private function money($value): string
    {
        return 'TZS ' . number_format((float) $value, 0);
    }

    private function abs($value): string
    {
        return (string) abs($value);
    }

    private function emptyPayload($groups): array
    {
        return [
            'filters' => [
                'groups' => $groups->map(fn ($g) => ['id' => $g->id, 'name' => $g->name])->values(),
                'selected_group_id' => null,
                'generated_at' => now()->toIso8601String(),
            ],
            'summary' => [
                'total_groups' => 0, 'active_groups' => 0, 'total_members' => 0, 'active_members' => 0,
                'new_members_this_month' => 0, 'total_expected' => 0, 'total_contributed' => 0,
                'due_expected' => 0, 'due_collected' => 0, 'collection_rate' => 0, 'outstanding_amount' => 0,
                'overdue_amount' => 0, 'overdue_schedules' => 0, 'total_schedules' => 0, 'paid_schedules' => 0,
            ],
            'collection_trend' => [],
            'product_type_breakdown' => [],
            'group_performance' => [],
            'at_risk_members' => [],
            'upcoming_dues' => [
                'next_7_days' => 0, 'next_14_days' => 0, 'next_30_days' => 0, 'schedules_next_30_days' => 0,
            ],
            'recent_contributions' => [],
            'penalties' => ['outstanding_amount' => 0, 'outstanding_count' => 0, 'paid_amount' => 0, 'waived_count' => 0],
            'members_growth' => [],
            'financial_year_status' => ['active_cycles' => 0, 'earliest_end_date' => null, 'cycles_ending_soon' => 0],
            'insights' => [[
                'level' => 'info',
                'title' => 'No groups yet',
                'message' => 'Create a Kikoba group and enroll members to start seeing analytics here.',
            ]],
        ];
    }
}
