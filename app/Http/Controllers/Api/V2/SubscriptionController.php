<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Company;
use App\Models\CustomersZone;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\SubscriptionOrder;
use App\Models\User;
use App\Models\Zone;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class SubscriptionController extends BaseController
{


    /**
     * Get available plans
     */
    public function getPlans()
    {
        $plans = Plan::with('discounts')->orderBy('price', 'asc')->get();

        // Transform plans to include all necessary data
        $plansData = $plans->map(function ($plan) {
            return [
                'id' => $plan->id,
                'name' => $plan->name,
                'price' => $plan->price,
                'description' => $plan->description,

                // Limits
                'customer_limit' => $plan->customer_limit,
                'branch_limit' => $plan->branch_limit,
                'zone_limit' => $plan->zone_limit,
                'user_limit' => $plan->user_limit,
                'loans_limit' => $plan->loans_limit,

                // Feature toggles
                'telegram_notifications' => (bool) $plan->telegram_notifications,
                'sms_notifications' => (bool) $plan->sms_notifications,
                'trace_customer' => (bool) $plan->trace_customer,
                'has_advanced_reports' => (bool) $plan->has_advanced_reports,
                'has_support_tickets' => (bool) $plan->has_support_tickets,
                'has_api_access' => (bool) $plan->has_api_access,
                'has_export_data' => (bool) $plan->has_export_data,
                'has_mobile_app' => (bool) $plan->has_mobile_app,
                'has_audit_logs' => (bool) $plan->has_audit_logs,
                'has_custom_reports' => (bool) $plan->has_custom_reports,
                'has_multi_currency' => (bool) $plan->has_multi_currency,
                'has_bulk_operations' => (bool) $plan->has_bulk_operations,
                'has_priority_support' => (bool) $plan->has_priority_support,

                // Discounts
                'discounts' => $plan->discounts->map(function ($discount) {
                    return [
                        'id' => $discount->id,
                        'duration_months' => $discount->duration_months,
                        'discount_percentage' => (float) $discount->discount_percentage,
                        'is_active' => (bool) $discount->is_active,
                    ];
                }),

                // Additional computed fields
                'features_list' => $plan->features_list,
                'formatted_price' => number_format($plan->price, 0, ',', '.') . ' TZS',
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $plansData
        ]);
    }


    /**
     * Get current subscription status with full details
     */
    public function getCurrentSubscription(Request $request)
    {
        $user = $request->user();
        $companyId = $this->getCompanyId();

        $subscription = Subscription::with(['plan.discounts', 'plan', 'subscriptionOrder'])
            ->where('company_id', $companyId)
            ->where('status', 'active')
            ->first();

        if (!$subscription) {
            return response()->json([
                'success' => true,
                'data' => null,
                'message' => 'No active subscription found'
            ]);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'subscription' => [
                    'id' => $subscription->id,
                    'status' => $subscription->status,
                    'start_date' => $subscription->start_date,
                    'end_date' => $subscription->end_date,
                    'plan' => [
                        'id' => $subscription->plan->id,
                        'name' => $subscription->plan->name,
                        'price' => $subscription->plan->price,
                        'description' => $subscription->plan->description,
                        'features' => [
                            'customer_limit' => $subscription->plan->customer_limit,
                            'branch_limit' => $subscription->plan->branch_limit,
                            'zone_limit' => $subscription->plan->zone_limit,
                            'user_limit' => $subscription->plan->user_limit,
                            'has_advanced_reports' => (bool) $subscription->plan->has_advanced_reports,
                            'has_support_tickets' => (bool) $subscription->plan->has_support_tickets,
                            'has_api_access' => (bool) $subscription->plan->has_api_access,
                            'has_export_data' => (bool) $subscription->plan->has_export_data,
                            'has_mobile_app' => (bool) $subscription->plan->has_mobile_app,
                            'has_priority_support' => (bool) $subscription->plan->has_priority_support,
                        ],
                    ],
                    'order' => $subscription->subscriptionOrder ? [
                        'order_number' => $subscription->subscriptionOrder->order_number,
                        'amount' => $subscription->subscriptionOrder->amount,
                        'duration_months' => $subscription->subscriptionOrder->duration_months,
                    ] : null,
                ],
                'is_active' => $subscription->is_active,
                'days_remaining' => $subscription->days_remaining,
                'usage' => [
                    'customers' => $this->getCurrentCustomerCount($companyId),
                    'branches' => $this->getCurrentBranchCount($companyId),
                    'zones' => $this->getCurrentZoneCount($companyId),
                    'users' => $this->getCurrentUserCount($companyId),
                ]
            ]
        ]);
    }



    /**
     * Submit subscription order with receipt
     */
    public function submitOrder(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'plan_id' => 'required|exists:plans,id',
            'duration_months' => 'required|integer|min:1|max:60', // Added duration validation
            'receipt_number' => 'required|string|max:255',
            'payment_notes' => 'nullable|string|max:1000',
            'receipt_file' => 'nullable|file|mimes:jpeg,png,jpg,pdf|max:5120',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $user = $request->user();
        $companyId = $this->getCompanyId();
        $plan = Plan::find($request->plan_id);
        $durationMonths = $request->duration_months;

        // Calculate total amount with discount
        $monthlyPrice = floatval($plan->price);
        $subtotal = $monthlyPrice * $durationMonths;
        $discount = $this->calculateDiscount($durationMonths, $subtotal);
        $totalAmount = $subtotal - $discount;

        DB::beginTransaction();
        try {
            // Create order with duration and calculated amount
            $order = SubscriptionOrder::create([
                'order_number' => SubscriptionOrder::generateOrderNumber(),
                'company_id' => $companyId,
                'plan_id' => $request->plan_id,
                'duration_months' => $durationMonths, // Add this column to migration
                'receipt_number' => $request->receipt_number,
                'payment_notes' => $request->payment_notes,
                'amount' => $totalAmount,
                'subtotal' => $subtotal, // Add this column to migration
                'discount' => $discount, // Add this column to migration
                'currency' => 'TZS',
                'status' => 'pending',
                'payment_date' => now(),
            ]);

            // Handle receipt file upload
            if ($request->hasFile('receipt_file')) {
                $file = $request->file('receipt_file');
                $path = $file->store('subscription-receipts/' . $order->id, 'public');
                $order->receipt_file = $path;
                $order->save();
            }

            // Create pending subscription record
            $subscription = Subscription::create([
                'company_id' => $companyId,
                'plan_id' => $request->plan_id,
                'subscription_order_id' => $order->id,
                'status' => 'pending',
                'start_date' => null,
                'end_date' => null,
            ]);

            $order->subscription_id = $subscription->id;
            $order->save();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Subscription order submitted successfully. Awaiting admin approval.',
                'data' => $order->load(['plan', 'company'])
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to submit order: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Calculate discount based on duration
     */
    private function calculateDiscount($plan, $months)
    {
        // Get discount from plan's custom discounts
        $discountPercent = $plan->getDiscountForDuration($months);

        $monthlyPrice = floatval($plan->price);
        $subtotal = $monthlyPrice * $months;
        return ($subtotal * $discountPercent) / 100;
    }

    /**
     * Get order history for company
     */
    public function getOrderHistory(Request $request)
    {
        $user = $request->user();
        $companyId = $this->getCompanyId();

        $orders = SubscriptionOrder::with(['plan', 'approvedBy'])
            ->where('company_id', $companyId)
            ->orderBy('created_at', 'desc')
            ->paginate($request->get('per_page', 15));

        return response()->json([
            'success' => true,
            'data' => $orders
        ]);
    }

    /**
     * Get single order details
     */
    public function getOrder($id)
    {
        $user = request()->user();
        $companyId = $user->company_id;

        $order = SubscriptionOrder::with(['plan', 'approvedBy', 'subscription'])
            ->where('company_id', $companyId)
            ->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $order
        ]);
    }

    // ==================== ADMIN ONLY METHODS ====================

    /**
     * Get all pending orders for admin
     */
    public function getPendingOrders(Request $request)
    {
        $this->authorizeAdmin();

        $orders = SubscriptionOrder::with(['company', 'plan', 'approvedBy'])
            ->pending()
            ->orderBy('created_at', 'asc')
            ->paginate($request->get('per_page', 20));

        return response()->json([
            'success' => true,
            'data' => $orders
        ]);
    }

    /**
     * Get all orders with filters for admin
     */
    public function getAllOrders(Request $request)
    {
        $this->authorizeAdmin();

        $query = SubscriptionOrder::with(['company', 'plan', 'approvedBy']);

        if ($request->has('status') && $request->status) {
            $query->where('status', $request->status);
        }

        if ($request->has('company_id') && $request->company_id) {
            $query->where('company_id', $request->company_id);
        }

        if ($request->has('from_date') && $request->from_date) {
            $query->whereDate('created_at', '>=', $request->from_date);
        }

        if ($request->has('to_date') && $request->to_date) {
            $query->whereDate('created_at', '<=', $request->to_date);
        }

        if ($request->has('search') && $request->search) {
            $query->where(function ($q) use ($request) {
                $q->where('order_number', 'like', "%{$request->search}%")
                    ->orWhere('receipt_number', 'like', "%{$request->search}%");
            });
        }

        $orders = $query->orderBy('created_at', 'desc')
            ->paginate($request->get('per_page', 20));

        return response()->json([
            'success' => true,
            'data' => $orders
        ]);
    }

    /**
     * Verify receipt (first step - check receipt validity)
     */
    public function verifyOrder(Request $request, $id)
    {
        $this->authorizeAdmin();

        $order = SubscriptionOrder::findOrFail($id);

        if ($order->status !== 'pending') {
            return response()->json([
                'success' => false,
                'message' => 'Order already processed'
            ], 422);
        }

        $validator = Validator::make($request->all(), [
            'admin_notes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        DB::beginTransaction();
        try {
            $order->status = 'verified';
            $order->admin_notes = $request->admin_notes;
            $order->save();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Receipt verified successfully',
                'data' => $order
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to verify order: ' . $e->getMessage()
            ], 500);
        }
    }


    /**
     * Approve order and activate subscription
     */
    public function approveOrder(Request $request, $id)
    {
        $this->authorizeAdmin();

        $order = SubscriptionOrder::with(['plan', 'company'])->findOrFail($id);

        if (!in_array($order->status, ['pending', 'verified'])) {
            return response()->json([
                'success' => false,
                'message' => 'Order cannot be approved in current status'
            ], 422);
        }

        $validator = Validator::make($request->all(), [
            'duration_months' => 'required|integer|min:1|max:60',
            'start_date' => 'required|date|after_or_equal:today',
            'admin_notes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        DB::beginTransaction();
        try {
            $user = $request->user();

            // Calculate end date based on start date and duration
            $startDate = \Carbon\Carbon::parse($request->start_date);
            $endDate = $startDate->copy()->addMonths($request->duration_months);

            // Update order
            $order->status = 'approved';
            $order->approved_by = $user->id;
            $order->approved_at = now();
            $order->start_date = $startDate;
            $order->end_date = $endDate;
            $order->duration_months = $request->duration_months;
            if ($request->admin_notes) {
                $order->admin_notes = $request->admin_notes;
            }
            $order->save();

            // Update or create subscription
            $subscription = Subscription::updateOrCreate(
                ['company_id' => $order->company_id, 'subscription_order_id' => $order->id],
                [
                    'plan_id' => $order->plan_id,
                    'status' => 'active',
                    'start_date' => $startDate,
                    'end_date' => $endDate,
                    'features' => [
                        'customer_limit' => $order->plan->customer_limit,
                        'branch_limit' => $order->plan->branch_limit,
                        'zone_limit' => $order->plan->zone_limit,
                        'user_limit' => $order->plan->user_limit,
                    ]
                ]
            );

            // Deactivate any other active subscriptions for this company
            Subscription::where('company_id', $order->company_id)
                ->where('id', '!=', $subscription->id)
                ->where('status', 'active')
                ->update(['status' => 'expired']);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Order approved and subscription activated successfully',
                'data' => [
                    'order' => $order,
                    'subscription' => $subscription
                ]
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to approve order: ' . $e->getMessage()
            ], 500);
        }
    }


    /**
     * Reject order
     */
    public function rejectOrder(Request $request, $id)
    {
        $this->authorizeAdmin();

        $order = SubscriptionOrder::findOrFail($id);

        if ($order->status !== 'pending') {
            return response()->json([
                'success' => false,
                'message' => 'Only pending orders can be rejected'
            ], 422);
        }

        $validator = Validator::make($request->all(), [
            'rejection_reason' => 'required|string|max:1000',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        DB::beginTransaction();
        try {
            $user = $request->user();

            $order->status = 'rejected';
            $order->rejection_reason = $request->rejection_reason;
            $order->approved_by = $user->id;
            $order->approved_at = now();
            $order->save();

            // Update related subscription
            if ($order->subscription) {
                $order->subscription->status = 'cancelled';
                $order->subscription->save();
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Order rejected successfully',
                'data' => $order
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to reject order: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get subscription statistics for admin
     */
    public function getAdminStats()
    {
        $this->authorizeAdmin();

        $stats = [
            'pending_orders' => SubscriptionOrder::pending()->count(),
            'verified_orders' => SubscriptionOrder::verified()->count(),
            'approved_orders' => SubscriptionOrder::approved()->count(),
            'rejected_orders' => SubscriptionOrder::where('status', 'rejected')->count(),
            'active_subscriptions' => Subscription::active()->count(),
            'expiring_soon' => Subscription::expiringSoon(30)->count(),
            'total_revenue' => SubscriptionOrder::approved()->sum('amount'),
            'monthly_revenue' => SubscriptionOrder::approved()
                ->whereMonth('approved_at', now()->month)
                ->sum('amount'),
        ];

        return response()->json([
            'success' => true,
            'data' => $stats
        ]);
    }

    // ==================== HELPER METHODS ====================

    private function authorizeAdmin()
    {
        if (!$this->hasPermission(1)) {
            abort(403, 'Unauthorized access. Admin only.');
        }
    }

    private function getCurrentCustomerCount($companyId)
    {
        return CustomersZone::where('company_id', $companyId)->count();
    }

    private function getCurrentBranchCount($companyId)
    {
        return Branch::where('company', $companyId)->count();
    }

    private function getCurrentZoneCount($companyId)
    {
        return Zone::where('company', $companyId)->count();
    }

    private function getCurrentUserCount($companyId)
    {
        return User::where('user_company', $companyId)->count();
    }


    /**
     * Get all company subscriptions for admin
     */
    public function getCompanySubscriptions(Request $request)
    {
        //$this->authorizeAdmin();

        $query = Subscription::with(['company', 'plan', 'subscriptionOrder'])
            ->whereHas('company');

        // Apply filters
        if ($request->has('status') && $request->status) {
            if ($request->status === 'active') {
                $query->where('status', 'active')
                    ->where('end_date', '>=', now());
            } elseif ($request->status === 'expiring_soon') {
                $query->where('status', 'active')
                    ->where('end_date', '<=', now()->addDays(30))
                    ->where('end_date', '>=', now());
            } else {
                $query->where('status', $request->status);
            }
        }

        if ($request->has('search') && $request->search) {
            $search = $request->search;
            $query->whereHas('company', function ($q) use ($search) {
                $q->where('company_name', 'like', "%{$search}%");
            })->orWhereHas('plan', function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%");
            });
        }

        $subscriptions = $query->orderBy('created_at', 'desc')
            ->paginate($request->get('per_page', 20));

        // Enhance each subscription with usage data
        $subscriptions->getCollection()->transform(function ($subscription) {
            $companyId = $subscription->company_id;

            $subscription->company_name = $subscription->company->company_name;
            $subscription->plan_name = $subscription->plan->name;
            $subscription->plan_price = $subscription->plan->price;
            $subscription->usage = [
                'customers' => $this->getCurrentCustomerCount($companyId),
                'branches' => $this->getCurrentBranchCount($companyId),
                'zones' => $this->getCurrentZoneCount($companyId),
                'users' => $this->getCurrentUserCount($companyId),
            ];

            return $subscription;
        });

        // Get statistics
        $stats = [
            'total_active' => Subscription::where('status', 'active')
                ->where('end_date', '>=', now())->count(),
            'total_expired' => Subscription::where('status', 'active')
                ->where('end_date', '<', now())->count(),
            'total_companies' => Company::count(),
            'total_revenue' => SubscriptionOrder::where('status', 'approved')->sum('amount'),
            'monthly_revenue' => SubscriptionOrder::where('status', 'approved')
                ->whereMonth('approved_at', now()->month)->sum('amount'),
            'expiring_soon' => Subscription::where('status', 'active')
                ->where('end_date', '<=', now()->addDays(30))
                ->where('end_date', '>=', now())->count(),
            'by_plan' => Subscription::where('status', 'active')
                ->select('plan_id', DB::raw('count(*) as count'))
                ->groupBy('plan_id')
                ->get()
                ->pluck('count', 'plan_id')
                ->toArray(),
        ];

        return response()->json([
            'success' => true,
            'data' => $subscriptions,
            'stats' => $stats
        ]);
    }

    /**
     * Renew a subscription
     */
    public function renewSubscription(Request $request, $id)
    {
        $this->authorizeAdmin();

        $subscription = Subscription::with(['company', 'plan'])->findOrFail($id);

        $validator = Validator::make($request->all(), [
            'duration_months' => 'required|integer|min:1|max:60',
            'start_date' => 'required|date',
            'admin_notes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        DB::beginTransaction();
        try {
            $user = $request->user();
            $startDate = \Carbon\Carbon::parse($request->start_date);
            $endDate = $startDate->copy()->addMonths($request->duration_months);

            // Calculate renewal amount (no payment collected, just extending)
            $monthlyPrice = floatval($subscription->plan->price);
            $subtotal = $monthlyPrice * $request->duration_months;

            // Create renewal order record
            $order = SubscriptionOrder::create([
                'order_number' => SubscriptionOrder::generateOrderNumber(),
                'company_id' => $subscription->company_id,
                'plan_id' => $subscription->plan_id,
                'subscription_id' => $subscription->id,
                'duration_months' => $request->duration_months,
                'receipt_number' => 'RENEWAL-' . $subscription->id . '-' . time(),
                'subtotal' => $subtotal,
                'amount' => $subtotal,
                'currency' => 'TZS',
                'status' => 'approved',
                'admin_notes' => $request->admin_notes,
                'approved_by' => $user->id,
                'approved_at' => now(),
                'start_date' => $startDate,
                'end_date' => $endDate,
            ]);

            // Update subscription
            $subscription->start_date = $startDate;
            $subscription->end_date = $endDate;
            $subscription->status = 'active';
            $subscription->subscription_order_id = $order->id;
            $subscription->save();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Subscription renewed successfully',
                'data' => [
                    'subscription' => $subscription,
                    'order' => $order
                ]
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to renew subscription: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Cancel a subscription
     */
    public function cancelSubscription(Request $request, $id)
    {
        $this->authorizeAdmin();

        $subscription = Subscription::findOrFail($id);

        if ($subscription->status !== 'active') {
            return response()->json([
                'success' => false,
                'message' => 'Only active subscriptions can be cancelled'
            ], 422);
        }

        DB::beginTransaction();
        try {
            $subscription->status = 'cancelled';
            $subscription->save();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Subscription cancelled successfully',
                'data' => $subscription
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to cancel subscription: ' . $e->getMessage()
            ], 500);
        }
    }
}
