<?php
// app/Http/Controllers/Api/V2/PlanManagementController.php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Models\PlanDiscount;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class PlanManagementController extends BaseController
{
   

    /**
     * Get all plans with their discounts
     */
    public function index()
    {
        $plans = Plan::with('discounts')->orderBy('price', 'asc')->get();
        
        return response()->json([
            'success' => true,
            'data' => $plans
        ]);
    }

    /**
     * Create a new plan
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255|unique:plans',
            'price' => 'required|numeric|min:0',
            'customer_limit' => 'nullable|integer|min:0',
            'branch_limit' => 'nullable|integer|min:0',
            'zone_limit' => 'nullable|integer|min:0',
            'user_limit' => 'nullable|integer|min:0',
            'loans_limit' => 'nullable|integer|min:0',
            'description' => 'nullable|string',
            'telegram_notifications' => 'boolean',
            'sms_notifications' => 'boolean',
            'trace_customer' => 'boolean',
            'has_advanced_reports' => 'boolean',
            'has_support_tickets' => 'boolean',
            'has_api_access' => 'boolean',
            'has_export_data' => 'boolean',
            'has_mobile_app' => 'boolean',
            'has_audit_logs' => 'boolean',
            'has_custom_reports' => 'boolean',
            'has_multi_currency' => 'boolean',
            'has_bulk_operations' => 'boolean',
            'has_priority_support' => 'boolean',
            'discounts' => 'nullable|array',
            'discounts.*.duration_months' => 'required|integer|in:1,3,6,12,24',
            'discounts.*.discount_percentage' => 'required|numeric|min:0|max:100',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $plan = Plan::create($request->except('discounts'));

        // Create discounts
        if ($request->has('discounts')) {
            foreach ($request->discounts as $discount) {
                PlanDiscount::create([
                    'plan_id' => $plan->id,
                    'duration_months' => $discount['duration_months'],
                    'discount_percentage' => $discount['discount_percentage'],
                    'is_active' => true
                ]);
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Plan created successfully',
            'data' => $plan->load('discounts')
        ], 201);
    }

    /**
     * Update a plan
     */
    public function update(Request $request, $id)
    {
        $plan = Plan::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255|unique:plans,name,' . $id,
            'price' => 'required|numeric|min:0',
            'customer_limit' => 'nullable|integer|min:0',
            'branch_limit' => 'nullable|integer|min:0',
            'zone_limit' => 'nullable|integer|min:0',
            'user_limit' => 'nullable|integer|min:0',
            'loans_limit' => 'nullable|integer|min:0',
            'description' => 'nullable|string',
            'telegram_notifications' => 'boolean',
            'sms_notifications' => 'boolean',
            'trace_customer' => 'boolean',
            'has_advanced_reports' => 'boolean',
            'has_support_tickets' => 'boolean',
            'has_api_access' => 'boolean',
            'has_export_data' => 'boolean',
            'has_mobile_app' => 'boolean',
            'has_audit_logs' => 'boolean',
            'has_custom_reports' => 'boolean',
            'has_multi_currency' => 'boolean',
            'has_bulk_operations' => 'boolean',
            'has_priority_support' => 'boolean',
            'discounts' => 'nullable|array',
            'discounts.*.duration_months' => 'required|integer|in:1,3,6,12,24',
            'discounts.*.discount_percentage' => 'required|numeric|min:0|max:100',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $plan->update($request->except('discounts'));

        // Update discounts
        if ($request->has('discounts')) {
            foreach ($request->discounts as $discount) {
                PlanDiscount::updateOrCreate(
                    [
                        'plan_id' => $plan->id,
                        'duration_months' => $discount['duration_months']
                    ],
                    [
                        'discount_percentage' => $discount['discount_percentage'],
                        'is_active' => true
                    ]
                );
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Plan updated successfully',
            'data' => $plan->load('discounts')
        ]);
    }

    /**
     * Delete a plan
     */
    public function destroy($id)
    {
        $plan = Plan::findOrFail($id);
        
        if ($plan->subscriptions()->count() > 0) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot delete plan because it has active subscriptions'
            ], 422);
        }
        
        // Delete associated discounts first
        $plan->discounts()->delete();
        $plan->delete();
        
        return response()->json([
            'success' => true,
            'message' => 'Plan deleted successfully'
        ]);
    }

    /**
     * Get plan details with discounts
     */
    public function show($id)
    {
        $plan = Plan::with('discounts')->findOrFail($id);
        
        return response()->json([
            'success' => true,
            'data' => $plan
        ]);
    }

    /**
     * Update specific discount for a plan
     */
    public function updateDiscount(Request $request, $planId, $durationMonths)
    {
        $validator = Validator::make($request->all(), [
            'discount_percentage' => 'required|numeric|min:0|max:100',
            'is_active' => 'sometimes|boolean'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $discount = PlanDiscount::updateOrCreate(
            [
                'plan_id' => $planId,
                'duration_months' => $durationMonths
            ],
            [
                'discount_percentage' => $request->discount_percentage,
                'is_active' => $request->get('is_active', true)
            ]
        );

        return response()->json([
            'success' => true,
            'message' => 'Discount updated successfully',
            'data' => $discount
        ]);
    }
}