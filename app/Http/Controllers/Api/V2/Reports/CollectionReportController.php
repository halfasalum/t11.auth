<?php

namespace App\Http\Controllers\Api\V2\Reports;

use App\Http\Controllers\Api\V2\BaseController;
use App\Models\LoanPaymentSchedules;
use App\Models\PaymentSubmissions;
use App\Models\Loans;
use App\Models\Customers;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class CollectionReportController extends BaseController
{

    /**
     * Daily Collection Report with Payment Date Tracking
     */
    public function dailyCollection(Request $request)
    {
        try {
            $companyId = $this->getCompanyId();
            $date = $request->get('date', Carbon::today()->toDateString());

            // Get schedules due on this date
            $schedules = LoanPaymentSchedules::where('payment_due_date', $date)
                ->whereHas('loan', function ($q) use ($companyId) {
                    $q->where('company', $companyId);
                })
                ->with(['loan.loan_customer'])
                ->get();

            $expectedAmount = $schedules->sum('payment_total_amount');

            // Get ALL approved payments for these schedule IDs
            $scheduleIds = $schedules->pluck('id')->toArray();

            $submissions = PaymentSubmissions::whereIn('schedule_id', $scheduleIds)
                ->where('company', $companyId)
                ->where('submission_status', 11)
                ->get()
                ->keyBy(function ($item) {
                    return (int) $item->schedule_id;
                });

            // Also get payments that were submitted ON the due date (for on-time tracking)
            $onTimeSubmissions = PaymentSubmissions::whereIn('schedule_id', $scheduleIds)
                ->where('company', $companyId)
                ->where('submission_status', 11)
                ->whereDate('submitted_date', $date)
                ->get()
                ->keyBy(function ($item) {
                    return (int) $item->schedule_id;
                });

            $collectedAmount = $submissions->sum('amount');
            $onTimeCollectedAmount = $onTimeSubmissions->sum('amount');
            $collectionRate = $expectedAmount > 0 ? round(($collectedAmount / $expectedAmount) * 100, 2) : 0;
            $onTimeRate = $expectedAmount > 0 ? round(($onTimeCollectedAmount / $expectedAmount) * 100, 2) : 0;

            $loansData = [];
            $pendingCount = 0;
            $completedCount = 0;
            $partialCount = 0;
            $onTimeCount = 0;
            $lateCount = 0;

            foreach ($schedules as $schedule) {
                $payment = $submissions->get($schedule->id);
                $onTimePayment = $onTimeSubmissions->get($schedule->id);
                $collected = $payment ? (float) $payment->amount : 0;
                $dueAmount = (float) $schedule->payment_total_amount;

                // Determine payment timeliness
                $isOnTime = $onTimePayment !== null;
                $daysLate = 0;

                if ($payment && !$isOnTime) {
                    $paymentDate = Carbon::parse($payment->submitted_date);
                    $dueDate = Carbon::parse($date);
                    $daysLate = $dueDate->diffInDays($paymentDate);
                }

                if ($isOnTime) {
                    $onTimeCount++;
                } elseif ($payment && !$isOnTime) {
                    $lateCount++;
                }

                // Determine payment status
                if ($collected >= $dueAmount) {
                    $status = 'Paid';
                    $completedCount++;
                } elseif ($collected > 0) {
                    $status = 'Partial';
                    $partialCount++;
                } else {
                    $status = 'Pending';
                    $pendingCount++;
                }

                $loansData[] = [
                    'loan_number' => $schedule->loan_number,
                    'customer_name' => $schedule->loan->loan_customer->fullname ?? 'Unknown',
                    'expected' => $dueAmount,
                    'collected' => $collected,
                    'status' => $status,
                    'payment_date' => $payment ? $payment->submitted_date : null,
                    'is_on_time' => $isOnTime,
                    'days_late' => $daysLate,
                ];
            }

            $data = [
                'date' => $date,
                'expected_amount' => $expectedAmount,
                'collected_amount' => $collectedAmount,
                'on_time_collected_amount' => $onTimeCollectedAmount,
                'collection_rate' => $collectionRate,
                'on_time_rate' => $onTimeRate,
                'pending_count' => $pendingCount,
                'partial_count' => $partialCount,
                'completed_count' => $completedCount,
                'on_time_count' => $onTimeCount,
                'late_count' => $lateCount,
                'loans' => $loansData,
            ];

            return $this->successResponse($data, 'Daily collection retrieved successfully');
        } catch (\Exception $e) {
            Log::error('Daily collection error: ' . $e->getMessage(), [
                'date' => $date ?? null,
                'trace' => $e->getTraceAsString()
            ]);
            return $this->errorResponse('Failed to load daily collection: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Overdue Report
     */
    public function overdueReport(Request $request)
    {
        try {
            $companyId = $this->getCompanyId();

            $loans = Loans::where('company', $companyId)
                ->whereIn('status', [Loans::STATUS_ACTIVE, Loans::STATUS_OVERDUE])
                ->where('end_date', '<', Carbon::today())
                ->with(['loan_customer'])
                ->get();

            $overdueLoans = [];
            $totalOverdue = 0;
            $totalAmount = 0;
            $totalDays = 0;

            foreach ($loans as $loan) {
                $daysOverdue = Carbon::parse($loan->end_date)->diffInDays(now());
                $outstandingBalance = ($loan->total_loan + ($loan->penalty_amount ?? 0)) - ($loan->loan_paid ?? 0);

                // Get last payment
                $lastPayment = PaymentSubmissions::where('loan_number', $loan->loan_number)
                    ->where('submission_status', 11)
                    ->orderBy('submitted_date', 'desc')
                    ->first();

                $overdueLoans[] = [
                    'loan_number' => $loan->loan_number,
                    'customer_name' => $loan->loan_customer->fullname ?? 'Unknown',
                    'phone' => $loan->loan_customer->phone ?? 'N/A',
                    'principal_amount' => (float) $loan->principal_amount,
                    'outstanding_balance' => (float) $outstandingBalance,
                    'end_date' => $loan->end_date,
                    'days_overdue' => $daysOverdue,
                    'last_payment_date' => $lastPayment ? $lastPayment->submitted_date : null,
                    'last_payment_amount' => $lastPayment ? (float) $lastPayment->amount : 0,
                ];

                $totalOverdue++;
                $totalAmount += $outstandingBalance;
                $totalDays += $daysOverdue;
            }

            $data = [
                'loans' => $overdueLoans,
                'summary' => [
                    'total_overdue' => $totalOverdue,
                    'total_amount' => (float) $totalAmount,
                    'avg_days' => $totalOverdue > 0 ? round($totalDays / $totalOverdue, 2) : 0,
                ],
            ];

            return $this->successResponse($data, 'Overdue report retrieved successfully');
        } catch (\Exception $e) {
            return $this->errorResponse('Failed to load overdue report: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Reconciliation Report
     */
    public function reconciliationReport(Request $request)
    {
        try {
            $companyId = $this->getCompanyId();
            $startDate = $request->get('start_date', Carbon::now()->startOfMonth());
            $endDate = $request->get('end_date', Carbon::now());

            $schedules = LoanPaymentSchedules::whereBetween('payment_due_date', [$startDate, $endDate])
                ->whereHas('loan', function ($q) use ($companyId) {
                    $q->where('company', $companyId);
                })
                ->get();

            $totalExpected = $schedules->sum('payment_total_amount');

            $submissions = PaymentSubmissions::whereBetween('submitted_date', [$startDate, $endDate])
                ->where('company', $companyId)
                ->get();

            $totalSubmitted = $submissions->sum('amount');
            $totalApproved = $submissions->where('submission_status', 11)->sum('amount');
            $totalRejected = $submissions->where('submission_status', 9)->sum('amount');
            $pendingZone = $submissions->where('submission_status', 4)->sum('amount');
            $pendingBranch = $submissions->where('submission_status', 8)->sum('amount');

            $reconciliationRate = $totalExpected > 0 ? round(($totalApproved / $totalExpected) * 100, 2) : 0;

            // Find discrepancies
            $discrepancies = [];
            foreach ($schedules as $schedule) {
                $submission = $submissions->where('schedule_id', $schedule->id)->first();
                if ($submission && abs($submission->amount - $schedule->payment_total_amount) > 0.01) {
                    $discrepancies[] = [
                        'loan_number' => $schedule->loan_number,
                        'customer_name' => $schedule->loan->loan_customer->fullname ?? 'Unknown',
                        'expected_amount' => (float) $schedule->payment_total_amount,
                        'submitted_amount' => (float) $submission->amount,
                        'approved_amount' => $submission->submission_status == 11 ? (float) $submission->amount : 0,
                        'status' => $this->getStatusLabel($submission->submission_status),
                        'discrepancy' => (float) abs($submission->amount - $schedule->payment_total_amount),
                    ];
                }
            }

            $data = [
                'date' => Carbon::now()->toDateString(),
                'total_expected' => (float) $totalExpected,
                'total_submitted' => (float) $totalSubmitted,
                'total_approved' => (float) $totalApproved,
                'total_rejected' => (float) $totalRejected,
                'pending_zone' => (float) $pendingZone,
                'pending_branch' => (float) $pendingBranch,
                'reconciliation_rate' => $reconciliationRate,
                'discrepancies' => $discrepancies,
            ];

            return $this->successResponse($data, 'Reconciliation report retrieved successfully');
        } catch (\Exception $e) {
            return $this->errorResponse('Failed to load reconciliation report: ' . $e->getMessage(), 500);
        }
    }

    private function getStatusLabel($status)
    {
        $labels = [
            4 => 'Pending Zone Approval',
            8 => 'Pending Branch Approval',
            9 => 'Rejected',
            11 => 'Approved',
        ];
        return $labels[$status] ?? 'Unknown';
    }

    /**
     * Daily Target and Collection by Officer Report
     */
    public function dailyCollectionByOfficer(Request $request)
    {
        try {
            $companyId = $this->getCompanyId();
            $date = $request->get('date', Carbon::today()->toDateString());
            $zoneId = $request->get('zone_id');
            $branchId = $request->get('branch_id');

            // Get schedules due on this date
            $schedulesQuery = LoanPaymentSchedules::where('payment_due_date', $date)
                ->whereHas('loan', function ($q) use ($companyId) {
                    $q->where('company', $companyId);
                })
                ->with(['loan.registeredBy', 'loan.loan_customer']);

            if ($zoneId) {
                $schedulesQuery->where('zone', $zoneId);
            }

            if ($branchId) {
                $schedulesQuery->where('branch', $branchId);
            }

            $schedules = $schedulesQuery->get();

            // Get all approved payments for these schedules
            $scheduleIds = $schedules->pluck('id')->toArray();
            $payments = PaymentSubmissions::whereIn('schedule_id', $scheduleIds)
                ->where('company', $companyId)
                ->where('submission_status', 11)
                ->get()
                ->keyBy('schedule_id');

            // Group by officer (registered_by)
            $byOfficer = [];
            foreach ($schedules as $schedule) {
                $officerId = $schedule->loan->registered_by;
                $officer = User::find($officerId);
                $officerName = $officer->first_name . ' ' . $officer->last_name ?? 'Unknown';
                $zoneName = $schedule->loan->loan_zone->zone_name ?? 'Unknown';
                $branchName = $schedule->loan->loan_zone->zone_branch->branch_name ?? 'Unknown';

                $payment = $payments->get($schedule->id);
                $collected = $payment ? (float) $payment->amount : 0;
                $expected = (float) $schedule->payment_total_amount;

                $key = "officer_{$officerId}";
                if (!isset($byOfficer[$key])) {
                    $byOfficer[$key] = [
                        'officer_id' => $officerId,
                        'officer_name' => $officerName,
                        'zone' => $zoneName,
                        'branch' => $branchName,
                        'total_target' => 0,
                        'total_collected' => 0,
                        'collection_rate' => 0,
                        'customers_count' => 0,
                        'paid_count' => 0,
                        'partial_count' => 0,
                        'pending_count' => 0,
                        'details' => []
                    ];
                }

                $byOfficer[$key]['total_target'] += $expected;
                $byOfficer[$key]['total_collected'] += $collected;
                $byOfficer[$key]['customers_count']++;

                if ($collected >= $expected) {
                    $byOfficer[$key]['paid_count']++;
                } elseif ($collected > 0) {
                    $byOfficer[$key]['partial_count']++;
                } else {
                    $byOfficer[$key]['pending_count']++;
                }

                $byOfficer[$key]['details'][] = [
                    'loan_number' => $schedule->loan_number,
                    'customer_name' => $schedule->loan->loan_customer->fullname ?? 'Unknown',
                    'phone' => $schedule->loan->loan_customer->phone ?? '',
                    'expected' => $expected,
                    'collected' => $collected,
                    'status' => $collected >= $expected ? 'Paid' : ($collected > 0 ? 'Partial' : 'Pending'),
                    'payment_date' => $payment ? $payment->submitted_date : null,
                ];
            }

            // Calculate collection rates
            foreach ($byOfficer as &$officer) {
                $officer['collection_rate'] = $officer['total_target'] > 0
                    ? round(($officer['total_collected'] / $officer['total_target']) * 100, 2)
                    : 0;
            }

            // Sort by collection rate descending
            usort($byOfficer, function ($a, $b) {
                return $b['collection_rate'] <=> $a['collection_rate'];
            });

            // Summary totals
            $summary = [
                'total_target' => array_sum(array_column($byOfficer, 'total_target')),
                'total_collected' => array_sum(array_column($byOfficer, 'total_collected')),
                'total_customers' => array_sum(array_column($byOfficer, 'customers_count')),
                'total_paid' => array_sum(array_column($byOfficer, 'paid_count')),
                'total_partial' => array_sum(array_column($byOfficer, 'partial_count')),
                'total_pending' => array_sum(array_column($byOfficer, 'pending_count')),
                'overall_rate' => array_sum(array_column($byOfficer, 'total_target')) > 0
                    ? round((array_sum(array_column($byOfficer, 'total_collected')) / array_sum(array_column($byOfficer, 'total_target'))) * 100, 2)
                    : 0,
            ];

            $data = [
                'date' => $date,
                'by_officer' => array_values($byOfficer),
                'summary' => $summary,
                'filters' => [
                    'zone_id' => $zoneId,
                    'branch_id' => $branchId,
                ]
            ];

            return $this->successResponse($data, 'Daily collection by officer retrieved successfully');
        } catch (\Exception $e) {
            Log::error('Daily collection by officer error: ' . $e->getMessage());
            return $this->errorResponse('Failed to load daily collection by officer: ' . $e->getMessage(), 500);
        }
    }


    /**
     * Daily Target and Collection by Zone Report
     */
    public function dailyCollectionByZone(Request $request)
    {
        try {
            $companyId = $this->getCompanyId();
            $date = $request->get('date', Carbon::today()->toDateString());
            $branchId = $request->get('branch_id');

            // Get schedules due on this date
            $schedulesQuery = LoanPaymentSchedules::where('payment_due_date', $date)
                ->whereHas('loan', function ($q) use ($companyId) {
                    $q->where('company', $companyId);
                })
                ->with(['loan.loan_zone', 'loan.loan_customer']);

            if ($branchId) {
                $schedulesQuery->whereHas('loan.loan_zone', function ($q) use ($branchId) {
                    $q->where('branch', $branchId);
                });
            }

            $schedules = $schedulesQuery->get();

            // Get all approved payments
            $scheduleIds = $schedules->pluck('id')->toArray();
            $payments = PaymentSubmissions::whereIn('schedule_id', $scheduleIds)
                ->where('company', $companyId)
                ->where('submission_status', 11)
                ->get()
                ->keyBy('schedule_id');

            // Group by zone
            $byZone = [];
            foreach ($schedules as $schedule) {
                $zoneId = $schedule->zone;
                $zoneName = $schedule->loan->loan_zone->zone_name ?? 'Unknown';
                $branchName = $schedule->loan->loan_zone->zone_branch->branch_name ?? 'Unknown';

                $payment = $payments->get($schedule->id);
                $collected = $payment ? (float) $payment->amount : 0;
                $expected = (float) $schedule->payment_total_amount;

                $key = "zone_{$zoneId}";
                if (!isset($byZone[$key])) {
                    $byZone[$key] = [
                        'zone_id' => $zoneId,
                        'zone_name' => $zoneName,
                        'branch_name' => $branchName,
                        'total_target' => 0,
                        'total_collected' => 0,
                        'collection_rate' => 0,
                        'customers_count' => 0,
                        'paid_count' => 0,
                        'partial_count' => 0,
                        'pending_count' => 0,
                        'officers' => []
                    ];
                }

                $byZone[$key]['total_target'] += $expected;
                $byZone[$key]['total_collected'] += $collected;
                $byZone[$key]['customers_count']++;

                if ($collected >= $expected) {
                    $byZone[$key]['paid_count']++;
                } elseif ($collected > 0) {
                    $byZone[$key]['partial_count']++;
                } else {
                    $byZone[$key]['pending_count']++;
                }
            }

            // Calculate collection rates
            foreach ($byZone as &$zone) {
                $zone['collection_rate'] = $zone['total_target'] > 0
                    ? round(($zone['total_collected'] / $zone['total_target']) * 100, 2)
                    : 0;
            }

            // Sort by collection rate descending
            usort($byZone, function ($a, $b) {
                return $b['collection_rate'] <=> $a['collection_rate'];
            });

            $data = [
                'date' => $date,
                'by_zone' => array_values($byZone),
                'summary' => [
                    'total_target' => array_sum(array_column($byZone, 'total_target')),
                    'total_collected' => array_sum(array_column($byZone, 'total_collected')),
                    'overall_rate' => array_sum(array_column($byZone, 'total_target')) > 0
                        ? round((array_sum(array_column($byZone, 'total_collected')) / array_sum(array_column($byZone, 'total_target'))) * 100, 2)
                        : 0,
                ]
            ];

            return $this->successResponse($data, 'Daily collection by zone retrieved successfully');
        } catch (\Exception $e) {
            Log::error('Daily collection by zone error: ' . $e->getMessage());
            return $this->errorResponse('Failed to load daily collection by zone: ' . $e->getMessage(), 500);
        }
    }


    /**
     * Daily Target and Collection by Branch Report
     */
    public function dailyCollectionByBranch(Request $request)
    {
        try {
            $companyId = $this->getCompanyId();
            $date = $request->get('date', Carbon::today()->toDateString());

            // Get schedules due on this date
            $schedules = LoanPaymentSchedules::where('payment_due_date', $date)
                ->whereHas('loan', function ($q) use ($companyId) {
                    $q->where('company', $companyId);
                })
                ->with(['loan.loan_zone.branch', 'loan.loan_customer'])
                ->get();

            // Get all approved payments
            $scheduleIds = $schedules->pluck('id')->toArray();
            $payments = PaymentSubmissions::whereIn('schedule_id', $scheduleIds)
                ->where('company', $companyId)
                ->where('submission_status', 11)
                ->get()
                ->keyBy('schedule_id');

            // Group by branch
            $byBranch = [];
            foreach ($schedules as $schedule) {
                $branchId = $schedule->loan->loan_zone->branch->id ?? null;
                $branchName = $schedule->loan->loan_zone->branch->branch_name ?? 'Unknown';

                $payment = $payments->get($schedule->id);
                $collected = $payment ? (float) $payment->amount : 0;
                $expected = (float) $schedule->payment_total_amount;

                $key = "branch_{$branchId}";
                if (!isset($byBranch[$key])) {
                    $byBranch[$key] = [
                        'branch_id' => $branchId,
                        'branch_name' => $branchName,
                        'total_target' => 0,
                        'total_collected' => 0,
                        'collection_rate' => 0,
                        'customers_count' => 0,
                        'paid_count' => 0,
                        'partial_count' => 0,
                        'pending_count' => 0,
                        'zones' => []
                    ];
                }

                $byBranch[$key]['total_target'] += $expected;
                $byBranch[$key]['total_collected'] += $collected;
                $byBranch[$key]['customers_count']++;

                if ($collected >= $expected) {
                    $byBranch[$key]['paid_count']++;
                } elseif ($collected > 0) {
                    $byBranch[$key]['partial_count']++;
                } else {
                    $byBranch[$key]['pending_count']++;
                }
            }

            // Calculate collection rates
            foreach ($byBranch as &$branch) {
                $branch['collection_rate'] = $branch['total_target'] > 0
                    ? round(($branch['total_collected'] / $branch['total_target']) * 100, 2)
                    : 0;
            }

            // Sort by collection rate descending
            usort($byBranch, function ($a, $b) {
                return $b['collection_rate'] <=> $a['collection_rate'];
            });

            $data = [
                'date' => $date,
                'by_branch' => array_values($byBranch),
                'summary' => [
                    'total_target' => array_sum(array_column($byBranch, 'total_target')),
                    'total_collected' => array_sum(array_column($byBranch, 'total_collected')),
                    'overall_rate' => array_sum(array_column($byBranch, 'total_target')) > 0
                        ? round((array_sum(array_column($byBranch, 'total_collected')) / array_sum(array_column($byBranch, 'total_target'))) * 100, 2)
                        : 0,
                ]
            ];

            return $this->successResponse($data, 'Daily collection by branch retrieved successfully');
        } catch (\Exception $e) {
            Log::error('Daily collection by branch error: ' . $e->getMessage());
            return $this->errorResponse('Failed to load daily collection by branch: ' . $e->getMessage(), 500);
        }
    }


    /**
     * Collection Approval Status by Zone and Officer
     */
    public function collectionApprovalStatus(Request $request)
    {
        try {
            $companyId = $this->getCompanyId();
            $date = $request->get('date', Carbon::today()->toDateString());
            $zoneId = $request->get('zone_id');
            $status = $request->get('status'); // pending_zone, pending_branch, approved, rejected

            // Get submissions for the date
            $submissionsQuery = PaymentSubmissions::whereDate('submitted_date', $date)
                ->where('company', $companyId)
                ->with(['schedule.loan.loan_customer', 'schedule.loan.registeredBy', 'schedule.loan.loan_zone']);

            if ($zoneId) {
                $submissionsQuery->where('zone', $zoneId);
            }

            if ($status) {
                $statusMap = [
                    'pending_zone' => 4,
                    'pending_branch' => 8,
                    'approved' => 11,
                    'rejected' => 9,
                ];
                if (isset($statusMap[$status])) {
                    $submissionsQuery->where('submission_status', $statusMap[$status]);
                }
            }

            $submissions = $submissionsQuery->get();

            // Group by zone and then by officer
            $byZone = [];
            foreach ($submissions as $submission) {
                $zoneId = $submission->zone;
                $zoneName = $submission->schedule->loan->loan_zone->zone_name ?? 'Unknown';
                $officerId = $submission->schedule->loan->registered_by;
                $officer = User::find($officerId);
                $officerName = $officer->first_name . ' ' . $officer->last_name ?? 'Unknown';
                $branchName = $submission->schedule->loan->loan_zone->zone_branch->branch_name ?? 'Unknown';

                $statusLabel = match ($submission->submission_status) {
                    4 => 'Pending Zone Approval',
                    8 => 'Pending Branch Approval',
                    11 => 'Approved',
                    9 => 'Rejected',
                    default => 'Unknown'
                };

                $statusColor = match ($submission->submission_status) {
                    4 => 'warning',
                    8 => 'info',
                    11 => 'success',
                    9 => 'danger',
                    default => 'secondary'
                };

                if (!isset($byZone[$zoneId])) {
                    $byZone[$zoneId] = [
                        'zone_id' => $zoneId,
                        'zone_name' => $zoneName,
                        'branch_name' => $branchName,
                        'total_amount' => 0,
                        'total_count' => 0,
                        'by_officer' => [],
                        'by_status' => [
                            'pending_zone' => 0,
                            'pending_branch' => 0,
                            'approved' => 0,
                            'rejected' => 0,
                        ]
                    ];
                }

                $byZone[$zoneId]['total_amount'] += (float) $submission->amount;
                $byZone[$zoneId]['total_count']++;

                // Update status counts
                if ($submission->submission_status == 4) $byZone[$zoneId]['by_status']['pending_zone']++;
                if ($submission->submission_status == 8) $byZone[$zoneId]['by_status']['pending_branch']++;
                if ($submission->submission_status == 11) $byZone[$zoneId]['by_status']['approved']++;
                if ($submission->submission_status == 9) $byZone[$zoneId]['by_status']['rejected']++;

                // Group by officer within zone
                if (!isset($byZone[$zoneId]['by_officer'][$officerId])) {
                    $byZone[$zoneId]['by_officer'][$officerId] = [
                        'officer_id' => $officerId,
                        'officer_name' => $officerName,
                        'total_amount' => 0,
                        'total_count' => 0,
                        'status_breakdown' => [
                            'pending_zone' => 0,
                            'pending_branch' => 0,
                            'approved' => 0,
                            'rejected' => 0,
                        ],
                        'submissions' => []
                    ];
                }

                $byZone[$zoneId]['by_officer'][$officerId]['total_amount'] += (float) $submission->amount;
                $byZone[$zoneId]['by_officer'][$officerId]['total_count']++;

                if ($submission->submission_status == 4) $byZone[$zoneId]['by_officer'][$officerId]['status_breakdown']['pending_zone']++;
                if ($submission->submission_status == 8) $byZone[$zoneId]['by_officer'][$officerId]['status_breakdown']['pending_branch']++;
                if ($submission->submission_status == 11) $byZone[$zoneId]['by_officer'][$officerId]['status_breakdown']['approved']++;
                if ($submission->submission_status == 9) $byZone[$zoneId]['by_officer'][$officerId]['status_breakdown']['rejected']++;

                $byZone[$zoneId]['by_officer'][$officerId]['submissions'][] = [
                    'id' => $submission->id,
                    'loan_number' => $submission->loan_number,
                    'customer_name' => $submission->schedule->loan->loan_customer->fullname ?? 'Unknown',
                    'phone' => $submission->schedule->loan->loan_customer->phone ?? '',
                    'amount' => (float) $submission->amount,
                    'status' => $statusLabel,
                    'status_color' => $statusColor,
                    'submitted_date' => $submission->submitted_date,
                    'schedule_due_date' => $submission->schedule->payment_due_date ?? null,
                ];
            }

            // Convert nested arrays to indexed arrays
            foreach ($byZone as &$zone) {
                $zone['by_officer'] = array_values($zone['by_officer']);
                // Sort officers by amount descending
                usort($zone['by_officer'], function ($a, $b) {
                    return $b['total_amount'] <=> $a['total_amount'];
                });
            }

            $data = [
                'date' => $date,
                'by_zone' => array_values($byZone),
                'summary' => [
                    'total_submissions' => $submissions->count(),
                    'total_amount' => (float) $submissions->sum('amount'),
                    'pending_zone' => $submissions->where('submission_status', 4)->count(),
                    'pending_branch' => $submissions->where('submission_status', 8)->count(),
                    'approved' => $submissions->where('submission_status', 11)->count(),
                    'rejected' => $submissions->where('submission_status', 9)->count(),
                ]
            ];

            return $this->successResponse($data, 'Collection approval status retrieved successfully');
        } catch (\Exception $e) {
            Log::error('Collection approval status error: ' . $e->getMessage());
            return $this->errorResponse('Failed to load collection approval status: ' . $e->getMessage(), 500);
        }
    }
}
