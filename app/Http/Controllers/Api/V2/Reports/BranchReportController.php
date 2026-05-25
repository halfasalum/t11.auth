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
     * Branch Performance Report - Simplified & Focused
     */
    public function branchPerformance(Request $request)
    {
        try {
            $companyId = $this->getCompanyId();

            // Get date range from request
            $fromDate = $request->input('from_date');
            $toDate = $request->input('to_date');

            // Parse dates
            $startDate = $fromDate ? Carbon::parse($fromDate)->startOfDay() : null;
            $endDate = $toDate ? Carbon::parse($toDate)->endOfDay() : null;

            // If no dates provided, default to current month
            if (!$startDate && !$endDate) {
                $startDate = Carbon::now()->startOfMonth();
                $endDate = Carbon::now()->endOfDay();
            }

            $branches = BranchModel::where('company', $companyId)->get();
            $result = [];

            foreach ($branches as $branch) {
                // Get zones under this branch
                $zoneIds = Zone::where('branch', $branch->id)->pluck('id');

                if ($zoneIds->isEmpty()) {
                    continue;
                }

                // ========== STEP 1: Get ALL schedules due in the period ==========
                $schedulesQuery = DB::table('loan_payment_schedule')
                    ->whereIn('zone', $zoneIds)
                    ->where('status', 1); // Active schedules

                if ($startDate && $endDate) {
                    $schedulesQuery->whereBetween('payment_due_date', [$startDate, $endDate]);
                } elseif ($startDate) {
                    $schedulesQuery->where('payment_due_date', '>=', $startDate);
                } elseif ($endDate) {
                    $schedulesQuery->where('payment_due_date', '<=', $endDate);
                }

                $schedules = $schedulesQuery->get();

                // Get unique loan IDs from these schedules
                $loanIds = $schedules->pluck('loan_number')->unique()->filter()->values();

                // ========== STEP 2: Get loan details for these unique loans ==========
                $loans = [];
                $totalDisbursed = 0;
                $totalLoanInterest = 0;
                $activeLoansCount = 0;

                if ($loanIds->isNotEmpty()) {
                    $loansData = Loans::whereIn('loan_number', $loanIds)
                        ->where('company', $companyId)
                        ->get();

                    foreach ($loansData as $loan) {
                        $loans[$loan->loan_number] = $loan;
                        $totalDisbursed += $loan->principal_amount;
                        $totalLoanInterest += $loan->interest_amount;

                        // Check if loan is active (Active or Overdue status)
                        if (in_array($loan->status, [Loans::STATUS_ACTIVE, Loans::STATUS_OVERDUE])) {
                            $activeLoansCount++;
                        }
                    }
                }

                // ========== STEP 3: Expected target from schedules ==========
                $expectedPrincipal = $schedules->sum('payment_principal_amount');
                $expectedInterest = $schedules->sum('payment_interest_amount');
                $expectedTotal = $schedules->sum('payment_total_amount');

                // ========== STEP 4: Collections from expected schedules ==========
                $scheduleIds = $schedules->pluck('id')->filter()->values();

                $payments = PaymentSubmissions::whereIn('schedule_id', $scheduleIds)
                    ->where('submission_status', 11) // Approved/Completed
                    //->whereBetween('created_at', [$startDate, $endDate])
                    ->get();

                // Use paid_principal and paid_interest from payment submissions
                $collectedPrincipal = $payments->sum('paid_principal');
                $collectedInterest = $payments->sum('paid_interest');
                $collectedTotal = $payments->sum('amount');

                // ========== STEP 5: Collection efficiency ==========
                $collectionEfficiency = $expectedTotal > 0
                    ? round(($collectedTotal / $expectedTotal) * 100, 2)
                    : 0;

                $principalEfficiency = $expectedPrincipal > 0
                    ? round(($collectedPrincipal / $expectedPrincipal) * 100, 2)
                    : 0;

                $interestEfficiency = $expectedInterest > 0
                    ? round(($collectedInterest / $expectedInterest) * 100, 2)
                    : 0;

                // ========== STEP 6: Active customers (with loans in this period) ==========
                $customerIds = [];
                if ($loanIds->isNotEmpty()) {
                    $customerIds = Loans::whereIn('loan_number', $loanIds)
                        ->where('company', $companyId)
                        ->pluck('customer')
                        ->unique()
                        ->filter()
                        ->values()
                        ->toArray();
                }

                $activeCustomers = count($customerIds);

                $result[] = [
                    'branch_name' => $branch->branch_name,
                    'branch_id' => $branch->id,

                    // Loan Portfolio Metrics
                    'active_loans' => $activeLoansCount,
                    'active_customers' => $activeCustomers,
                    'total_disbursed' => (float) $totalDisbursed,
                    'total_interest' => (float) $totalLoanInterest,

                    // Expected Collection (Target)
                    'expected_principal' => (float) $expectedPrincipal,
                    'expected_interest' => (float) $expectedInterest,
                    'expected_total' => (float) $expectedTotal,

                    // Actual Collection
                    'collected_principal' => (float) $collectedPrincipal,
                    'collected_interest' => (float) $collectedInterest,
                    'collected_total' => (float) $collectedTotal,

                    // Collection Efficiencies
                    'principal_efficiency' => $principalEfficiency,
                    'interest_efficiency' => $interestEfficiency,
                    'collection_efficiency' => $collectionEfficiency,

                    // Additional Info
                    'total_schedules' => $schedules->count(),
                    'paid_schedules' => $payments->count(),
                    'period_start' => $startDate->toDateString(),
                    'period_end' => $endDate->toDateString(),
                ];
            }

            // Sort by collection efficiency
            usort($result, function ($a, $b) {
                return $b['collection_efficiency'] <=> $a['collection_efficiency'];
            });

            return $this->successResponse($result, 'Branch performance retrieved successfully');
        } catch (\Exception $e) {
            Log::error('Branch Performance Error: ' . $e->getMessage());
            return $this->errorResponse('Failed to load branch performance: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Zone Officer Performance Report - Simplified & Focused
     */
    public function zonePerformance(Request $request)
    {
        try {
            $companyId = $this->getCompanyId();
            $userBranches = $this->getUserBranches();

            // Get date range from request
            $fromDate = $request->input('from_date');
            $toDate = $request->input('to_date');

            // Parse dates
            $startDate = $fromDate ? Carbon::parse($fromDate)->startOfDay() : null;
            $endDate = $toDate ? Carbon::parse($toDate)->endOfDay() : null;

            // If no dates provided, default to current month
            if (!$startDate && !$endDate) {
                $startDate = Carbon::now()->startOfMonth();
                $endDate = Carbon::now()->endOfDay();
            }

            $query = Zone::whereHas('zone_branch', function ($q) use ($companyId, $userBranches) {
                $q->where('company', $companyId);
                if (!empty($userBranches)) {
                    $q->whereIn('id', $userBranches);
                }
            })->with(['zone_branch', 'zone_officers']);

            $zones = $query->get();
            $result = [];

            foreach ($zones as $zone) {
                // ========== STEP 1: Get ALL schedules due in the period for this zone ==========
                $schedulesQuery = DB::table('loan_payment_schedule')
                    ->where('zone', $zone->id)
                    ->where('status', 1); // Active schedules

                if ($startDate && $endDate) {
                    $schedulesQuery->whereBetween('payment_due_date', [$startDate, $endDate]);
                } elseif ($startDate) {
                    $schedulesQuery->where('payment_due_date', '>=', $startDate);
                } elseif ($endDate) {
                    $schedulesQuery->where('payment_due_date', '<=', $endDate);
                }

                $schedules = $schedulesQuery->get();

                // Get unique loan IDs from these schedules
                $loanIds = $schedules->pluck('loan_number')->unique()->filter()->values();

                // ========== STEP 2: Get loan details for these unique loans ==========
                $totalDisbursed = 0;
                $totalLoanInterest = 0;
                $activeLoansCount = 0;
                $customersSet = [];

                if ($loanIds->isNotEmpty()) {
                    $loansData = Loans::whereIn('loan_number', $loanIds)
                        ->where('company', $companyId)
                        ->get();

                    foreach ($loansData as $loan) {
                        $totalDisbursed += $loan->principal_amount;
                        $totalLoanInterest += $loan->interest_amount;

                        // Track unique customers
                        if ($loan->customer) {
                            $customersSet[$loan->customer] = true;
                        }

                        // Check if loan is active (Active or Overdue status)
                        if (in_array($loan->status, [Loans::STATUS_ACTIVE, Loans::STATUS_OVERDUE])) {
                            $activeLoansCount++;
                        }
                    }
                }

                $activeCustomers = count($customersSet);

                // ========== STEP 3: Expected target from schedules ==========
                $expectedPrincipal = $schedules->sum('principal_amount');
                $expectedInterest = $schedules->sum('interest_amount');
                $expectedTotal = $schedules->sum('payment_total_amount');

                // ========== STEP 4: Collections from expected schedules ==========
                $scheduleIds = $schedules->pluck('id')->filter()->values();

                $payments = PaymentSubmissions::whereIn('schedule_id', $scheduleIds)
                    ->where('submission_status', 11) // Approved/Completed
                    ->whereBetween('created_at', [$startDate, $endDate])
                    ->get();

                // Use paid_principal and paid_interest from payment submissions
                $collectedPrincipal = $payments->sum('paid_principal');
                $collectedInterest = $payments->sum('paid_interest');
                $collectedTotal = $payments->sum('amount');

                // ========== STEP 5: Collection efficiency ==========
                $collectionEfficiency = $expectedTotal > 0
                    ? round(($collectedTotal / $expectedTotal) * 100, 2)
                    : 0;

                $principalEfficiency = $expectedPrincipal > 0
                    ? round(($collectedPrincipal / $expectedPrincipal) * 100, 2)
                    : 0;

                $interestEfficiency = $expectedInterest > 0
                    ? round(($collectedInterest / $expectedInterest) * 100, 2)
                    : 0;

                // ========== STEP 6: Get officer names ==========
                $officers = $zone->zone_officers->map(fn($o) => $o->first_name . ' ' . $o->last_name)->join(', ');

                $result[] = [
                    'zone_name' => $zone->zone_name,
                    'zone_id' => $zone->id,
                    'branch_name' => $zone->zone_branch->branch_name ?? 'N/A',
                    'officer_name' => $officers ?: 'Not Assigned',

                    // Loan Portfolio Metrics
                    'active_loans' => $activeLoansCount,
                    'active_customers' => $activeCustomers,
                    'total_disbursed' => (float) $totalDisbursed,
                    'total_interest' => (float) $totalLoanInterest,

                    // Expected Collection (Target)
                    'expected_principal' => (float) $expectedPrincipal,
                    'expected_interest' => (float) $expectedInterest,
                    'expected_total' => (float) $expectedTotal,

                    // Actual Collection
                    'collected_principal' => (float) $collectedPrincipal,
                    'collected_interest' => (float) $collectedInterest,
                    'collected_total' => (float) $collectedTotal,

                    // Collection Efficiencies
                    'principal_efficiency' => $principalEfficiency,
                    'interest_efficiency' => $interestEfficiency,
                    'collection_efficiency' => $collectionEfficiency,

                    // Additional Info
                    'total_schedules' => $schedules->count(),
                    'paid_schedules' => $payments->count(),
                    'period_start' => $startDate->toDateString(),
                    'period_end' => $endDate->toDateString(),
                ];
            }

            // Sort by collection efficiency
            usort($result, function ($a, $b) {
                return $b['collection_efficiency'] <=> $a['collection_efficiency'];
            });

            return $this->successResponse($result, 'Zone performance retrieved successfully');
        } catch (\Exception $e) {
            Log::error('Zone Performance Error: ' . $e->getMessage());
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
