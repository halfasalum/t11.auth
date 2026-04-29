<?php

namespace App\Http\Controllers;

use App\Models\Income;
use App\Models\IncomeCategory;
use Illuminate\Http\Request;
use Tymon\JWTAuth\Facades\JWTAuth;
use Illuminate\Validation\ValidationException;

class IncomeController
{
    public function list()
    {
        $user = JWTAuth::parseToken()->getPayload();
        $user_company = $user->get('company');
        $income = Income::where('income_company', $user_company)
            ->join('income_categories', 'income_categories.id', '=', 'incomes.income_category')
            ->get();
        $categories = IncomeCategory::where('category_company', $user_company)
            ->where('category_status', '!=', 3)
            ->get();
        return response()->json([
            'incomes' => $income,
            'categories' => $categories
        ]);
    }


    public function registeriCategory(Request $request)
    {
        try {
            $validated = $request->validate([
                'category_name' => 'bail|required',
                'loan_related' => 'bail|required',
            ]);
            $user = JWTAuth::parseToken()->getPayload();
            $user_company = $user->get('company');
            $validated['category_company']       = $user_company;

            IncomeCategory::create($validated);
            return response()->json([
                'status' => 'success',
                'message' => 'Income category created successfully',
            ], 201);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'errors' => $e->errors(),
            ], 422);
        }
    }

    public function registerIncome(Request $request)
    {
        try {
            $validated = $request->validate([
                'income_category' => 'bail|required',
                'income_date' => 'bail|required',
                'income_amount' => 'bail|required|numeric|min:1',
                'loan_number'   => 'bail|nullable',
                'income_branch'   => 'bail|required',
                'income_description'   => 'bail|nullable',
                'paid_account'   => 'bail|required',
            ]);
            $user = JWTAuth::parseToken()->getPayload();
            $user_company = $user->get('company');
            $user_id = $user->get('user_id');
            $validated['income_company']       = $user_company;
            $validated['registered_by']       = $user_id;

            Income::create($validated);
            return response()->json([
                'status' => 'success',
                'message' => 'Income  created successfully',
            ], 201);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'errors' => $e->errors(),
            ], 422);
        }
    }

    public function categoryList()
    {
        $user = JWTAuth::parseToken()->getPayload();
        $user_company = $user->get('company');
        $list = [];
        $categories = IncomeCategory::where('category_company', $user_company)
            ->where('category_status', 1)
            ->get();
        if (sizeof($categories) > 0) {
            $list = $categories;
        }
        return response()->json($list);
    }
}
