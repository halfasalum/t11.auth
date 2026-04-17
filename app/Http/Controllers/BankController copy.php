<?php

namespace App\Http\Controllers;

use App\Models\AccountHistory;
use App\Models\Accounts;
use App\Models\Customers;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Tymon\JWTAuth\Facades\JWTAuth;
use Illuminate\Validation\ValidationException;
use Carbon\Carbon;

class BankControllerBK extends Controller
{
    /**
     * List all active accounts with their current balances and recent transactions
     */
    public function list(Request $request)
    {
        try {
            $user = JWTAuth::parseToken()->getPayload();
            $userCompany = $user->get('company');
            $userId = $user->get('user_id');

            // Get pagination parameters
            $perPage = $request->get('per_page', 15);
            $search = $request->get('search');

            // Build accounts query
            $accountsQuery = Accounts::select(
                'id',
                'account_name',
                'account_balance',
                'account_status',
                'created_at',
                'updated_at'
            )
                ->where('account_status', '!=', 3) // Exclude deleted accounts
                ->where('company', $userCompany);

            // Apply search filter
            if ($search) {
                $accountsQuery->where(function ($q) use ($search) {
                    $q->where('account_name', 'LIKE', "%{$search}%");
                });
            }

            // Get accounts
            $accounts = $accountsQuery->orderBy('account_name')->get();

            // Get date range for transactions (last 30 days by default)
            $startDate = $request->get('start_date', Carbon::now()->subDays(30)->toDateString());
            $endDate = $request->get('end_date', Carbon::now()->toDateString());

            // Get transactions for the period
            $transactionsQuery = AccountHistory::where('account_histories.company', $userCompany)
                ->join('accounts', 'accounts.id', '=', 'account_histories.account_id')
                ->whereBetween('transaction_date', [$startDate, $endDate])
                ->select(
                    'account_histories.*',
                    'accounts.account_name'
                );

            // Apply account filter if specified
            if ($request->has('account_id')) {
                $transactionsQuery->where('account_histories.account_id', $request->account_id);
            }

            $transactions = $transactionsQuery->orderBy('transaction_date', 'desc')->get();

            // Calculate summary statistics
            $totalBalance = $accounts->sum('account_balance');
            $activeAccounts = $accounts->where('account_status', 1)->count();
            $totalTransactions = $transactions->count();
            $totalTransactionVolume = $transactions->sum('transaction_amount');

            // Calculate per-account statistics
            $accountStats = [];
            foreach ($accounts as $account) {
                $accountTransactions = $transactions->where('account_id', $account->id);
                $accountStats[$account->id] = [
                    'transaction_count' => $accountTransactions->count(),
                    'total_inflow' => (float) $accountTransactions->where('is_reverse', false)->sum('transaction_amount'),
                    'total_outflow' => (float) $accountTransactions->where('is_reverse', true)->sum('transaction_amount'),
                    'last_transaction_date' => $accountTransactions->max('transaction_date'),
                ];
            }

            // Get recent transactions (last 10)
            $recentTransactions = $transactions->take(10)->map(function ($transaction) {
                return [
                    'id' => $transaction->id,
                    'account_id' => $transaction->account_id,
                    'account_name' => $transaction->account_name,
                    'transaction_date' => $transaction->transaction_date,
                    'amount' => (float) $transaction->transaction_amount,
                    'type' => $transaction->is_reverse ? 'debit' : 'credit',
                    'description' => $this->getTransactionDescription($transaction),
                    'loan_number' => $transaction->loan_number,
                    'customer_name' => $this->getCustomerName($transaction->customer),
                    'is_reverse' => $transaction->is_reverse,
                ];
            });

            return response()->json([
                'success' => true,
                'data' => [
                    'accounts' => $accounts,
                    'transactions' => $transactions,
                    'recent_transactions' => $recentTransactions,
                    'summary' => [
                        'total_balance' => (float) $totalBalance,
                        'active_accounts' => $activeAccounts,
                        'total_accounts' => $accounts->count(),
                        'total_transactions' => $totalTransactions,
                        'total_volume' => (float) $totalTransactionVolume,
                        'period' => [
                            'start_date' => $startDate,
                            'end_date' => $endDate,
                        ],
                    ],
                    'account_stats' => $accountStats,
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
     * Register a new bank account
     */
    public function registerAccount(Request $request)
    {
        try {
            $validated = $request->validate([
                'account_name' => 'required|string|max:255',
                'initial_balance' => 'nullable|numeric|min:0',
            ]);

            $user = JWTAuth::parseToken()->getPayload();
            $userCompany = $user->get('company');
            $userId = $user->get('user_id');

            // Check if account with same name exists
            $existingAccount = Accounts::where('account_name', $validated['account_name'])
                ->where('company', $userCompany)
                ->first();

            if ($existingAccount) {
                return response()->json([
                    'success' => false,
                    'message' => 'An account with this name already exists',
                ], 422);
            }

            $initialBalance = isset($validated['initial_balance']) ? (float) $validated['initial_balance'] : 0;
            

            // Create the account
            $account = Accounts::create([
                'account_name' => $validated['account_name'],
                'account_balance' => $initialBalance > 0 ? 0 : $initialBalance,
                'account_status' => 1,
                'company' => $userCompany,
            ]);

            // Register initial balance transaction if greater than 0
            if ($initialBalance > 0) {
                $this->registerTransaction(
                    $account->id,
                    $initialBalance,
                    true, // is_income
                    Carbon::now()->toDateString(),
                    false, // is_reverse
                    null, // branch
                    null, // zone
                    null, // loan_number
                    null, // customer
                    null  // schedule_id
                );
            }

            return response()->json([
                'success' => true,
                'message' => 'Bank account created successfully',
                'data' => [
                    'id' => $account->id,
                    'account_name' => $account->account_name,
                    'account_balance' => (float) $account->account_balance,
                ],
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
    public function registerTransaction($account, $amount, $is_income = true, $date, $is_reverse = false, $branch = null, $zone = null, $loan_number = null, $customer = null, $schedule_id = null)
    {
        try {
            $user = JWTAuth::parseToken()->getPayload();
            $userCompany = $user->get('company');
            $userId = $user->get('user_id');
            $f_start_date = $user->get('f_start_date');
            $f_end_date = $user->get('f_end_date');
            
            // Calculate amount sign (negative for debit/reverse)
            $amount = (!$is_income || $is_reverse) ? -1 * abs($amount) : abs($amount);

            DB::transaction(function () use ($account, $amount, $date, $is_reverse, $branch, $zone, $loan_number, $customer, $userCompany, $userId, $f_start_date, $f_end_date, $schedule_id) {
                // Get the account
                $bankAccount = Accounts::find($account);
                if (!$bankAccount) {
                    throw new \Exception('Account not found');
                }

                // Update account balance
                $bankAccount->increment('account_balance', $amount);

                // Get the last transaction for opening balance
                $lastTransaction = AccountHistory::where('account_id', $account)
                    ->orderBy('created_at', 'desc')
                    ->first();

                $openingBalance = $lastTransaction ? $lastTransaction->closing_balance : 0;
                $closingBalance = $openingBalance + $amount;

                // Create transaction record
                AccountHistory::create([
                    'account_id' => $account,
                    'period_start' => $f_start_date,
                    'period_end' => $f_end_date,
                    'opening_balance' => $openingBalance,
                    'transaction_amount' => $amount,
                    'closing_balance' => $closingBalance,
                    'loan_number' => $loan_number,
                    'customer' => $customer,
                    'company' => $userCompany,
                    'branch' => $branch,
                    'zone' => $zone,
                    'transaction_date' => $date,
                    'is_reverse' => $is_reverse,
                    'registered_by' => $userId,
                    'schedule_id' => $schedule_id,
                ]);
            });

            return true;
        } catch (\Exception $e) {
            Log::error('Failed to register transaction: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Get single account details
     */
    public function show($id, Request $request)
    {
        try {
            $user = JWTAuth::parseToken()->getPayload();
            $userCompany = $user->get('company');

            $account = Accounts::where('id', $id)
                ->where('company', $userCompany)
                ->where('account_status', '!=', 3)
                ->first();

            if (!$account) {
                return response()->json([
                    'success' => false,
                    'message' => 'Account not found',
                ], 404);
            }

            $startDate = $request->get('start_date', Carbon::now()->subDays(30)->toDateString());
            $endDate = $request->get('end_date', Carbon::now()->toDateString());

            $transactions = AccountHistory::where('account_id', $id)
                ->where('company', $userCompany)
                ->whereBetween('transaction_date', [$startDate, $endDate])
                ->orderBy('transaction_date', 'desc')
                ->get();

            return response()->json([
                'success' => true,
                'data' => [
                    'account' => $account,
                    'transactions' => $transactions,
                    'summary' => [
                        'total_credits' => (float) $transactions->where('is_reverse', false)->sum('transaction_amount'),
                        'total_debits' => (float) $transactions->where('is_reverse', true)->sum('transaction_amount'),
                        'transaction_count' => $transactions->count(),
                    ],
                ],
            ], 200);
        } catch (\Exception $e) {
            Log::error('Failed to fetch account details: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch account details',
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
            
            $accounts = Accounts::select('id', 'account_name', 'account_balance')
                ->where('company', $userCompany)
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
                'message' => 'Failed to fetch accounts',
            ], 500);
        }
    }

    /**
     * Get transaction description
     */
    private function getTransactionDescription($transaction): string
    {
        if ($transaction->loan_number) {
            return $transaction->is_reverse
                ? "Reversal for Loan #{$transaction->loan_number}"
                : "Payment received for Loan #{$transaction->loan_number}";
        }
        return $transaction->is_reverse ? "Debit transaction" : "Credit transaction";
    }

    /**
     * Get customer name by ID
     */
    private function getCustomerName($customerId): ?string
    {
        if (!$customerId) return null;
        $customer = Customers::find($customerId);
        return $customer ? $customer->fullname : null;
    }
}