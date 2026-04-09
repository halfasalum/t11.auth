<?php

namespace App\Http\Controllers;

use App\Models\AccountHistory;
use App\Models\Accounts;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;
use Tymon\JWTAuth\Facades\JWTAuth;
use Illuminate\Validation\ValidationException;

class BankController
{

    public function list()
    {
        $user = JWTAuth::parseToken()->getPayload();
        $user_company = $user->get('company');
        $accounts = Accounts::select("id", "account_name", "account_balance", "created_at")
            ->where('account_status', '!=', 3)
            ->where('company', $user_company)
            ->get();
        $transactions = AccountHistory::where('account_histories.company', $user_company)
            ->join('accounts', 'accounts.id', '=', 'account_histories.account_id')
            //->where('transaction_date',' >= ', date('Y-m-01'))
            //->where('transaction_date',' <= ', date('Y-m-d'))
            ->get();
        return response()->json([
            'accounts'  => $accounts,
            'transactions' => $transactions
        ]);
    }
    public function registeriAccount(Request $request)
    {
        try {
            $validated = $request->validate([
                'account_name' => 'bail|required',
                'account_balance' => 'bail|nullable|numeric',
            ]);
            $user = JWTAuth::parseToken()->getPayload();
            $user_company = $user->get('company');
            $validated['company']       = $user_company;
            $initial_balance = isset($validated['account_balance']) ? $validated['account_balance'] : 0;
            $validated['account_balance'] = 0;

            // Create the account
            $newAccount = Accounts::create($validated);
            $insertedId = $newAccount->id;

            // Register a transaction if initial balance is greater than 0
            if ($initial_balance > 0) {
                $this->registerTransaction(
                    $insertedId,
                    $initial_balance,
                    true, // is_income
                    now()->format('Y-m-d'), // Use Carbon for consistency
                    false, // is_reverse
                    null, // branch
                    null, // zone
                    null, // loan_number
                    null  // customer
                );
            }
            return response()->json([
                'status' => 'success',
                'message' => 'Bank account created successfully',
            ], 201);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'errors' => $e->errors(),
            ], 422);
        }
    }

    public function registerTransaction($account, $amount, $is_income = true, $date, $is_reverse = false, $branch = null, $zone = null, $loan_number = null, $customer = null, $schedule_id = null)
    {
        $user = JWTAuth::parseToken()->getPayload();
        $user_company = $user->get('company');
        $user_id = $user->get('user_id');
        $f_start_date = $user->get('f_start_date');
        $f_end_date = $user->get('f_end_date');
        $amount = (!$is_income || $is_reverse) ? -1 * $amount : $amount;
        DB::transaction(function () use ($account, $amount) {
            //$bankAccount = Accounts::findOrFail($account);
            $bankAccount = Accounts::find($account);
            if (!is_null($bankAccount)) {
                $bankAccount->increment('account_balance', $amount);
            }
        });
        DB::transaction(function () use ($account, $amount, $is_income, $date, $is_reverse, $branch, $zone, $loan_number, $customer, $user_company, $user_id, $f_start_date, $f_end_date, $schedule_id) {
            // Fetch the last transaction

            $last_transaction = AccountHistory::where('account_id', $account)
                ->orderBy('created_at', 'desc')
                ->first();

            if (!is_null($account)) {
                // Set opening balance (0 for first transaction)
                $opening_balance = $last_transaction ? $last_transaction->closing_balance : 0;
                $closing_balance = $opening_balance + $amount;

                // Create new transaction
                $transaction = AccountHistory::create([
                    'account_id' => $account,
                    'period_start' => $f_start_date,
                    'period_end' => $f_end_date,
                    'opening_balance' => $opening_balance,
                    'transaction_amount' => $amount,
                    'closing_balance' => $closing_balance,
                    'loan_number' => $loan_number,
                    'customer' => $customer,
                    'company' => $user_company,
                    'branch' => $branch,
                    'zone' => $zone,
                    'transaction_date' => $date,
                    'is_reverse' => $is_reverse,
                    'registered_by' => $user_id,
                    'schedule_id' => $schedule_id
                ]);

                //return $transaction;
            }
        });
    }

    public function listActiveAccounts()
    {
        $user = JWTAuth::parseToken()->getPayload();
        $user_company = $user->get('company');
        $accounts = Accounts::select("id", "account_name")
            ->where('company', $user_company)
            ->where("account_status", 1)
            ->get();
        return $accounts;
    }
}
