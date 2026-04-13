<?php

namespace App\Http\Controllers\Api\V2\Reports;

use App\Http\Controllers\Api\V2\BaseController;
use App\Models\Loans;
use App\Models\PaymentSubmissions;
use App\Models\Expenses;
use App\Models\Income;
use App\Models\Accounts;
use App\Models\AccountHistory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class FinancialReportController extends BaseController
{
    /**
     * Income Statement (Profit & Loss)
     */
    public function incomeStatement(Request $request)
    {
        try {
            $companyId = $this->getCompanyId();
            $period = $request->get('period', 'monthly');
            $startDate = $request->get('start_date') ? Carbon::parse($request->start_date) : Carbon::now()->startOfMonth();
            $endDate = $request->get('end_date') ? Carbon::parse($request->end_date) : Carbon::now();

            // Calculate Income
            $interestIncome = $this->calculateInterestIncome($companyId, $startDate, $endDate);
            $penaltyIncome = $this->calculatePenaltyIncome($companyId, $startDate, $endDate);
            $otherIncome = $this->calculateOtherIncome($companyId, $startDate, $endDate);

            // Calculate Expenses
            $expensesByCategory = $this->getExpensesByCategory($companyId, $startDate, $endDate);
            $totalExpenses = $expensesByCategory->sum('amount');

            // Calculate Profit
            $totalIncome = $interestIncome + $penaltyIncome + $otherIncome;
            $grossProfit = $totalIncome - $expensesByCategory->where('is_staff_related', false)->sum('amount');
            $netProfit = $totalIncome - $totalExpenses;
            $profitMargin = $totalIncome > 0 ? ($netProfit / $totalIncome) * 100 : 0;

            // Get trend data
            $trendData = $this->getIncomeStatementTrend($companyId);

            $data = [
                'period' => [
                    'start_date' => $startDate->toDateString(),
                    'end_date' => $endDate->toDateString(),
                ],
                'income' => [
                    'interest_income' => (float) $interestIncome,
                    'penalty_income' => (float) $penaltyIncome,
                    'other_income' => (float) $otherIncome,
                    'total_income' => (float) $totalIncome,
                ],
                'expenses' => [
                    'by_category' => $expensesByCategory,
                    'total_expenses' => (float) $totalExpenses,
                ],
                'profit' => [
                    'gross_profit' => (float) $grossProfit,
                    'net_profit' => (float) $netProfit,
                    'profit_margin' => round($profitMargin, 2),
                ],
                'trend' => $trendData,
            ];

            return $this->successResponse($data, 'Income statement retrieved successfully');
        } catch (\Exception $e) {
            return $this->errorResponse('Failed to load income statement: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Balance Sheet Report
     */
    public function balanceSheet(Request $request)
    {
        try {
            $companyId = $this->getCompanyId();
            $asAtDate = $request->get('as_at_date') ? Carbon::parse($request->as_at_date) : Carbon::now();

            // Assets
            $cashInAccounts = Accounts::where('company', $companyId)->sum('account_balance');
            $loanReceivables = Loans::where('company', $companyId)
                ->whereIn('status', [Loans::STATUS_ACTIVE, Loans::STATUS_OVERDUE])
                ->get()
                ->sum(function ($loan) {
                    return ($loan->total_loan + ($loan->penalty_amount ?? 0)) - ($loan->loan_paid ?? 0);
                });

            // Liabilities
            $allocatedFunds = DB::table('branch_funds_allocation')
                ->where('company', $companyId)
                ->sum('allocated_amount');

            $data = [
                'as_at_date' => $asAtDate->toDateString(),
                'assets' => [
                    'cash_in_accounts' => (float) $cashInAccounts,
                    'loan_receivables' => (float) $loanReceivables,
                    'total_assets' => (float) ($cashInAccounts + $loanReceivables),
                ],
                'liabilities' => [
                    'allocated_funds' => (float) $allocatedFunds,
                    'total_liabilities' => (float) $allocatedFunds,
                ],
                'equity' => [
                    'retained_earnings' => (float) ($cashInAccounts + $loanReceivables - $allocatedFunds),
                    'total_equity' => (float) ($cashInAccounts + $loanReceivables - $allocatedFunds),
                ],
            ];

            return $this->successResponse($data, 'Balance sheet retrieved successfully');
        } catch (\Exception $e) {
            return $this->errorResponse('Failed to load balance sheet: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Cash Flow Statement
     */
    public function cashFlowStatement(Request $request)
    {
        try {
            $companyId = $this->getCompanyId();
            $startDate = $request->get('start_date') ? Carbon::parse($request->start_date) : Carbon::now()->startOfMonth();
            $endDate = $request->get('end_date') ? Carbon::parse($request->end_date) : Carbon::now();

            // Cash Inflows
            $loanRepayments = PaymentSubmissions::where('company', $companyId)
                ->where('submission_status', 11)
                ->whereBetween('submitted_date', [$startDate, $endDate])
                ->sum('amount');

            $otherIncome = Income::where('income_company', $companyId)
                ->whereBetween('income_date', [$startDate, $endDate])
                ->sum('income_amount');

            // Cash Outflows
            $loanDisbursements = Loans::where('company', $companyId)
                ->whereBetween('start_date', [$startDate, $endDate])
                ->sum('principal_amount');

            $operatingExpenses = Expenses::where('company_id', $companyId)
                ->whereBetween('expense_date', [$startDate, $endDate])
                ->sum('amount');

            $netCashFlow = ($loanRepayments + $otherIncome) - ($loanDisbursements + $operatingExpenses);

            $data = [
                'period' => [
                    'start_date' => $startDate->toDateString(),
                    'end_date' => $endDate->toDateString(),
                ],
                'cash_inflows' => [
                    'loan_repayments' => (float) $loanRepayments,
                    'other_income' => (float) $otherIncome,
                    'total_inflows' => (float) ($loanRepayments + $otherIncome),
                ],
                'cash_outflows' => [
                    'loan_disbursements' => (float) $loanDisbursements,
                    'operating_expenses' => (float) $operatingExpenses,
                    'total_outflows' => (float) ($loanDisbursements + $operatingExpenses),
                ],
                'net_cash_flow' => (float) $netCashFlow,
                'opening_balance' => (float) Accounts::where('company', $companyId)->sum('account_balance'),
                'closing_balance' => (float) (Accounts::where('company', $companyId)->sum('account_balance') + $netCashFlow),
            ];

            return $this->successResponse($data, 'Cash flow statement retrieved successfully');
        } catch (\Exception $e) {
            return $this->errorResponse('Failed to load cash flow statement: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Account History Report
     */
    public function accountHistory($accountId, Request $request)
    {
        try {
            $companyId = $this->getCompanyId();
            $startDate = $request->get('start_date') ? Carbon::parse($request->start_date) : Carbon::now()->subMonths(6);
            $endDate = $request->get('end_date') ? Carbon::parse($request->end_date) : Carbon::now();

            $account = Accounts::where('id', $accountId)
                ->where('company', $companyId)
                ->first();

            if (!$account) {
                return $this->errorResponse('Account not found', 404);
            }

            $history = AccountHistory::where('account_id', $accountId)
                ->whereBetween('transaction_date', [$startDate, $endDate])
                ->orderBy('transaction_date', 'desc')
                ->get();

            $data = [
                'account' => [
                    'id' => $account->id,
                    'account_name' => $account->account_name,
                    'current_balance' => (float) $account->account_balance,
                ],
                'period' => [
                    'start_date' => $startDate->toDateString(),
                    'end_date' => $endDate->toDateString(),
                ],
                'transactions' => $history->map(function ($transaction) {
                    return [
                        'date' => $transaction->transaction_date,
                        'description' => "Loan #{$transaction->loan_number}",
                        'amount' => (float) $transaction->transaction_amount,
                        'type' => $transaction->is_reverse ? 'reversal' : 'payment',
                        'opening_balance' => (float) $transaction->opening_balance,
                        'closing_balance' => (float) $transaction->closing_balance,
                    ];
                }),
                'summary' => [
                    'total_transactions' => $history->count(),
                    'total_amount' => (float) $history->sum('transaction_amount'),
                ],
            ];

            return $this->successResponse($data, 'Account history retrieved successfully');
        } catch (\Exception $e) {
            return $this->errorResponse('Failed to load account history: ' . $e->getMessage(), 500);
        }
    }

    private function calculateInterestIncome($companyId, $startDate, $endDate)
    {
        return PaymentSubmissions::where('company', $companyId)
            ->where('submission_status', 11)
            ->whereBetween('submitted_date', [$startDate, $endDate])
            ->sum('paid_interest');
    }

    private function calculatePenaltyIncome($companyId, $startDate, $endDate)
    {
        return PaymentSubmissions::where('company', $companyId)
            ->where('submission_status', 11)
            ->whereBetween('submitted_date', [$startDate, $endDate])
            ->whereHas('schedule', function ($q) {
                $q->where('is_penalty', true);
            })
            ->sum('amount');
    }

    private function calculateOtherIncome($companyId, $startDate, $endDate)
    {
        return Income::where('income_company', $companyId)
            ->whereBetween('income_date', [$startDate, $endDate])
            ->sum('income_amount');
    }

    private function getExpensesByCategory($companyId, $startDate, $endDate)
    {
        return Expenses::where('expenses.company_id', $companyId)
            ->whereBetween('expense_date', [$startDate, $endDate])
            ->join('expense_categories', 'expenses.category_id', '=', 'expense_categories.id')
            ->select(
                'expense_categories.name as category',
                'expense_categories.is_staff_related',
                DB::raw('SUM(expenses.amount) as amount')
            )
            ->groupBy('expense_categories.id', 'expense_categories.name', 'expense_categories.is_staff_related')
            ->get()
            ->map(function ($item) {
                return [
                    'category' => $item->category,
                    'amount' => (float) $item->amount,
                    'is_staff_related' => (bool) $item->is_staff_related,
                ];
            });
    }

    private function getIncomeStatementTrend($companyId)
    {
        $trend = [];
        $startDate = Carbon::now()->subMonths(11)->startOfMonth();

        for ($i = 0; $i < 12; $i++) {
            $monthStart = $startDate->copy()->addMonths($i)->startOfMonth();
            $monthEnd = $monthStart->copy()->endOfMonth();

            $income = $this->calculateInterestIncome($companyId, $monthStart, $monthEnd) +
                $this->calculatePenaltyIncome($companyId, $monthStart, $monthEnd) +
                $this->calculateOtherIncome($companyId, $monthStart, $monthEnd);

            $expenses = Expenses::where('company_id', $companyId)
                ->whereBetween('expense_date', [$monthStart, $monthEnd])
                ->sum('amount');

            $trend[] = [
                'period' => $monthStart->format('M Y'),
                'income' => (float) $income,
                'expenses' => (float) $expenses,
            ];
        }

        return $trend;
    }

    public function listAccounts()
    {
        $companyId = $this->getCompanyId();
        $accounts = Accounts::where('company', $companyId)
            ->select('id', 'account_name', 'account_balance as current_balance')
            ->get();
        return $this->successResponse($accounts, 'Accounts retrieved successfully');
    }
}
