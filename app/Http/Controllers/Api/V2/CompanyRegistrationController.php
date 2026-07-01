<?php
// app/Http/Controllers/Api/V2/CompanyRegistrationController.php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Notifications;
use App\Models\Company;
use App\Models\User;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\SubscriptionOrder;
use App\Models\Roles;
use App\Models\role_permissions;
use App\Models\users_roles;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class CompanyRegistrationController extends BaseController
{
    /**
     * Complete registration: Company + Admin + Plan Selection
     */
    public function register(Request $request)
    {
        try {
            $validated = $request->validate([
                // Company details
                'company_name' => 'required|string|max:255|unique:companies,company_name',
                'company_email' => 'required|email|max:255|unique:companies,company_email',
                'company_phone' => 'required|string|max:20',
                'company_address' => 'nullable|string|max:500',
                'company_city' => 'nullable|string|max:100',
                'company_country' => 'nullable|string|max:100',

                // Admin details
                'admin_name' => 'required|string|max:255',
                'admin_email' => 'required|email|max:255|unique:users,email',
                'admin_phone' => 'required|string|max:20',
                'admin_password' => 'required|string|min:6|confirmed',
                // Plan details
                'is_trial' => 'sometimes|boolean',
                'plan_id' => 'required_if:is_trial,false',
                'duration_months' => 'required_if:is_trial,false|integer|min:1|max:24',
                'terms_agreed' => 'accepted',
            ]);

            $isTrial = $request->boolean('is_trial', false);

            // If not trial, validate plan is not trial
            if (!$isTrial) {
                $plan = Plan::find($request->plan_id);
                if ($plan->name === 'Trial' || $plan->price == 0) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Invalid plan selection for paid subscription.',
                    ], 422);
                }
            }

            DB::beginTransaction();

            // 1. Create Company
            $company = Company::create([
                'company_name' => $request->company_name,
                'company_email' => $request->company_email,
                'company_phone' => $request->company_phone,
                'company_address' => $request->company_address,
                'company_city' => $request->company_city,
                'company_country' => $request->company_country,
                'company_status' => $isTrial ? 1 : 0, // Active for trial, inactive for paid until approval
                'registration_ip' => $request->ip(),
            ]);

            // 2. Create Admin User
            $adminUser = User::create([
                'name' => $request->admin_email,
                'email' => $request->admin_email,
                'user_phone' => $request->admin_phone,
                'phone' => '255' . substr($request->admin_phone, 1),
                'password' => Hash::make($request->admin_password),
                'user_company' => $company->id,
                'status' => 1,
                'super_admin' => 0,
                'first_name' => $request->admin_name,
                'last_name' => $request->admin_name,
                'email_verified_at' => Carbon::now(),
            ]);

            // 3. Create Company Admin Role and Assign Permissions
            $adminRole = Roles::create([
                'role_name' => 'Company Admin',
                'company' => $company->id,
                'status' => 1,
            ]);

            $controls = [
                3,
                6,
                7,
                8,
                9,
                10,
                12,
                13,
                14,
                15,
                16,
                17,
                18,
                21,
                23,
                25,
                26,
                27,
                29,
                30,
                31,
                32,
                33,
                35,
                36,
                37,
                38,
                39,
                41,
                56,
            ]; // TODO: Pass specific module control IDs here

            if (!empty($controls)) {
                foreach ($controls as $control_id) {
                    role_permissions::create([
                        'role_id' => $adminRole->id,
                        'permission_id' => $control_id,
                        'permission_status' => 1,
                    ]);
                }
            }

            // Assign role to user
            users_roles::create([
                'user_id' => $adminUser->id,
                'role_id' => $adminRole->id,
                'user_role_status' => 1,
            ]);

            // 4. Update company with registered_by
            $company->registered_by = $adminUser->id;
            $company->save();

            // 5. Create Subscription
            $plan = $isTrial ? Plan::where('name', 'Trial')->first() : Plan::find($request->plan_id);
            $durationMonths = $request->duration_months ?? 1;

            // Calculate amounts
            $monthlyPrice = floatval($plan->price);
            $subtotal = $monthlyPrice * $durationMonths;
            $discount = $plan->getDiscountForDuration($durationMonths);
            $discountAmount = ($subtotal * $discount) / 100;
            $totalAmount = $subtotal - $discountAmount;

            // Determine dates
            if ($isTrial) {
                $startDate = Carbon::now();
                $endDate = Carbon::now()->addDays(7);
                $orderStatus = 'approved';
                $subscriptionStatus = 'active';
                $company->trial_used = true;
                $company->save();
            } else {
                $startDate = Carbon::now();
                $endDate = Carbon::now()->addMonths($durationMonths);
                $orderStatus = 'pending';
                $subscriptionStatus = 'pending';
            }

            // Create subscription order
            $order = SubscriptionOrder::create([
                'order_number' => SubscriptionOrder::generateOrderNumber(),
                'company_id' => $company->id,
                'plan_id' => $plan->id,
                'duration_months' => $durationMonths,
                'receipt_number' => now()->format('YmdHis') . '-TRIAL',
                'subtotal' => $subtotal,
                'discount' => $discountAmount,
                'amount' => $totalAmount,
                'currency' => 'TZS',
                'status' => $orderStatus,
                'payment_date' => now(),
                'start_date' => $startDate,
                'end_date' => $endDate,
            ]);

            // Create subscription
            $subscription = Subscription::create([
                'company_id' => $company->id,
                'plan_id' => $plan->id,
                'subscription_order_id' => $order->id,
                'status' => $subscriptionStatus,
                'start_date' => $startDate,
                'end_date' => $endDate,
                'features' => [
                    'customer_limit' => $plan->customer_limit,
                    'branch_limit' => $plan->branch_limit,
                    'zone_limit' => $plan->zone_limit,
                    'user_limit' => $plan->user_limit,
                    'has_advanced_reports' => $plan->has_advanced_reports,
                    'has_support_tickets' => $plan->has_support_tickets,
                    'has_api_access' => $plan->has_api_access,
                    'has_export_data' => $plan->has_export_data,
                    'has_mobile_app' => $plan->has_mobile_app,
                    'has_priority_support' => $plan->has_priority_support,
                ]
            ]);

            $order->subscription_id = $subscription->id;
            $order->save();

            // If trial, auto-approve the order
            if ($isTrial) {
                $order->approved_at = now();
                $order->save();
                $company->registration_completed_at = now();
                $company->save();
            }

            $notification = new Notifications();
            $name = $request->admin_email;
            $password = $request->admin_password;
            $phone = '255' . substr($request->admin_phone, 1);
            $message = "Habari, Username yako ni : ".$name. " na password ni : ". $password ." na link ni : https://app.flux.co.tz";
            $notification->sendSMS($phone, $message);

            DB::commit();

            $message = $isTrial
                ? 'Registration completed! Your 7-day trial has started.'
                : 'Registration completed! Your subscription order is pending admin approval.';

            return response()->json([
                'success' => true,
                'message' => $message,
                'data' => [
                    'company' => [
                        'id' => $company->id,
                        'name' => $company->company_name,
                        'status' => $isTrial ? 'active' : 'pending_approval',
                    ],
                    'subscription' => [
                        'plan' => $plan->name,
                        'is_trial' => $isTrial,
                        'start_date' => $startDate,
                        'end_date' => $endDate,
                        'days_remaining' => $isTrial ? 7 : null,
                    ],
                ],
            ], 201);
        } catch (ValidationException $e) {
            return $this->validationErrorResponse($e);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Registration failed: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Registration failed: ' . $e->getMessage(),
            ], 500);
        }
    }


    /**
     * Check availability endpoints
     */
    public function checkCompanyName(Request $request)
    {
        $validated = $request->validate([
            'company_name' => 'required|string',
        ]);

        $exists = Company::where('company_name', $request->company_name)->exists();

        return response()->json([
            'success' => true,
            'available' => !$exists,
        ]);
    }

    public function checkCompanyEmail(Request $request)
    {
        $validated = $request->validate([
            'email' => 'required|email',
        ]);

        $exists = Company::where('company_email', $request->email)->exists();

        return response()->json([
            'success' => true,
            'available' => !$exists,
        ]);
    }

    public function checkAdminEmail(Request $request)
    {
        $validated = $request->validate([
            'email' => 'required|email',
        ]);

        $exists = User::where('email', $request->email)->exists();

        return response()->json([
            'success' => true,
            'available' => !$exists,
        ]);
    }
}
