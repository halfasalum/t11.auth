<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Categories;
use App\Models\Expenses;
use App\Models\User;
use Illuminate\Http\Request;
use Tymon\JWTAuth\Facades\JWTAuth;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class ExpenseController extends Controller
{

    /**
     * List expenses and categories with filters
     */
    public function list(Request $request)
    {
        try {
            $user = JWTAuth::parseToken()->getPayload();
            $userCompany = $user->get('company');

            // Get filters
            $startDate = $request->get('start_date');
            $endDate = $request->get('end_date');
            $categoryId = $request->get('category_id');
            $branchId = $request->get('branch_id');

            // Build expenses query - use get() with proper object return
            $expensesQuery = Expenses::where('expenses.company_id', $userCompany)
                ->join('expense_categories', 'expense_categories.id', '=', 'expenses.category_id')
                ->select(
                    'expenses.*',
                    'expense_categories.name as category_name',
                    'expense_categories.is_staff_related'
                );

            // Apply filters
            if ($startDate) {
                $expensesQuery->whereDate('expense_date', '>=', $startDate);
            }
            if ($endDate) {
                $expensesQuery->whereDate('expense_date', '<=', $endDate);
            }
            if ($categoryId) {
                $expensesQuery->where('category_id', $categoryId);
            }
            if ($branchId) {
                $expensesQuery->where('branch_id', $branchId);
            }

            $expenses = $expensesQuery->orderBy('expense_date', 'desc')->get();

            // Get related data
            $userIds = $expenses->pluck('user_id')->filter()->unique()->values();
            $branchIds = $expenses->pluck('branch_id')->unique()->values();

            $users = collect();
            if ($userIds->isNotEmpty()) {
                $users = User::where('user_company', $userCompany)
                    ->whereIn('id', $userIds)
                    ->get()
                    ->keyBy('id');
            }

            $branches = collect();
            if ($branchIds->isNotEmpty()) {
                $branches = Branch::where('company', $userCompany)
                    ->whereIn('id', $branchIds)
                    ->get()
                    ->keyBy('id');
            }

            // Format expenses - ensure we're working with objects
            $formattedExpenses = collect();
            foreach ($expenses as $expense) {
                $user = $users->get($expense->user_id);
                $branch = $branches->get($expense->branch_id);

                $formattedExpenses->push([
                    'id' => $expense->id,
                    'amount' => (float) $expense->amount,
                    'description' => $expense->description,
                    'category' => $expense->category_name,
                    'category_id' => $expense->category_id,
                    'is_staff_related' => $expense->is_staff_related,
                    'user' => $user ? ($user->first_name . ' ' . $user->last_name) : null,
                    'user_id' => $expense->user_id,
                    'branch' => $branch ? $branch->branch_name : null,
                    'branch_id' => $expense->branch_id,
                    'expense_date' => $expense->expense_date,
                    'created_at' => $expense->created_at,
                    'registered_by' => $expense->registered_by,
                ]);
            }

            // Get categories
            $categories = Categories::where('company_id', $userCompany)
                ->orderBy('name')
                ->get();

            // Get branches for filter
            $allBranches = Branch::where('company', $userCompany)
                ->select('id', 'branch_name')
                ->get();

            // Get accounts
            $bank = new BankController();
            $accountsResponse = $bank->listActiveAccounts();
            $accounts = [];
            if ($accountsResponse && method_exists($accountsResponse, 'getData')) {
                $accounts = $accountsResponse->getData(true);
            } elseif (is_array($accountsResponse)) {
                $accounts = $accountsResponse;
            }

            // Calculate summary statistics
            $totalExpenses = $formattedExpenses->sum('amount');
            $totalTransactions = $formattedExpenses->count();
            $avgExpense = $totalTransactions > 0 ? $totalExpenses / $totalTransactions : 0;

            // Monthly trend
            $monthlyTrend = [];
            foreach ($formattedExpenses as $expense) {
                if (isset($expense['expense_date'])) {
                    $month = Carbon::parse($expense['expense_date'])->format('Y-m');
                    if (!isset($monthlyTrend[$month])) {
                        $monthlyTrend[$month] = 0;
                    }
                    $monthlyTrend[$month] += $expense['amount'];
                }
            }
            ksort($monthlyTrend);
            $monthlyTrend = array_slice($monthlyTrend, -6, 6, true);

            // Category breakdown
            $categoryBreakdown = [];
            foreach ($formattedExpenses as $expense) {
                $category = $expense['category'];
                if (!isset($categoryBreakdown[$category])) {
                    $categoryBreakdown[$category] = [
                        'total' => 0,
                        'count' => 0,
                        'percentage' => 0,
                    ];
                }
                $categoryBreakdown[$category]['total'] += $expense['amount'];
                $categoryBreakdown[$category]['count']++;
            }

            // Calculate percentages
            foreach ($categoryBreakdown as &$category) {
                $category['percentage'] = $totalExpenses > 0
                    ? round(($category['total'] / $totalExpenses) * 100, 2)
                    : 0;
            }

            // Staff vs General expenses
            $staffExpenses = 0;
            $generalExpenses = 0;
            foreach ($formattedExpenses as $expense) {
                if ($expense['is_staff_related'] == 1) {
                    $staffExpenses += $expense['amount'];
                } else {
                    $generalExpenses += $expense['amount'];
                }
            }

            // Current month expenses
            $currentMonth = Carbon::now()->format('Y-m');
            $currentMonthExpense = $monthlyTrend[$currentMonth] ?? 0;

            // Sort categories by total descending and take top 5
            $topCategories = collect($categoryBreakdown)->sortByDesc('total')->take(5);

            return response()->json([
                'success' => true,
                'data' => [
                    'expenses' => $formattedExpenses,
                    'categories' => $categories,
                    'branches' => $allBranches,
                    'accounts' => $accounts,
                    'summary' => [
                        'total_expenses' => (float) $totalExpenses,
                        'total_transactions' => $totalTransactions,
                        'average_expense' => (float) $avgExpense,
                        'current_month_expense' => (float) $currentMonthExpense,
                        'staff_expenses' => (float) $staffExpenses,
                        'general_expenses' => (float) $generalExpenses,
                        'staff_percentage' => $totalExpenses > 0 ? round(($staffExpenses / $totalExpenses) * 100, 2) : 0,
                    ],
                    'insights' => [
                        'monthly_trend' => $monthlyTrend,
                        'category_breakdown' => $categoryBreakdown,
                        'top_categories' => $topCategories,
                    ],
                ],
                'message' => 'Expense data retrieved successfully',
            ], 200);
        } catch (\Exception $e) {
            Log::error('Failed to fetch expenses: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'line' => $e->getLine(),
                'file' => $e->getFile()
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch expenses: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * List expense categories
     */
    public function listCategories(Request $request)
    {
        try {
            $user = JWTAuth::parseToken()->getPayload();
            $userCompany = $user->get('company');

            $categories = Categories::where('company_id', $userCompany)
                ->orderBy('name')
                ->get();

            return response()->json([
                'success' => true,
                'data' => $categories,
                'message' => 'Categories retrieved successfully',
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch categories',
            ], 500);
        }
    }

    /**
     * Register expense category
     */
    public function registerCategory(Request $request)
    {
        try {
            $validated = $request->validate([
                'name' => 'required|string|max:255|unique:expense_categories,name,NULL,id,company_id,' . $this->getCompanyId(),
                'is_staff_related' => 'required|boolean',
            ]);

            $user = JWTAuth::parseToken()->getPayload();
            $userCompany = $user->get('company');

            $category = Categories::create([
                'name' => $validated['name'],
                'is_staff_related' => $validated['is_staff_related'],
                'status' => 1,
                'company_id' => $userCompany,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Expense category created successfully',
                'data' => $category,
            ], 201);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to create category: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Register expense
     */
    public function registerExpense(Request $request)
    {
        try {
            $user = JWTAuth::parseToken()->getPayload();
            $userCompany = $user->get('company');
            $userId = $user->get('user_id');

            $validated = $request->validate([
                'expense_date' => 'required|date|before_or_equal:today',
                'amount' => 'required|numeric|min:0.01',
                'category' => 'required|integer|exists:expense_categories,id',
                'branch' => 'required|integer|exists:branches,id',
                'staff' => 'nullable|integer|exists:users,id',
                'description' => 'nullable|string|max:1000',
                'paid_account' => 'required|integer|exists:accounts,id',
            ]);

            // Get category to check if staff is required
            $category = Categories::find($validated['category']);
            if ($category->is_staff_related == 1 && empty($validated['staff'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Staff member is required for this expense category',
                ], 422);
            }

            DB::beginTransaction();

            // Create expense record
            $expense = Expenses::create([
                'expense_date' => $validated['expense_date'],
                'category_id' => $validated['category'],
                'user_id' => $validated['staff'] ?? null,
                'description' => $validated['description'],
                'amount' => $validated['amount'],
                'status' => 1,
                'company_id' => $userCompany,
                'branch_id' => $validated['branch'],
                'registered_by' => $userId,
            ]);

            // Register bank transaction (debit)
            $bank = new BankController();
            $bank->registerTransaction(
                $validated['paid_account'],
                $validated['amount'],
                false, // is_income = false (expense)
                $validated['expense_date'],
                false, // is_reverse
                $validated['branch'],
                null, // zone
                null, // loan_number
                null, // customer
                null  // schedule_id
            );

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Expense registered successfully',
                'data' => $expense,
            ], 201);
        } catch (ValidationException $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Expense registration failed: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to register expense: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Enable expense category
     */
    public function enableCategory(Request $request)
    {
        try {
            $validated = $request->validate([
                'id' => 'required|integer|exists:expense_categories,id',
            ]);

            $user = JWTAuth::parseToken()->getPayload();
            $userCompany = $user->get('company');

            $category = Categories::where('id', $validated['id'])
                ->where('company_id', $userCompany)
                ->first();

            if (!$category) {
                return response()->json([
                    'success' => false,
                    'message' => 'Category not found',
                ], 404);
            }

            $category->update(['status' => 1]);

            return response()->json([
                'success' => true,
                'message' => 'Category enabled successfully',
            ], 200);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to enable category',
            ], 500);
        }
    }

    /**
     * Disable expense category
     */
    public function disableCategory(Request $request)
    {
        try {
            $validated = $request->validate([
                'id' => 'required|integer|exists:expense_categories,id',
            ]);

            $user = JWTAuth::parseToken()->getPayload();
            $userCompany = $user->get('company');

            $category = Categories::where('id', $validated['id'])
                ->where('company_id', $userCompany)
                ->first();

            if (!$category) {
                return response()->json([
                    'success' => false,
                    'message' => 'Category not found',
                ], 404);
            }

            $category->update(['status' => 0]);

            return response()->json([
                'success' => true,
                'message' => 'Category disabled successfully',
            ], 200);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to disable category',
            ], 500);
        }
    }

    /**
     * Get users dropdown for staff selection
     */
    public function getUsersDropdown()
    {
        try {
            $user = JWTAuth::parseToken()->getPayload();
            $userCompany = $user->get('company');

            $users = User::where('user_company', $userCompany)
                ->where('status', 1)
                ->select('id', 'first_name', 'last_name', 'email')
                ->orderBy('first_name')
                ->get();

            return response()->json([
                'success' => true,
                'data' => $users,
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch users',
            ], 500);
        }
    }

    /**
     * Get company ID from JWT
     */
    private function getCompanyId()
    {
        $user = JWTAuth::parseToken()->getPayload();
        return $user->get('company');
    }
}
