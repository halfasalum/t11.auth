<?php

namespace App\Http\Middleware;

use App\Models\BranchModel;
use App\Models\CustomersZone;
use App\Models\Plan;
use App\Models\Subscriptions;
use App\Models\User;
use App\Models\Zone;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Tymon\JWTAuth\Facades\JWTAuth;

class CheckSubscriptionLimits
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     * @param  string  $resource
     * @return Response
     */
    public function handle(Request $request, Closure $next, $resource): Response
    {
        try {
            // Get the authenticated user's company from JWT
            $user = JWTAuth::parseToken()->getPayload();
            $user_company = $user->get('company');

            // Check for active subscription
            $subscription = Subscriptions::where('company_id', $user_company)
                ->where('status', 1)
                ->first();
            if (!$subscription) {
                return response()->json(['message' => 'No active subscription'], 403);
            }

            // Get the plan
            $plan = Plan::find($subscription->plan_id);
            if (!$plan) {
                return response()->json(['message' => 'Plan not found'], 403);
            }

            // Map resources to their plan limit fields
            $limitMap = [
                'customers' => 'customer_limit',
                'branches' => 'branch_limit',
                'zones' => 'zone_limit',
                'users' => 'user_limit',
            ];

            // If resource is not in limitMap, proceed without checking
            if (!isset($limitMap[$resource])) {
                return $next($request);
            }

            // Get the limit for the resource
            $limitField = $limitMap[$resource];
            $limit = $plan->$limitField;

            // Count active resources
            $customersCount = CustomersZone::where('company_id', $user_company)
                ->where('status', 1)
                ->count();
            $zoneCount = Zone::where('company', $user_company)
                ->where('status', 1)
                ->count();
            $branchCount = BranchModel::where('company', $user_company)
                ->where('status', 1)
                ->count();
            $userCount = User::where('user_company', $user_company)
                ->where('status', 1)
                ->count();

            // Determine current count based on resource
            $currentCount = match ($resource) {
                'customers' => $customersCount,
                'branches' => $branchCount,
                'zones' => $zoneCount,
                'users' => $userCount,
                default => 0,
            };

            // Check if limit is exceeded
            if ($limit !== null && $currentCount >= $limit) {
                return response()->json(['message' => "Subscription limit reached for $resource"], 422);
            }

            return $next($request);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Error: ' . $e->getMessage()], 401);
        }
    }
}