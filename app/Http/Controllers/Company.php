<?php

namespace App\Http\Controllers;

use App\Models\Company as ModelsCompany;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class Company extends Controller
{
    public function register(Request $request){
        try {
            $validated = $request->validate([
                'company_name' => 'bail|required|string|max:255',
                'company_phone' => 'bail|required|string|max:10',
                'company_email' => 'bail|required|string|max:255',
            ]);

            $company = ModelsCompany::create($validated);

            return response()->json([
                'status' => 'success',
                'message' => 'Company created successfully',
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
        $companies = ModelsCompany::where('company_status', '!=', 3)
        ->select('id','company_name','company_phone','company_status','company_email','created_at')
        ->get();
        return response()->json(
            $companies
        );
    }
}
