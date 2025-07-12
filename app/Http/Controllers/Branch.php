<?php

namespace App\Http\Controllers;

use App\Models\BranchModel;
use App\Models\FundsAllocation;
use App\Models\Zone;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Tymon\JWTAuth\Facades\JWTAuth;

class Branch extends Controller
{
    public function register(Request $request)
    {
        try {
            $validated = $request->validate([
                'branch_name' => 'bail|required|string|max:255|unique:App\Models\BranchModel,branch_name',
                'balance' => 'bail|numeric',
            ]);
            $user = JWTAuth::parseToken()->getPayload();
            $user_company = $user->get('company');
            $user_id = $user->get('user_id');
            $validated['registered_by'] = $user_id;
            $validated['company']       = $user_company;

            $company = BranchModel::create($validated);

            return response()->json([
                'status' => 'success',
                'message' => 'Branch created successfully',
            ], 201);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'errors' => $e->errors(),
            ], 422);
        }
    }

    public function list()
    {
        $user = JWTAuth::parseToken()->getPayload();
        $user_company = $user->get('company');
        $branches = BranchModel::where('status', '!=', 3)
            ->where('company', $user_company)
            ->select('id', 'branch_name', 'balance', 'created_at')
            ->get();
        return response()->json(
            $branches
        );
    }

    public function branchDetails($branchId = null)
    {
        $user = JWTAuth::parseToken()->getPayload();
        $user_company = $user->get('company');
        $user_id = $user->get('user_id');
        $branch = BranchModel::where('id', $branchId)
            ->where('company', $user_company)
            ->where('status', '!=', 3)
            ->first();
        $funds = FundsAllocation::where('branch', $branchId)
            ->get();
        return response()->json(
            [
                'details'   => $branch,
                'funds'     => $funds
            ]
        );
    }

    

    public function update(Request $request)
    {
        try {
            $validated = $request->validate([
                'id' => 'bail|required',
                'status' => 'bail|required|in:1,2,3',
                'branch_name' => 'bail|required|string|max:255|unique:App\Models\BranchModel,branch_name,' . $request->id,
            ]);
             unset($validated['id']);
            $user = JWTAuth::parseToken()->getPayload();
            $user_company = $user->get('company');
            $user_id = $user->get('user_id');
            $branchId = $request->branch_id;
            

            $company = BranchModel::where('id', $branchId)
                ->where('company', $user_company)
                ->update($validated);

            return response()->json([
                'status' => 'success',
                'message' => 'Branch updated successfully',
            ], 201);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'errors' => $e->errors(),
            ], 422);
        }
    }

    public function fund(Request $request)
    {
        try {
            $validated = $request->validate([
                'branch_id' => 'bail|required|integer|exists:App\Models\BranchModel,id',
                'fund' => 'bail|required|numeric',
            ]);
            $user = JWTAuth::parseToken()->getPayload();
            $user_company = $user->get('company');
            $user_id = $user->get('user_id');
            //$validated['registered_by'] = $user_id;
            //$validated['company']       = $user_company;
            $branchId = $request->branch_id;

            $branch = BranchModel::where('id', $branchId)
                ->where('company', $user_company)
                ->where('status', 1)
                ->first();
            if (!$branch) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Branch not found or inactive',
                ], 422);
            }
            $branch->increment('balance', $validated['fund']);
            FundsAllocation::create([
                'branch' => $branchId,
                'allocated_amount' => $validated['fund'],
                'allocated_by' => $user_id,
                'company' => $user_company,
            ]);
            return response()->json([
                'status' => 'success',
                'message' => 'Branch fund update successfully',
            ], 201);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'errors' => $e->errors(),
            ], 422);
        }
    }
}
