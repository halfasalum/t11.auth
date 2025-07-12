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
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next, $resource): Response
    {
        try {
            $user = JWTAuth::parseToken()->getPayload();
            $user_company = $user->get('company');
            $subscription = Subscriptions::where('company_id', $user_company)
                ->where('status', 1)
                ->first();
            if (!$subscription) {
                return response()->json(['error' => 'No active subscription'], 403);
            }
            $plan = Plan::find($subscription->plan_id);
            $limitMap = [
                'customers' => $plan->customer_limit,
                'branches' => $plan->branch_limit,
                'zones' => $plan->zone_limit,
                'users' => $plan->user_limit,
            ];
            if (!isset($limitMap[$resource])) {
                return $next($request); // No limit check for this resource
            }
            $limit = $plan->{$limitMap[$resource]['field']};
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
            $currentCount = 0;
            switch ($resource) {
                case 'customers':
                    $currentCount = $customersCount;
                    break;
                case 'branches':
                    $currentCount = $branchCount;
                    break;
                case 'zones':
                    $currentCount = $zoneCount;
                    break;
                case 'users':
                    $currentCount = $userCount;
                    break;
            }
            if ($limit !== null && $currentCount >= $limit) {
                return response()->json(['message' => 'Subscription limit reached for ' . $resource], 403);
            }
            return $next($request);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Error: ' . $e->getMessage()], 401);
        }
    }
}
