<?php

namespace App\Http\Controllers\Api\V2\Reports;

use App\Http\Controllers\Api\V2\BaseController;
use App\Models\BranchModel;
use App\Models\Zone;
use App\Models\Loans;
use App\Models\CustomersZone;
use App\Models\FundsAllocation;
use App\Models\PaymentSubmissions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class BranchReportController extends BaseController
{
    /**
     * Branch Performance Report
     */
    public function branchPerformance(Request $request)
    {
        try {
            $companyId = $this->getCompanyId();

            $branches = BranchModel::where('company', $companyId)->get();
            $result = [];

            foreach ($branches as $branch) {
                // Get zones under this branch
                $zoneIds = Zone::where('branch', $branch->id)->pluck('id');

                // Get customers in these zones
                $customersCount = CustomersZone::whereIn('zone_id', $zoneIds)
                    ->where('company_id', $companyId)
                    ->count();

                // Get loans in these zones
                $loans = Loans::whereIn('zone', $zoneIds)
                    ->where('company', $companyId)
                    ->get();

                $totalDisbursed = $loans->sum('principal_amount');
                $totalRepaid = $loans->sum('loan_paid');
                $outstandingBalance = $loans->sum(function ($loan) {
                    return ($loan->total_loan + ($loan->penalty_amount ?? 0)) - ($loan->loan_paid ?? 0);
                });

                $activeLoans = $loans->whereIn('status', [Loans::STATUS_ACTIVE, Loans::STATUS_OVERDUE])->count();

                // Calculate collection efficiency for current month
                $currentMonthStart = Carbon::now()->startOfMonth();
                $expectedCollection = DB::table('loan_payment_schedule')
                    ->whereIn('zone', $zoneIds)
                    ->whereBetween('payment_due_date', [$currentMonthStart, Carbon::now()])
                    ->sum('payment_total_amount');

                $actualCollection = PaymentSubmissions::whereIn('zone', $zoneIds)
                    ->where('company', $companyId)
                    ->where('submission_status', 11)
                    ->whereBetween('submitted_date', [$currentMonthStart, Carbon::now()])
                    ->sum('amount');

                $collectionEfficiency = $expectedCollection > 0 ? round(($actualCollection / $expectedCollection) * 100, 2) : 0;

                // Calculate default rate
                $defaultedLoans = $loans->where('status', Loans::STATUS_DEFAULTED)->count();
                $defaultRate = $loans->count() > 0 ? round(($defaultedLoans / $loans->count()) * 100, 2) : 0;

                $result[] = [
                    'branch_name' => $branch->branch_name,
                    'branch_id' => $branch->id,
                    'active_customers' => $customersCount,
                    'total_loans' => $loans->count(),
                    'active_loans' => $activeLoans,
                    'total_disbursed' => (float) $totalDisbursed,
                    'total_collected' => (float) $totalRepaid,
                    'outstanding_balance' => (float) $outstandingBalance,
                    'collection_efficiency' => $collectionEfficiency,
                    'default_rate' => $defaultRate,
                ];
            }

            return $this->successResponse($result, 'Branch performance retrieved successfully');
        } catch (\Exception $e) {
            return $this->errorResponse('Failed to load branch performance: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Zone Officer Performance Report
     */
    public function zonePerformance(Request $request)
    {
        try {
            $companyId = $this->getCompanyId();
            $userBranches = $this->getUserBranches();

            $query = Zone::whereHas('zone_branch', function ($q) use ($companyId, $userBranches) {
                $q->where('company', $companyId);
                if (!empty($userBranches) && !$this->isManager()) {
                    $q->whereIn('id', $userBranches);
                }
            })->with(['zone_branch', 'zone_officers']);

            $zones = $query->get();

            $result = [];

            foreach ($zones as $zone) {
                // Get customers in this zone
                $customersCount = CustomersZone::where('zone_id', $zone->id)
                    ->where('company_id', $companyId)
                    ->count();

                // Get loans in this zone
                $loans = Loans::where('zone', $zone->id)
                    ->where('company', $companyId)
                    ->get();

                $totalDisbursed = $loans->sum('principal_amount');
                $totalRepaid = $loans->sum('loan_paid');
                $outstandingBalance = $loans->sum(function ($loan) {
                    return ($loan->total_loan + ($loan->penalty_amount ?? 0)) - ($loan->loan_paid ?? 0);
                });

                $activeLoans = $loans->whereIn('status', [Loans::STATUS_ACTIVE, Loans::STATUS_OVERDUE])->count();

                // Calculate collection efficiency
                $currentMonthStart = Carbon::now()->startOfMonth();
                $expectedCollection = DB::table('loan_payment_schedule')
                    ->where('zone', $zone->id)
                    ->whereBetween('payment_due_date', [$currentMonthStart, Carbon::now()])
                    //add schedule status condition
                    ->sum('payment_total_amount');

                $actualCollection = PaymentSubmissions::where('zone', $zone->id)
                    ->where('company', $companyId)
                    ->where('submission_status', 11)
                    ->whereBetween('submitted_date', [$currentMonthStart, Carbon::now()])
                    ->sum('amount');

                $collectionEfficiency = $expectedCollection > 0 ? round(($actualCollection / $expectedCollection) * 100, 2) : 0;

                // Calculate default rate
                $defaultedLoans = $loans->where('status', Loans::STATUS_DEFAULTED)->count();
                $defaultRate = $loans->count() > 0 ? round(($defaultedLoans / $loans->count()) * 100, 2) : 0;
                //Log::info("Zone Branch : ", [$zone->zone_branch]);
                $officers = $zone->zone_officers->map(fn($o) => $o->first_name . ' ' . $o->last_name)->join(', ');

                $result[] = [
                    'zone_name' => $zone->zone_name,
                    'zone_id' => $zone->id,
                    'branch_name' => $zone->zone_branch->branch_name ?? 'N/A',
                    'officer_name' => $officers, // You can fetch from users if available
                    'total_customers' => $customersCount,
                    'total_loans' => $loans->count(),
                    'active_loans' => $activeLoans,
                    'total_disbursed' => (float) $totalDisbursed,
                    'total_collected' => (float) $totalRepaid,
                    'outstanding_balance' => (float) $outstandingBalance,
                    'collection_efficiency' => $collectionEfficiency,
                    'default_rate' => $defaultRate,
                ];
            }

            return $this->successResponse($result, 'Zone performance retrieved successfully');
        } catch (\Exception $e) {
            return $this->errorResponse('Failed to load zone performance: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Funds Allocation Report
     */
    public function fundsAllocation(Request $request)
    {
        try {
            $companyId = $this->getCompanyId();

            $allocations = FundsAllocation::where('company', $companyId)
                ->with('allocation_branch')
                ->get();

            $result = [];
            foreach ($allocations as $allocation) {
                // Get all zones under this branch
                $zoneIds = Zone::where('branch', $allocation->allocation_branch->id)
                    ->pluck('id')
                    ->toArray(); // Convert to array

                // If no zones found, skip or set utilized amount to 0
                if (empty($zoneIds)) {
                    $utilizedAmount = 0;
                } else {
                    // Calculate utilized amount (loans disbursed from this branch's zones)
                    $utilizedAmount = Loans::where('company', $companyId)
                        ->whereIn('zone', $zoneIds)  // Now using array, not Collection
                        ->sum('principal_amount');
                }

                $remainingBalance = $allocation->allocated_amount - $utilizedAmount;
                $utilizationRate = $allocation->allocated_amount > 0
                    ? round(($utilizedAmount / $allocation->allocated_amount) * 100, 2)
                    : 0;

                $result[] = [
                    'branch_id' => $allocation->allocation_branch->id,
                    'branch_name' => $allocation->allocation_branch->branch_name,
                    'allocated_amount' => (float) $allocation->allocated_amount,
                    'utilized_amount' => (float) $utilizedAmount,
                    'remaining_balance' => (float) max(0, $remainingBalance),
                    'utilization_rate' => $utilizationRate,
                    'last_allocation_date' => $allocation->created_at,
                ];
            }

            return $this->successResponse($result, 'Funds allocation retrieved successfully');
        } catch (\Exception $e) {
            Log::error('Funds allocation error: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
            return $this->errorResponse('Failed to load funds allocation: ' . $e->getMessage(), 500);
        }
    }
}
