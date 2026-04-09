<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Categories;
use App\Models\Expenses;
use App\Models\User;
use Illuminate\Http\Request;
use Tymon\JWTAuth\Facades\JWTAuth;
use Illuminate\Validation\ValidationException;

class ExpenseController
{
    public function list()
    {
        $expenses = $this->listExpenses();
        $categories = $this->listExpenseCategories();
        return response()->json([
            'expenses' => $expenses,
            'categories' => $categories['categories'],
        ]);
    }

    public function listExpenses()
    {
        $user = JWTAuth::parseToken()->getPayload();
        $user_company = $user->get('company');
        $expenses = Expenses::where(['expenses.company_id'=> $user_company])
        ->join('expense_categories', 'expense_categories.id', '=', 'expenses.category_id')
        ->get();
        $expensesCollections = collect($expenses);
        $expenseUsers = $expensesCollections->pluck('user_id')->unique();
        $expenseBranches = $expensesCollections->pluck('branch_id')->unique();
        $users = User::where(['user_company'=> $user_company])
        ->whereIn('id', $expenseUsers)
        ->get();
        $branches = Branch::where(['company'=> $user_company])
        ->whereIn('id', $expenseBranches)
        ->get();
        $expenses = $expenses->map(function ($expense) use ($users, $branches) {
            $user = $users->where('id', $expense->user_id)->first();
            $branch = $branches->where('id', $expense->branch_id)->first();
            return [
                'id' => $expense->id,
                'amount' => $expense->amount,
                'description' => $expense->description,
                'category' => $expense->name,
                'user' => $user ? $user->first_name.' '.$user->middle_name.' '.$user->last_name : null,
                'branch' => $branch ? $branch->branch_name : null,
                'expense_date' => $expense->expense_date,
                'created_at' => $expense->created_at,
            ];
        });

        return $expenses;
    }

    public function listExpenseCategories()
    {
        $user = JWTAuth::parseToken()->getPayload();
        $user_company = $user->get('company');
        $categories = Categories::where(['company_id'=> $user_company, 'status'=>1])->get();
        $bank = new BankController();
        $accounts = $bank->listActiveAccounts();
        return 
            [
                'categories' => $categories,
                'accounts' => $accounts
            ]
        ;
    }

    public function registerCategory(Request $request)
    {
        try {
            $validated = $request->validate([
                'name' => 'bail|required|string',
                'is_staff_related' => 'bail|required|boolean',
            ]);
            $user = JWTAuth::parseToken()->getPayload();
            $user_company = $user->get('company');
            $user_id = $user->get('user_id');
            $customer = $request->customer;
            $validated['company_id'] = $user_company;
            $company = Categories::create($validated);
            return response()->json([
                'status' => 'success',
                'message' => 'Expense cateogory created successfully',
            ], 201);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'errors' => $e->errors(),
            ], 422);
        }
    }

    public function registerExpense(Request $request)
    {
        try{
            $user = JWTAuth::parseToken()->getPayload();
            $user_company = $user->get('company');
            $user_id = $user->get('user_id');
            $validated = $request->validate([
                'expense_date' => 'bail|required|date|before_or_equal:today',
                'amount' => 'bail|required|numeric',
                'category_id' => 'bail|required',
                'isStaffRelated' => 'bail|required',
                'user_id' => 'bail|nullable|required_if:isStaffRelated,1',
                'branch_id' => 'bail|required',
                'description' => 'bail|nullable',
                'paid_account' => 'bail|required|integer'
            ]);
            $validated['company_id'] = $user_company;
            $validated['registered_by'] = $user_id;
            if($validated['isStaffRelated'] == 1){
                $validated['user_id'] = $validated['user_id'];
                unset($validated['isStaffRelated']);
            }else{
                unset($validated['isStaffRelated']); 
            }
            Expenses::create($validated);
            $bank = new BankController();
            $bank->registerTransaction(
                $validated['paid_account'],
                $validated['amount'],
                false,
                $validated['expense_date'],
                false,
                $validated['branch_id'],
                null,
                null,
                null,
                null
            );
            return response()->json([
                'status' => 'success',
                'message' => 'Expense  created successfully',
            ], 201);
        }catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'errors' => $e->errors(),
            ], 422);
        }
    }
}
