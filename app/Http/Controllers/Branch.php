<?php

namespace App\Http\Controllers;

use App\Models\BranchModel;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Tymon\JWTAuth\Facades\JWTAuth;

class Branch extends Controller
{
    public function register(Request $request){
        try {
            $validated = $request->validate([
                'branch_name' => 'bail|required|string|max:255',
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
                'module' => $company->company_name,
            ], 201);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'errors' => $e->errors(),
            ], 422);
        }
    }

    public function list() {
        $user = JWTAuth::parseToken()->getPayload();
        $user_company = $user->get('company');
        $branches = BranchModel::where('status', '!=', 3)
        ->where('company',$user_company)
        ->select('id','branch_name','balance','created_at')
        ->get();
        return response()->json(
            $branches
        );
    }
}
