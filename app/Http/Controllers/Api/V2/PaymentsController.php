<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\BankController;
use App\Http\Controllers\Controller;
use App\Models\Accounts;
use App\Models\Branch;
use App\Models\Customers;
use App\Models\CustomersZone;
use App\Models\LoanPaymentSchedules;
use App\Models\PaymentSubmissions;
use App\Models\Zone;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class PaymentsController extends BaseController
{
    public function yesterdayPayments(Request $request)
    {
        try {
            // =============================
            // 1️⃣ Auth & Context
            // =============================
            $companyId   = $this->getCompanyId();
            $user_zones  = (array) $this->getUserZones();

            if (empty($user_zones)) {
                return response()->json(['message' => 'User has no assigned zones'], 403);
            }

            $date        = now()->subDay()->toDateString();
            $perPage     = (int) $request->get('per_page', 10);
            $currentPage = (int) $request->get('page', 1);

            // =============================
            // 2️⃣ BASE QUERY (shared)
            // =============================
            $baseQuery = LoanPaymentSchedules::query()
                ->whereDate('loan_payment_schedule.payment_due_date', $date)
                ->where('loan_payment_schedule.status', 1)
                ->whereIn('loan_payment_schedule.zone', $user_zones)
                ->where('loan_payment_schedule.company', $companyId)
                ->join('loans', 'loans.loan_number', '=', 'loan_payment_schedule.loan_number')
                ->whereIn('loans.status', [5, 12])
                ->leftJoin(
                    'payment_submissions',
                    'payment_submissions.schedule_id',
                    '=',
                    'loan_payment_schedule.id'
                )
                ->leftJoin(
                    'accounts',
                    'accounts.id',
                    '=',
                    'payment_submissions.paid_account'
                );

            // =============================
            // 3️⃣ SUMMARY (NO PAGINATION)
            // =============================
            $summaryTotals = (clone $baseQuery)
                ->selectRaw('
                SUM(loan_payment_schedule.payment_total_amount) as total_target,
                SUM(COALESCE(payment_submissions.amount, 0)) as total_collections,
                SUM(
                    loan_payment_schedule.payment_total_amount
                    - COALESCE(payment_submissions.amount, 0)
                ) as total_balance
            ')
                ->first();

            // =============================
            // 4️⃣ PAGINATED LIST
            // =============================
            $paginatedSchedules = (clone $baseQuery)
                ->select([
                    'loan_payment_schedule.id',
                    'loan_payment_schedule.loan_number',
                    'loan_payment_schedule.payment_total_amount',
                    'loan_payment_schedule.branch',
                    'loan_payment_schedule.zone',
                    'loan_payment_schedule.payment_due_date',
                    'payment_submissions.amount as collected_amount',
                    'payment_submissions.paid_account',
                    'accounts.account_name',
                    'loans.customer',
                ])
                ->orderBy('payment_submissions.created_at', 'desc')
                ->paginate($perPage, ['*'], 'page', $currentPage);

            // =============================
            // 5️⃣ FETCH CUSTOMERS (OTHER DB)
            // =============================
            $customerIds = collect($paginatedSchedules->items())
                ->pluck('customer')
                ->unique()
                ->values();

            $customers = CustomersZone::query()
                ->join('customers', 'customers.id', '=', 'customers_zones.customer_id')
                ->whereIn('customers.id', $customerIds)
                ->select(
                    'customers.id as customer_id',
                    'customers.fullname',
                    'customers.customer_phone as phone',
                    'customers.customer_image as image'
                )
                ->get()
                ->keyBy('customer_id');

            // =============================
            // 6️⃣ MERGE DATA FOR UI
            // =============================
            $payments = collect($paginatedSchedules->items())->map(function ($schedule) use ($customers) {
                $customer = $customers->get($schedule->customer);

                $target    = (float) $schedule->payment_total_amount;
                $collected = (float) ($schedule->collected_amount ?? 0);

                return [
                    'collection_date' => $schedule->payment_due_date,
                    'schedule' => [
                        'id' => $schedule->id,
                        'loan_number' => $schedule->loan_number,
                        'target_amount' => $target,
                        'branch' => $schedule->branch,
                        'zone' => $schedule->zone,
                        'paid_account_id' => $schedule->paid_account,
                        'account_name' => $schedule->account_name ?? 'N/A',
                    ],
                    'customer' => $customer ? [
                        'id' => $customer->customer_id,
                        'fullname' => $customer->fullname,
                        'phone' => $customer->phone,
                        'image' => $customer->image,
                    ] : null,
                    'collected_amount' => $collected,
                    'balance' => $target - $collected,
                ];
            });



            // =============================
            // 8️⃣ RESPONSE
            // =============================
            return response()->json([
                'collection_date' => $date,
                'summary' => [
                    'total_target'      => (float) ($summaryTotals->total_target ?? 0),
                    'total_collections' => (float) ($summaryTotals->total_collections ?? 0),
                    'total_balance'     => (float) ($summaryTotals->total_balance ?? 0),
                ],
                'pagination' => [
                    'total' => $paginatedSchedules->total(),
                    'per_page' => $paginatedSchedules->perPage(),
                    'current_page' => $paginatedSchedules->currentPage(),
                    'last_page' => $paginatedSchedules->lastPage(),
                ],
                'payments' => $payments,
            ]);
        } catch (\Tymon\JWTAuth\Exceptions\JWTException $e) {
            return response()->json(['message' => 'Authentication error'], 401);
        } catch (\Throwable $e) {
            Log::error('Yesterday payments error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json(['message' => 'Failed to load payments'], 500);
        }
    }

    public function todayPayments(Request $request)
    {
        $user_company = $this->getCompanyId();
        $user_zones   = (array) $this->getUserZones();
        $date         = now()->toDateString();

        if (empty($user_zones)) {
            return response()->json([
                'date' => $date,
                'accounts' => [],
                'schedules' => [],
            ]);
        }

        // 1. Accounts (fixed duplicate get + DB filtering)
        $accounts = Accounts::select('id', 'account_name')
            ->where('company', $user_company)
            ->where('account_status', 1)
            ->get();

        // 2. Get all schedules for today in user zones (ONE QUERY)
        $schedules = LoanPaymentSchedules::query()
            ->whereDate('loan_payment_schedule.payment_due_date', $date)
            ->whereIn('loan_payment_schedule.zone', $user_zones)
            ->where('loan_payment_schedule.status', 1)
            ->whereIn('loans.status', [5, 12])
            ->leftJoin('loans', 'loans.loan_number', '=', 'loan_payment_schedule.loan_number')
            ->select(
                'loan_payment_schedule.id',
                'loan_payment_schedule.loan_number',
                'loan_payment_schedule.payment_total_amount',
                'loan_payment_schedule.branch',
                'loan_payment_schedule.zone',
                'loans.customer'
            )
            ->get();

        if ($schedules->isEmpty()) {
            return response()->json([
                'date' => $date,
                'accounts' => $accounts,
                'schedules' => [],
            ]);
        }

        // 3. Exclude already-paid schedules (DB-side filtering)
        $paidScheduleIds = PaymentSubmissions::whereIn(
            'schedule_id',
            $schedules->pluck('id')
        )
            ->whereIn('submission_status', [4, 8, 9, 11])
            ->pluck('schedule_id')
            ->toArray();

        $schedules = $schedules->whereNotIn('id', $paidScheduleIds);

        if ($schedules->isEmpty()) {
            return response()->json([
                'date' => $date,
                'accounts' => $accounts,
                'schedules' => [],
            ]);
        }

        // 4. Load customers in ONE query
        $customerIds = $schedules->pluck('customer')->unique();

        $customers = Customers::whereIn('id', $customerIds)
            ->get()
            ->keyBy('id');

        // 5. Merge schedules + customers efficiently
        $merged = $schedules->map(function ($schedule) use ($customers) {
            return [
                'schedule' => $schedule,
                'customer' => $customers->get($schedule->customer),
            ];
        })->values();

        return response()->json([
            'date' => $date,
            'accounts' => $accounts,
            'schedules' => $merged,
        ]);
    }

    public function dayPayments($date = null, Request $request)
    {
        $user_company = $this->getCompanyId();
        $user_zones   = (array) $this->getUserZones();
        $date         = $date ?? now()->toDateString();

        if (empty($user_zones)) {
            return response()->json([
                'date' => $date,
                'accounts' => [],
                'schedules' => [],
            ]);
        }

        // 1. Accounts (fixed duplicate get + DB filtering)
        $accounts = Accounts::select('id', 'account_name')
            ->where('company', $user_company)
            ->where('account_status', 1)
            ->get();

        // 2. Get all schedules for today in user zones (ONE QUERY)
        $schedules = LoanPaymentSchedules::query()
            ->whereDate('loan_payment_schedule.payment_due_date', $date)
            ->whereIn('loan_payment_schedule.zone', $user_zones)
            ->where('loan_payment_schedule.status', 1)
            ->whereIn('loans.status', [5, 12])
            ->leftJoin('loans', 'loans.loan_number', '=', 'loan_payment_schedule.loan_number')
            ->select(
                'loan_payment_schedule.id',
                'loan_payment_schedule.loan_number',
                'loan_payment_schedule.payment_total_amount',
                'loan_payment_schedule.branch',
                'loan_payment_schedule.zone',
                'loans.customer'
            )
            ->get();

        if ($schedules->isEmpty()) {
            return response()->json([
                'date' => $date,
                'accounts' => $accounts,
                'schedules' => [],
            ]);
        }

        // 3. Exclude already-paid schedules (DB-side filtering)
        $paidScheduleIds = PaymentSubmissions::whereIn(
            'schedule_id',
            $schedules->pluck('id')
        )
            ->whereIn('submission_status', [4, 8, 9, 11])
            ->pluck('schedule_id')
            ->toArray();

        $schedules = $schedules->whereNotIn('id', $paidScheduleIds);

        if ($schedules->isEmpty()) {
            return response()->json([
                'date' => $date,
                'accounts' => $accounts,
                'schedules' => [],
            ]);
        }

        // 4. Load customers in ONE query
        $customerIds = $schedules->pluck('customer')->unique();

        $customers = Customers::whereIn('id', $customerIds)
            ->get()
            ->keyBy('id');

        // 5. Merge schedules + customers efficiently
        $merged = $schedules->map(function ($schedule) use ($customers) {
            return [
                'schedule' => $schedule,
                'customer' => $customers->get($schedule->customer),
            ];
        })->values();

        return response()->json([
            'date' => $date,
            'accounts' => $accounts,
            'schedules' => $merged,
        ]);
    }

    public function zonePaymentApproval($user_branches = [], $perPage = 15, $page = 1)
    {
        if (empty($user_branches)) {
            return collect();
        }

        // Use a single query with subqueries instead of multiple queries
        $query = PaymentSubmissions::query()
            ->select([
                'payment_submissions.zone',
                'loan_payment_schedule.payment_due_date',
                DB::raw('MAX(payment_submissions.submitted_date) as submitted_date'),
                DB::raw('SUM(payment_submissions.amount) as total_paid'),
                DB::raw('SUM(loan_payment_schedule.payment_total_amount) as total_target')
            ])
            ->join('loan_payment_schedule', function ($join) {
                $join->on('loan_payment_schedule.id', '=', 'payment_submissions.schedule_id')
                    ->where('loan_payment_schedule.status', 1);
            })
            ->where('payment_submissions.submission_status', 4)
            ->whereIn('payment_submissions.branch', $user_branches)
            ->groupBy('payment_submissions.zone', 'loan_payment_schedule.payment_due_date')
            ->orderBy('loan_payment_schedule.payment_due_date', 'desc')
            ->orderBy('payment_submissions.zone');

        // Apply pagination
        $results = $query->paginate($perPage, ['*'], 'page', $page);

        if ($results->isEmpty()) {
            return $results;
        }

        // Get zone IDs from results
        $zoneIds = $results->pluck('zone')->unique()->filter()->values()->toArray();

        // Fetch zone names in a single query
        $zoneNames = Zone::whereIn('id', $zoneIds)
            ->orWhereIn('branch', $user_branches)
            ->get()
            ->keyBy('id');

        // Transform results with zone names
        $results->getCollection()->transform(function ($item) use ($zoneNames) {
            $zone = $zoneNames[$item->zone] ?? null;

            return [
                'zone' => $item->zone,
                'zone_name' => $zone->zone_name ?? null,
                'payment_date' => $item->payment_due_date,
                'submitted_date' => $item->submitted_date,
                'total_paid' => (float) $item->total_paid,
                'total_target' => (float) $item->total_target,
                'achievement_percentage' => $item->total_target > 0
                    ? round(($item->total_paid / $item->total_target) * 100, 2)
                    : 0,
            ];
        });

        return $results;
    }


    public function zonePaymentApprovalWithMetadata()
    {
        $user_branches = $this->getUserBranches();


        $perPage = 15;
        $page = 1;

        $result = $this->zonePaymentApproval($user_branches, $perPage, $page);

        return [
            'data' => $result->items(),
            'meta' => [
                'current_page' => $result->currentPage(),
                'last_page' => $result->lastPage(),
                'per_page' => $result->perPage(),
                'total' => $result->total(),
                'from' => $result->firstItem(),
                'to' => $result->lastItem(),
            ],
            'links' => [
                'first' => $result->url(1),
                'last' => $result->url($result->lastPage()),
                'prev' => $result->previousPageUrl(),
                'next' => $result->nextPageUrl(),
            ],
        ];
    }

    public function branchPaymentApproval(Request $request)
    {
        $user_company = $this->getCompanyId();

        // Get pagination parameters
        $perPage = $request->get('per_page', 15);
        $page = $request->get('page', 1);
        $sortBy = $request->get('sort_by', 'payment_due_date');
        $sortOrder = $request->get('sort_order', 'desc');

        // Single query to get all required data with joins
        $query = PaymentSubmissions::select([
            'payment_submissions.branch',
            'loan_payment_schedule.payment_due_date',
            DB::raw('SUM(payment_submissions.amount) as total_paid'),
            DB::raw('SUM(loan_payment_schedule.payment_total_amount) as total_target')
        ])
            ->join('loan_payment_schedule', 'loan_payment_schedule.id', '=', 'payment_submissions.schedule_id')
            ->where('payment_submissions.submission_status', 8)
            ->where('payment_submissions.company', $user_company)
            ->groupBy('payment_submissions.branch', 'loan_payment_schedule.payment_due_date');

        // Apply sorting
        if (in_array($sortBy, ['payment_due_date', 'branch', 'total_paid', 'total_target'])) {
            if ($sortBy === 'branch_name') {
                // For branch name sorting, we'll need a different approach
            } else {
                $query->orderBy($sortBy, $sortOrder);
            }
        }

        // Get paginated results
        $paginatedData = $query->paginate($perPage, ['*'], 'page', $page);

        if ($paginatedData->isEmpty()) {
            return response()->json([
                'data' => [],
                'meta' => [
                    'current_page' => $page,
                    'per_page' => $perPage,
                    'total' => 0,
                    'total_pages' => 0
                ]
            ]);
        }

        // Get all branch IDs from the paginated results
        $branchIds = $paginatedData->pluck('branch')->unique()->values();

        // Fetch branch names in a single query
        $branchNames = Branch::whereIn('id', $branchIds)
            ->select('id', 'branch_name')
            ->get()
            ->keyBy('id');

        // Transform the results with branch names
        $transformedData = $paginatedData->map(function ($item) use ($branchNames) {
            return [
                'branch' => $item->branch,
                'branch_name' => $branchNames[$item->branch]->branch_name ?? 'Unknown Branch',
                'payment_date' => $item->payment_due_date,
                'total_paid' => (float) $item->total_paid,
                'total_target' => (float) $item->total_target,
                'achievement_rate' => $item->total_target > 0
                    ? round(($item->total_paid / $item->total_target) * 100, 2)
                    : 0
            ];
        });

        // If sorting by branch_name is requested
        if ($sortBy === 'branch_name') {
            $transformedData = $transformedData->sortBy('branch_name', SORT_REGULAR, $sortOrder === 'desc');
        }

        return response()->json([
            'data' => $transformedData->values(),
            'meta' => [
                'current_page' => $paginatedData->currentPage(),
                'per_page' => $paginatedData->perPage(),
                'total' => $paginatedData->total(),
                'total_pages' => $paginatedData->lastPage(),
                'has_more' => $paginatedData->hasMorePages()
            ],
            'summary' => [
                'total_branches' => $branchIds->count(),
                'total_paid' => $paginatedData->sum('total_paid'),
                'total_target' => $paginatedData->sum('total_target')
            ]
        ]);
    }

    public function branchPaymentsView($branch = null, $date = null)
    {
        $user_company = $this->getCompanyId();
        $user_id = $this->getUserId();
        // Fetch unfilled schedules for the given branch and date
        $unfilledSchedules = LoanPaymentSchedules::where([
            'loan_payment_schedule.branch' => $branch,
            'loan_payment_schedule.payment_due_date' => $date,
            'loan_payment_schedule.is_submitted' => true,
            //'payment_submissions.submission_status' => 8
        ])
            ->whereIn('payment_submissions.submission_status', [8, 11])
            ->join('loans', 'loans.loan_number', '=', 'loan_payment_schedule.loan_number')
            ->join('payment_submissions', 'payment_submissions.schedule_id', '=', 'loan_payment_schedule.id')
            ->where('loans.company', $user_company)
            ->select('loan_payment_schedule.*', 'payment_submissions.*', 'loans.customer', 'loans.loan_number')
            ->get();

        // Get customers in that zone
        $zoneCustomers = CustomersZone::where('customers_zones.branch_id', $branch)
            ->join('customers', 'customers.id', '=', 'customers_zones.customer_id')
            ->select('customers.*', 'customers.id as customer_id')
            ->get()
            ->keyBy('customer_id'); // To speed up lookups

        // Merge schedule with customer
        $merged = $unfilledSchedules->map(function ($schedule) use ($zoneCustomers) {
            $customer = $zoneCustomers->get($schedule->customer);
            return [
                'schedule' => $schedule,
                'customer' => $customer
            ];
        });

        return $merged;
    }

    public function branchPayments($branch = null, $date = null)
    {
        $user_company = $this->getCompanyId();
        $user_id = $this->getUserId();

        // Validate inputs
        if (!$branch || !$date) {
            return response()->json([
                'message' => 'Branch ID and date are required',
                'data' => []
            ], 400);
        }

        try {
            $parsedDate = Carbon::parse($date)->format('Y-m-d');
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Invalid date format. Use YYYY-MM-DD',
                'data' => []
            ], 400);
        }

        // Get zones for this branch
        $zones = Zone::where('branch', $branch)
            ->where('company', $user_company)
            ->where('status', 1)
            ->select('id', 'zone_name')
            ->orderBy('zone_name')
            ->get();

        if ($zones->isEmpty()) {
            return response()->json([
                'branch' => [
                    'id' => $branch,
                    'name' => Branch::where('id', $branch)->value('branch_name') ?? 'Unknown Branch'
                ],
                'date' => $parsedDate,
                'zones' => [],
                'summary' => [
                    'total_target' => 0,
                    'total_collections' => 0,
                    'total_balance' => 0,
                    'overall_achievement_rate' => 0
                ],
                'meta' => [
                    'total_zones' => 0
                ]
            ]);
        }

        // Get zone IDs
        $zoneIds = $zones->pluck('id')->toArray();

        // Get targets for all zones in single query
        $targets = LoanPaymentSchedules::whereIn('zone', $zoneIds)
            ->where('payment_due_date', $parsedDate)
            ->where('company', $user_company)
            ->where('is_submitted', true)
            ->where('status', 1)
            ->selectRaw('zone, SUM(payment_total_amount) as total_target')
            ->groupBy('zone')
            ->get()
            ->keyBy('zone');

        // Get collections for all zones in single query
        $collections = PaymentSubmissions::whereIn('zone', $zoneIds)
            ->where('submitted_date', $parsedDate)
            ->where('company', $user_company)
            ->where('submission_status', 8)
            ->selectRaw('zone, SUM(amount) as total_collected')
            ->groupBy('zone')
            ->get()
            ->keyBy('zone');

        // Process zones data
        $zoneData = [];
        $totalTarget = 0;
        $totalCollections = 0;

        foreach ($zones as $zone) {
            $zoneTarget = $targets[$zone->id]->total_target ?? 0;
            $zoneCollected = $collections[$zone->id]->total_collected ?? 0;
            $balance = $zoneTarget - $zoneCollected;

            $zoneData[] = [
                'zone_id' => $zone->id,
                'zone_name' => $zone->zone_name,
                'target' => (float) $zoneTarget,
                'collections' => (float) $zoneCollected,
                'balance' => (float) $balance,
                'achievement_rate' => $zoneTarget > 0
                    ? round(($zoneCollected / $zoneTarget) * 100, 2)
                    : ($zoneCollected > 0 ? 100 : 0),
                'submission_date' => $parsedDate
            ];

            $totalTarget += $zoneTarget;
            $totalCollections += $zoneCollected;
        }

        // Get branch info
        $branchInfo = Branch::where('id', $branch)
            ->where('company', $user_company)
            ->select('id', 'branch_name')
            ->first();

        return response()->json([
            'branch' => $branchInfo ?? ['id' => $branch, 'branch_name' => 'Unknown Branch'],
            'date' => $parsedDate,
            'zones' => $zoneData,
            'summary' => [
                'total_target' => (float) $totalTarget,
                'total_collections' => (float) $totalCollections,
                'total_balance' => (float) ($totalTarget - $totalCollections),
                'overall_achievement_rate' => $totalTarget > 0
                    ? round(($totalCollections / $totalTarget) * 100, 2)
                    : 0
            ],
            'meta' => [
                'total_zones' => count($zoneData),
                'zones_with_targets' => collect($zoneData)->where('target', '>', 0)->count(),
                'zones_with_collections' => collect($zoneData)->where('collections', '>', 0)->count()
            ]
        ]);
    }

    public function rejectZonePayments(Request $request)
    {
        try {
            $validated = $request->validate([
                'zone' => 'bail|required|numeric',
                'payment_date' => 'bail|required|date',
                'submitted_date' => 'bail|required|date',
                'page' => 'sometimes|integer|min:1',
                'per_page' => 'sometimes|integer|min:1|max:500',
            ]);

            $user_company = $this->getCompanyId();
            $bank = new BankController();
            $user_id = $this->getUserId();

            $data = [
                'submission_status' => 9
            ];

            $perPage = $validated['per_page'] ?? 100;
            $page = $validated['page'] ?? null;

            // Build the base query with selects to avoid ambiguity and improve performance
            $query = PaymentSubmissions::select(
                'payment_submissions.id as submission_id',
                'payment_submissions.schedule_id',
                'payment_submissions.amount',
                'payment_submissions.company',
                'payment_submissions.branch',
                'payment_submissions.zone',
                'payment_submissions.paid_account as account',
                'payment_submissions.loan_number',
                'loan_payment_schedule.payment_due_date as schedule_date',
                'loans.customer as customer_id' // Assuming 'customer' is the field name; adjust if needed
            )
                ->where('payment_submissions.submission_status', 4)
                ->join('loan_payment_schedule', 'payment_submissions.schedule_id', '=', 'loan_payment_schedule.id')
                ->join('loans', 'loans.loan_number', '=', 'payment_submissions.loan_number')
                ->where('payment_submissions.zone', $validated['zone'])
                ->where('payment_submissions.submitted_date', $validated['submitted_date'])
                ->where('loan_payment_schedule.payment_due_date', $validated['payment_date']);

            DB::beginTransaction();

            $rejectedCount = 0;

            if ($page) {
                // Paginated processing
                $paymentsForRejects = $query->paginate($perPage, ['*'], 'page', $page);

                foreach ($paymentsForRejects->items() as $payment) {
                    $bank->registerTransaction(
                        $payment->account,
                        $payment->amount,
                        false,
                        $payment->schedule_date,
                        true,
                        $payment->branch,
                        $payment->zone,
                        $payment->loan_number,
                        $payment->customer_id,
                        $payment->schedule_id
                    );
                }

                // Update only the processed submissions
                $submissionIds = collect($paymentsForRejects->items())->pluck('submission_id');
                if ($submissionIds->isNotEmpty()) {
                    $rejectedCount = PaymentSubmissions::whereIn('id', $submissionIds)->update($data);
                }

                $responseData = [
                    'status' => 'success',
                    'message' => 'Zone payments rejected successfully for the requested page',
                    'rejected_count' => $rejectedCount,
                    'total' => $paymentsForRejects->total(),
                    'current_page' => $paymentsForRejects->currentPage(),
                    'last_page' => $paymentsForRejects->lastPage(),
                    'per_page' => $paymentsForRejects->perPage(),
                ];
            } else {
                // Non-paginated: Process all with chunking for better memory performance
                $query->chunk(100, function ($payments) use ($bank, $validated) {
                    foreach ($payments as $payment) {
                        $bank->registerTransaction(
                            $payment->account,
                            $payment->amount,
                            false,
                            $validated['payment_date'], // Use validated date as it's consistent
                            true,
                            $payment->branch,
                            $payment->zone,
                            $payment->loan_number,
                            $payment->customer_id,
                            $payment->schedule_id
                        );
                    }
                });

                // Batch update all matching records
                $updateQuery = PaymentSubmissions::where('submission_status', 4)
                    ->join('loan_payment_schedule', 'payment_submissions.schedule_id', '=', 'loan_payment_schedule.id')
                    ->where('payment_submissions.zone', $validated['zone'])
                    ->where('payment_submissions.submitted_date', $validated['submitted_date'])
                    ->where('loan_payment_schedule.payment_due_date', $validated['payment_date']);

                $rejectedCount = $updateQuery->update($data);

                $responseData = [
                    'status' => 'success',
                    'message' => 'Zone payments rejected successfully',
                    'rejected_count' => $rejectedCount,
                ];
            }

            DB::commit();

            return response()->json($responseData, 201);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            DB::rollback();
            // Log the error or handle as needed
            return response()->json([
                'status' => 'error',
                'message' => 'An error occurred while rejecting payments',
            ], 500);
        }
    }

    public function rejectedPayments($page = 1, $perPage = 15)
    {
        $user_company = $this->getCompanyId();
        $user_zones   = (array) $this->getUserZones();
        if (empty($user_zones)) {
            return [
                'data' => collect(),
                'meta' => [
                    'current_page' => 1,
                    'per_page' => $perPage,
                    'total' => 0,
                    'last_page' => 1
                ]
            ];
        }

        $today = date('Y-m-d');

        // OPTIMIZATION 1: Use subquery instead of two separate queries
        $baseQuery = LoanPaymentSchedules::select([
            'loan_payment_schedule.zone',
            'loan_payment_schedule.payment_due_date',
            DB::raw('SUM(loan_payment_schedule.payment_total_amount) as total_target'),
            DB::raw('SUM(CASE WHEN payment_submissions.submission_status = 9 THEN payment_submissions.amount ELSE 0 END) as total_paid')
        ])
            ->leftJoin('payment_submissions', function ($join) {
                $join->on('payment_submissions.schedule_id', '=', 'loan_payment_schedule.id')
                    ->where('payment_submissions.submission_status', 9);
            })
            ->whereIn('loan_payment_schedule.zone', $user_zones)
            ->where('loan_payment_schedule.payment_due_date', '<=', $today)
            ->where('loan_payment_schedule.is_submitted', true)
            ->where('payment_submissions.submission_status', 9) // Move condition here for better performance
            ->whereNotExists(function ($query) use ($user_zones) {
                $query->select(DB::raw(1))
                    ->from('payment_submissions as ps')
                    ->join('loan_payment_schedule as lps', 'lps.id', '=', 'ps.schedule_id')
                    ->whereColumn('lps.zone', 'loan_payment_schedule.zone')
                    ->whereColumn('lps.payment_due_date', 'loan_payment_schedule.payment_due_date')
                    ->whereIn('ps.submission_status', [4, 8, 11])
                    ->whereIn('lps.zone', $user_zones);
            })
            ->groupBy('loan_payment_schedule.zone', 'loan_payment_schedule.payment_due_date')
            ->having('total_paid', '>', 0); // Only include rows with rejected payments

        // Get total count for pagination
        $total = $baseQuery->count();

        // Apply pagination
        $results = $baseQuery
            ->orderBy('loan_payment_schedule.payment_due_date', 'desc')
            ->orderBy('loan_payment_schedule.zone')
            ->skip(($page - 1) * $perPage)
            ->take($perPage)
            ->get();

        if ($results->isEmpty()) {
            return [
                'data' => collect(),
                'meta' => [
                    'current_page' => $page,
                    'per_page' => $perPage,
                    'total' => $total,
                    'last_page' => max(1, ceil($total / $perPage))
                ]
            ];
        }

        // OPTIMIZATION 2: Eager load zone names with caching
        $zoneIds = $results->pluck('zone')->unique()->toArray();
        $zoneNames = Cache::remember(
            "zone_names_" . implode('_', $zoneIds),
            now()->addMinutes(15),
            function () use ($zoneIds) {
                return Zone::whereIn('id', $zoneIds)
                    ->get(['id', 'zone_name'])
                    ->keyBy('id');
            }
        );

        // Format results
        $data = $results->map(function ($item) use ($zoneNames) {
            return [
                'zone' => $item->zone,
                'zone_name' => $zoneNames[$item->zone]->zone_name ?? null,
                'payment_date' => $item->payment_due_date,
                'total_target' => (float) $item->total_target,
                'total_paid' => (float) $item->total_paid,
            ];
        });

        return [
            'data' => $data,
            'meta' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => max(1, ceil($total / $perPage)),
                'has_more_pages' => ($page * $perPage) < $total
            ]
        ];
    }

    public function fetchRejectedPayments($zone = null, $date = null)
    {
        $user_company = $this->getCompanyId();
        $bank = new BankController();
        $accounts = $bank->listActiveAccounts();
        $rejectedSchedules = LoanPaymentSchedules::where([
            'loan_payment_schedule.zone' => $zone,
            'loan_payment_schedule.payment_due_date' => $date,
            'loan_payment_schedule.is_submitted' => true,
            'loan_payment_schedule.status' => 1
        ])
            //->join('payment_submissions', 'payment_submissions.schedule_id', '=', 'loan_payment_schedule.id')
            ->join('loans', 'loans.loan_number', '=', 'loan_payment_schedule.loan_number')
            ->join('payment_submissions', 'payment_submissions.schedule_id', '=', 'loan_payment_schedule.id')
            ->where('payment_submissions.submission_status', 9)
            ->where('loans.company', $user_company)
            ->whereIn('loans.status', [5, 12])
            ->whereNotExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('payment_submissions')
                    ->whereColumn('payment_submissions.schedule_id', 'loan_payment_schedule.id')
                    ->whereIn('payment_submissions.submission_status', [4, 8, 11]);
            })
            //->whereIn('payment_submissions.submission_status', [4, 9])
            ->select('loan_payment_schedule.*', 'loans.customer', 'loans.loan_number')
            ->distinct()
            ->distinct()
            ->get();

        // Get customers in that zone
        $zoneCustomers = CustomersZone::where('customers_zones.zone_id', $zone)
            ->join('customers', 'customers.id', '=', 'customers_zones.customer_id')
            ->select('customers.*', 'customers.id as customer_id')
            ->get()
            ->keyBy('customer_id'); // To speed up lookups

        // Merge schedule with customer
        $merged = $rejectedSchedules->map(function ($schedule) use ($zoneCustomers) {
            $customer = $zoneCustomers->get($schedule->customer);
            return [
                'schedule' => $schedule,
                'customer' => $customer
            ];
        });

        return response()->json(
            [
                "rejectedData" => $merged,
                "accounts" => $accounts
            ]
        );
    }

    public function unfilledPayments($perPage = 20)
    {
        $user_zones = (array) $this->getUserZones();
        $user_company = $this->getCompanyId();
        $today = date('Y-m-d');

        if (empty($user_zones)) {
            return collect();
        }

        // 1️⃣ Get all unsubmitted payment schedules grouped by zone & date with total_target
        $targetsQuery = LoanPaymentSchedules::selectRaw('loan_payment_schedule.zone, payment_due_date, SUM(payment_total_amount) as total_target')
            ->join('loans', 'loans.loan_number', '=', 'loan_payment_schedule.loan_number')
            ->where('loan_payment_schedule.is_submitted', false)
            ->where('loan_payment_schedule.status', 1)
            ->whereIn('loan_payment_schedule.zone', $user_zones)
            ->where('payment_due_date', '<', $today)
            ->whereIn('loans.status', [5, 12])
            ->groupBy('loan_payment_schedule.zone', 'payment_due_date');

        // 2️⃣ Get total collected for same zones & dates
        $collectedQuery = PaymentSubmissions::selectRaw('loan_payment_schedule.zone, loan_payment_schedule.payment_due_date, SUM(payment_submissions.amount) as total_paid')
            ->join('loan_payment_schedule', 'loan_payment_schedule.id', '=', 'payment_submissions.schedule_id')
            ->where('loan_payment_schedule.is_submitted', false)
            ->where('loan_payment_schedule.status', 1)
            ->whereIn('loan_payment_schedule.zone', $user_zones)
            ->where('loan_payment_schedule.payment_due_date', '<', $today)
            ->groupBy('loan_payment_schedule.zone', 'loan_payment_schedule.payment_due_date');

        $targets = $targetsQuery->get();
        $collected = $collectedQuery->get()->keyBy(fn($item) => $item->zone . '_' . $item->payment_due_date);

        // 3️⃣ Get zone names
        $zoneNames = Zone::whereIn('id', $user_zones)->get()->keyBy('id');

        // 4️⃣ Merge target and collected
        $merged = $targets->map(function ($targetItem) use ($collected, $zoneNames) {
            $key = $targetItem->zone . '_' . $targetItem->payment_due_date;
            $matchingCollected = $collected->get($key);
            return [
                'zone' => $targetItem->zone,
                'zone_name' => $zoneNames[$targetItem->zone]->zone_name ?? null,
                'payment_date' => $targetItem->payment_due_date,
                'total_target' => $targetItem->total_target,
                'total_paid' => $matchingCollected ? $matchingCollected->total_paid : 0,
            ];
        });

        // 5️⃣ Optional: paginate manually
        $page = request()->get('page', 1);
        $perPage = (int) $perPage;
        $paginated = $merged->forPage($page, $perPage)->values();

        return response()->json([
            'data' => $paginated,
            'meta' => [
                'total' => $merged->count(),
                'per_page' => $perPage,
                'current_page' => (int)$page,
                'last_page' => ceil($merged->count() / $perPage),
            ]
        ]);
    }

    public function unfilledDayPayments($zone = null, $date = null, Request $request)
    {
        $user_company = $this->getCompanyId();
        $user_zones   = (array) $this->getUserZones();
        $date         = $date ?? now()->toDateString();

        /* if (is_null($zone)) {
            return response()->json([
                'date' => $date,
                'accounts' => [],
                'schedules' => [],
            ]);
        } */

        // 1. Accounts (fixed duplicate get + DB filtering)
        $accounts = Accounts::select('id', 'account_name')
            ->where('company', $user_company)
            ->where('account_status', 1)
            ->get();

        // 2. Get all schedules for today in user zones (ONE QUERY)
        $schedules = LoanPaymentSchedules::query()
            ->whereDate('loan_payment_schedule.payment_due_date', $date)
            ->where('loan_payment_schedule.zone', $zone)
            ->where('loan_payment_schedule.status', 1)
            ->whereIn('loans.status', [5, 12])
            ->leftJoin('loans', 'loans.loan_number', '=', 'loan_payment_schedule.loan_number')
            ->select(
                'loan_payment_schedule.id',
                'loan_payment_schedule.loan_number',
                'loan_payment_schedule.payment_total_amount',
                'loan_payment_schedule.branch',
                'loan_payment_schedule.zone',
                'loans.customer'
            )
            ->get();

        if ($schedules->isEmpty()) {
            return response()->json([
                'date' => $date,
                'accounts' => $accounts,
                'schedules' => [],
            ]);
        }

        // 3. Exclude already-paid schedules (DB-side filtering)
        $paidScheduleIds = PaymentSubmissions::whereIn(
            'schedule_id',
            $schedules->pluck('id')
        )
            ->whereIn('submission_status', [4, 8, 9, 11])
            ->pluck('schedule_id')
            ->toArray();

        $schedules = $schedules->whereNotIn('id', $paidScheduleIds);

        if ($schedules->isEmpty()) {
            return response()->json([
                'date' => $date,
                'accounts' => $accounts,
                'schedules' => [],
            ]);
        }

        // 4. Load customers in ONE query
        $customerIds = $schedules->pluck('customer')->unique();

        $customers = Customers::whereIn('id', $customerIds)
            ->get()
            ->keyBy('id');

        // 5. Merge schedules + customers efficiently
        $merged = $schedules->map(function ($schedule) use ($customers) {
            return [
                'schedule' => $schedule,
                'customer' => $customers->get($schedule->customer),
            ];
        })->values();

        return response()->json([
            'date' => $date,
            'accounts' => $accounts,
            'schedules' => $merged,
        ]);
    }
}
