<?php

namespace App\Http\Controllers;

use App\Models\AccountHistory;
use App\Models\Accounts;
use App\Models\Customers;
use App\Models\Branch;
use App\Models\Zone;
use App\Models\Loans;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Tymon\JWTAuth\Facades\JWTAuth;
use Illuminate\Validation\ValidationException;
use Carbon\Carbon;

class BankController extends Controller
{
    /**
     * List accounts with filtering by branch/zone
     */
    public function list(Request $request)
    {
        try {
            $user = JWTAuth::parseToken()->getPayload();
            $userCompany = $user->get('company');
            $userBranch = $user->get('branch');
            $userZone = $user->get('zone');
            $userRole = $user->get('role');

            $perPage = $request->get('per_page', 15);
            $search = $request->get('search');
            $accountType = $request->get('account_type');
            $branchId = $request->get('branch_id');
            $zoneId = $request->get('zone_id');
            $status = $request->get('status', Accounts::STATUS_ACTIVE);

            // Build accounts query
            $accountsQuery = Accounts::with(['branch', 'zone', 'parentAccount'])
                ->where('company_id', $userCompany)
                ->where('account_status', '!=', Accounts::STATUS_DELETED);

            // Apply filters based on user role
            if ($userRole !== 'super_admin' && $userRole !== 'admin') {
                // Non-admin users can only see their branch/zone accounts
                if ($userBranch) {
                    $accountsQuery->where('branch_id', $userBranch);
                } elseif ($userZone) {
                    $accountsQuery->where('zone_id', $userZone);
                }
            }

            // Apply request filters
            if ($branchId) {
                $accountsQuery->where('branch_id', $branchId);
            }

            if ($zoneId) {
                $accountsQuery->where('zone_id', $zoneId);
            }

            if ($accountType) {
                $accountsQuery->where('account_type', $accountType);
            }

            if ($status) {
                $accountsQuery->where('account_status', $status);
            }

            // Apply search filter
            if ($search) {
                $accountsQuery->where(function ($q) use ($search) {
                    $q->where('account_name', 'LIKE', "%{$search}%")
                        ->orWhere('account_number', 'LIKE', "%{$search}%");
                });
            }

            // Get paginated accounts
            $accounts = $accountsQuery->orderBy('account_type')
                ->orderBy('account_name')
                ->paginate($perPage);

            // Get date range for transactions
            $startDate = $request->get('start_date', Carbon::now()->subDays(30)->toDateString());
            $endDate = $request->get('end_date', Carbon::now()->toDateString());

            // Calculate summary statistics
            $totalBalance = $accounts->sum('account_balance');
            $activeAccounts = $accounts->where('account_status', Accounts::STATUS_ACTIVE)->count();

            // Get recent transactions across all accounts
            $recentTransactions = AccountHistory::where('company_id', $userCompany)
                ->with(['account', 'customer', 'branch', 'zone'])
                ->whereBetween('transaction_date', [$startDate, $endDate])
                ->orderBy('transaction_date', 'desc')
                ->limit(20)
                ->get();

            return response()->json([
                'success' => true,
                'data' => [
                    'accounts' => $accounts,
                    'recent_transactions' => $recentTransactions,
                    'summary' => [
                        'total_balance' => (float) $totalBalance,
                        'active_accounts' => $activeAccounts,
                        'total_accounts' => $accounts->total(),
                        'period' => [
                            'start_date' => $startDate,
                            'end_date' => $endDate,
                        ],
                    ],
                ],
                'message' => 'Accounts retrieved successfully',
            ], 200);
        } catch (\Exception $e) {
            Log::error('Failed to fetch accounts: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch accounts: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get available funding accounts for loan approval
     */
    public function getFundingAccounts(Request $request)
    {
        try {
            $user = JWTAuth::parseToken()->getPayload();
            $userCompany = $user->get('company');
            $userBranch = $user->get('branch');
            $userZone = $user->get('zone');
            $userRole = $user->get('role');

            $loanAmount = $request->get('loan_amount');
            $branchId = $request->get('branch_id', $userBranch);
            $zoneId = $request->get('zone_id', $userZone);

            $accountsQuery = Accounts::with(['branch', 'zone'])
                ->where('company_id', $userCompany)
                ->where('account_status', Accounts::STATUS_ACTIVE)
                ->where('account_balance', '>', 0);

            // Filter by account type - prefer branch accounts first
            if ($branchId) {
                $accountsQuery->where(function ($q) use ($branchId) {
                    $q->where('branch_id', $branchId)
                        ->orWhere('account_type', Accounts::TYPE_GENERAL);
                });
            } elseif ($zoneId) {
                $accountsQuery->where(function ($q) use ($zoneId) {
                    $q->where('zone_id', $zoneId)
                        ->orWhere('account_type', Accounts::TYPE_GENERAL);
                });
            } else {
                $accountsQuery->where('account_type', Accounts::TYPE_GENERAL);
            }

            // If loan amount is provided, check which accounts have sufficient balance
            $accounts = $accountsQuery->orderBy('account_type')
                ->orderByRaw('CASE WHEN branch_id = ? THEN 0 ELSE 1 END', [$branchId])
                ->orderBy('account_balance', 'desc')
                ->get();

            // Add formatted balance and sufficiency check
            foreach ($accounts as $account) {
                $account->formatted_balance = $account->formatted_balance;
                $account->has_sufficient_balance = $loanAmount ? $account->account_balance >= $loanAmount : true;
                $account->shortfall = $loanAmount ? max(0, $loanAmount - $account->account_balance) : 0;
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'accounts' => $accounts,
                    'total_available' => (float) $accounts->sum('account_balance'),
                ],
            ], 200);
        } catch (\Exception $e) {
            Log::error('Failed to fetch funding accounts: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch funding accounts',
            ], 500);
        }
    }

    /**
     * Register a new account
     */
    public function registerAccount(Request $request)
    {
        try {
            $validated = $request->validate([
                'account_name' => 'required|string|max:255',
                'account_number' => 'nullable|string|max:50|unique:accounts,account_number',
                'account_type' => 'required|in:' . Accounts::TYPE_GENERAL . ',' . Accounts::TYPE_BRANCH . ',' . Accounts::TYPE_ESCROW,
                'branch_id' => 'required_if:account_type,' . Accounts::TYPE_BRANCH . '|nullable|exists:branches,id',
                'zone_id' => 'nullable|exists:zones,id',
                'initial_balance' => 'nullable|numeric|min:0',
                'minimum_balance' => 'nullable|numeric|min:0',
                'maximum_balance' => 'nullable|numeric|gt:minimum_balance',
                'currency' => 'nullable|string|size:3',
                'description' => 'nullable|string|max:500',
                'parent_account_id' => 'nullable|exists:accounts,id',
            ]);

            $user = JWTAuth::parseToken()->getPayload();
            $userCompany = $user->get('company');
            $userId = $user->get('user_id');

            // Check for duplicate account name
            $existingAccount = Accounts::where('account_name', $validated['account_name'])
                ->where('company_id', $userCompany)
                ->first();

            if ($existingAccount) {
                return response()->json([
                    'success' => false,
                    'message' => 'An account with this name already exists',
                ], 422);
            }

            // Auto-generate account number if not provided
            $accountNumber = $validated['account_number'] ?? $this->generateAccountNumber($validated['account_type']);

            $initialBalance = $validated['initial_balance'] ?? 0;

            // Create the account
            $account = Accounts::create([
                'account_name' => $validated['account_name'],
                'account_number' => $accountNumber,
                'account_type' => $validated['account_type'],
                'account_balance' => 0,
                'account_status' => Accounts::STATUS_ACTIVE,
                'branch_id' => $validated['branch_id'] ?? null,
                'zone_id' => $validated['zone_id'] ?? null,
                'company_id' => $userCompany,
                'parent_account_id' => $validated['parent_account_id'] ?? null,
                'currency' => $validated['currency'] ?? 'TZS',
                'minimum_balance' => $validated['minimum_balance'] ?? 0,
                'maximum_balance' => $validated['maximum_balance'] ?? null,
                'description' => $validated['description'] ?? null,
                'created_by' => $userId,
            ]);

            // Register initial balance transaction if greater than 0
            if ($initialBalance > 0) {
                $this->registerTransaction(
                    $account->id,
                    $initialBalance,
                    'credit',
                    Carbon::now()->toDateString(),
                    false,
                    null,
                    null,
                    null,
                    null,
                    null,
                    'Initial account funding'
                );
            }

            return response()->json([
                'success' => true,
                'message' => 'Account created successfully',
                'data' => $account->load(['branch', 'zone']),
            ], 201);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            Log::error('Failed to register account: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to register account: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Register a transaction
     */
    public function registerTransaction($accountId, $amount, $type, $date, $isReverse = false, $branchId = null, $zoneId = null, $loanNumber = null, $customerId = null, $scheduleId = null, $description = null)
    {
        try {
            $user = JWTAuth::parseToken()->getPayload();
            $userCompany = $user->get('company');
            $userId = $user->get('user_id');
            $fStartDate = $user->get('f_start_date');
            $fEndDate = $user->get('f_end_date');

            // Calculate amount sign
            $transactionAmount = ($type === 'credit') ? abs($amount) : -1 * abs($amount);
            if ($isReverse) {
                $transactionAmount = -1 * $transactionAmount;
            }

            DB::transaction(function () use ($accountId, $transactionAmount, $date, $isReverse, $branchId, $zoneId, $loanNumber, $customerId, $userCompany, $userId, $fStartDate, $fEndDate, $scheduleId, $description, $type) {
                // Get the account
                $account = Accounts::lockForUpdate()->find($accountId);
                if (!$account) {
                    throw new \Exception('Account not found');
                }

                // For debit transactions, check sufficient balance
                if ($type === 'debit' && !$isReverse) {
                    if (!$account->hasSufficientBalance(abs($transactionAmount))) {
                        throw new \Exception("Insufficient balance in account: {$account->account_name}");
                    }
                }

                // Get opening balance
                $lastTransaction = AccountHistory::where('account_id', $accountId)
                    ->orderBy('created_at', 'desc')
                    ->first();

                $openingBalance = $lastTransaction ? $lastTransaction->closing_balance : $account->account_balance;
                $closingBalance = $openingBalance + $transactionAmount;

                // Update account balance
                $account->increment('account_balance', $transactionAmount);

                // Generate reference number
                $referenceNumber = $this->generateReferenceNumber();

                // Create transaction record
                AccountHistory::create([
                    'account_id' => $accountId,
                    'period_start' => $fStartDate,
                    'period_end' => $fEndDate,
                    'opening_balance' => $openingBalance,
                    'transaction_amount' => $transactionAmount,
                    'closing_balance' => $closingBalance,
                    'loan_number' => $loanNumber,
                    'customer_id' => $customerId,
                    'company_id' => $userCompany,
                    'branch' => $branchId ?? $account->branch_id,
                    'zone' => $zoneId ?? $account->zone_id,
                    'transaction_date' => $date,
                    'transaction_type' => $type,
                    'is_reverse' => $isReverse,
                    'reference_number' => $referenceNumber,
                    'description' => $description ?? ($type === 'credit' ? 'Credit transaction' : 'Debit transaction'),
                    'registered_by' => $userId,
                    'schedule_id' => $scheduleId,
                ]);
            });

            return true;
        } catch (\Exception $e) {
            Log::error('Failed to register transaction: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Disburse loan from funding account
     */
    public function disburseLoan($loanId, $fundingAccountId, $remarks = null)
    {
        try {
            $user = JWTAuth::parseToken()->getPayload();
            $userCompany = $user->get('company');
            $userId = $user->get('user_id');

            $loan = Loans::where('id', $loanId)
                ->where('company', $userCompany)
                ->first();

            if (!$loan) {
                throw new \Exception('Loan not found');
            }

            $fundingAccount = Accounts::lockForUpdate()->find($fundingAccountId);
            if (!$fundingAccount) {
                throw new \Exception('Funding account not found');
            }

            // Check sufficient balance
            if (!$fundingAccount->hasSufficientBalance($loan->principal_amount)) {
                throw new \Exception("Insufficient balance in funding account: {$fundingAccount->account_name}");
            }

            DB::transaction(function () use ($loan, $fundingAccount, $userId, $remarks) {
                // Register debit transaction from funding account
                $this->registerTransaction(
                    $fundingAccount->id,
                    $loan->principal_amount,
                    'debit',
                    now()->toDateString(),
                    false,
                    $loan->branch,
                    $loan->zone,
                    $loan->loan_number,
                    $loan->customer,
                    null,
                    "Loan disbursement for {$loan->loan_number}" . ($remarks ? " - {$remarks}" : "")
                );

                // Update loan with funding account
                $loan->update([
                    'funding_account_id' => $fundingAccount->id,
                    'disbursed_by' => $userId,
                    'disbursed_at' => now(),
                ]);
            });

            return true;
        } catch (\Exception $e) {
            Log::error('Failed to disburse loan: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Get account balance summary by branch
     */
    public function getBalanceSummary(Request $request)
    {
        try {
            $user = JWTAuth::parseToken()->getPayload();
            $userCompany = $user->get('company');

            $summary = [
                'total_balance' => 0,
                'by_branch' => [],
                'by_type' => [],
                'by_zone' => [],
            ];

            // Get accounts grouped by branch
            $branches = Branch::where('company', $userCompany)->get();

            foreach ($branches as $branch) {
                $branchBalance = Accounts::where('company_id', $userCompany)
                    ->where('branch_id', $branch->id)
                    ->where('account_status', Accounts::STATUS_ACTIVE)
                    ->sum('account_balance');

                $summary['by_branch'][] = [
                    'branch_id' => $branch->id,
                    'branch_name' => $branch->name,
                    'balance' => (float) $branchBalance,
                ];
                $summary['total_balance'] += $branchBalance;
            }

            // Get accounts grouped by type
            $accountTypes = [Accounts::TYPE_GENERAL, Accounts::TYPE_BRANCH, Accounts::TYPE_ESCROW];
            foreach ($accountTypes as $type) {
                $typeBalance = Accounts::where('company_id', $userCompany)
                    ->where('account_type', $type)
                    ->where('account_status', Accounts::STATUS_ACTIVE)
                    ->sum('account_balance');

                $summary['by_type'][] = [
                    'type' => $type,
                    'balance' => (float) $typeBalance,
                ];
            }

            return response()->json([
                'success' => true,
                'data' => $summary,
            ], 200);
        } catch (\Exception $e) {
            Log::error('Failed to get balance summary: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to get balance summary',
            ], 500);
        }
    }

    /**
     * Generate unique account number
     */
    private function generateAccountNumber($accountType)
    {
        $prefix = '';
        switch ($accountType) {
            case Accounts::TYPE_GENERAL:
                $prefix = 'GEN';
                break;
            case Accounts::TYPE_BRANCH:
                $prefix = 'BRN';
                break;
            case Accounts::TYPE_ESCROW:
                $prefix = 'ESC';
                break;
            default:
                $prefix = 'ACC';
        }

        $year = date('Y');
        $random = mt_rand(10000, 99999);

        $accountNumber = $prefix . $year . $random;

        // Ensure uniqueness
        while (Accounts::where('account_number', $accountNumber)->exists()) {
            $random = mt_rand(10000, 99999);
            $accountNumber = $prefix . $year . $random;
        }

        return $accountNumber;
    }

    /**
     * Generate unique reference number for transaction
     */
    private function generateReferenceNumber()
    {
        return 'TXN' . date('YmdHis') . mt_rand(100, 999);
    }

    /**
     * Get parent accounts for hierarchy selection
     */
    public function getParentAccounts(Request $request)
    {
        try {
            $user = JWTAuth::parseToken()->getPayload();
            $userCompany = $user->get('company');

            $accounts = Accounts::where('company_id', $userCompany)
                ->where('account_status', Accounts::STATUS_ACTIVE)
                ->whereNull('parent_account_id') // Only top-level accounts can be parents
                ->orderBy('account_name')
                ->get(['id', 'account_name', 'account_balance', 'account_type']);

            return response()->json([
                'success' => true,
                'data' => $accounts,
            ], 200);
        } catch (\Exception $e) {
            Log::error('Failed to fetch parent accounts: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch parent accounts',
            ], 500);
        }
    }


    /**
     * Update an existing account
     */
    public function updateAccount(Request $request, $id)
    {
        try {
            $user = JWTAuth::parseToken()->getPayload();
            $userCompany = $user->get('company');

            $validated = $request->validate([
                'account_name' => 'required|string|max:255',
                'account_number' => 'nullable|string|max:50',
                'account_type' => 'required|in:general,branch,escrow,floating',
                'branch_id' => 'nullable|exists:branches,id',
                'zone_id' => 'nullable|exists:zones,id',
                'minimum_balance' => 'nullable|numeric|min:0',
                'maximum_balance' => 'nullable|numeric|gt:minimum_balance',
                'description' => 'nullable|string|max:500',
                'currency' => 'nullable|string|size:3',
                'account_status' => 'required|in:1,2,3',
            ]);

            $account = Accounts::where('id', $id)
                ->where('company_id', $userCompany)
                ->first();

            if (!$account) {
                return response()->json([
                    'success' => false,
                    'message' => 'Account not found',
                ], 404);
            }

            // Don't allow changing balance via edit
            unset($validated['account_balance']);

            $account->update($validated);

            return response()->json([
                'success' => true,
                'message' => 'Account updated successfully',
                'data' => $account->fresh(['branch', 'zone']),
            ], 200);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            Log::error('Failed to update account: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to update account: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * List active accounts for dropdown
     */
    public function listActiveAccounts()
    {
        try {
            $user = JWTAuth::parseToken()->getPayload();
            $userCompany = $user->get('company');

            $accounts = Accounts::select('id', 'account_name', 'account_balance', 'account_number')
                ->where('company_id', $userCompany)
                ->where('account_status', 1)
                ->orderBy('account_name')
                ->get();

            return response()->json([
                'success' => true,
                'data' => $accounts,
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch accounts : ' . $e->getMessage(),
            ], 500);
        }
    }

}
