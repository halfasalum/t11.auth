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

class CheckSubscriptionStatus
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        try {
            $user = JWTAuth::parseToken()->getPayload();
            $user_company = $user->get('company');
            $subscription = Subscriptions::where('company_id', $user_company)
                ->where('status', 1)
                ->first();
            if (!$subscription) {
                return response()->json(['message' => 'Error: Your Subscription has expired'], 409);
            }
            
            return $next($request);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Error: ' . $e->getMessage()], 401);
        }
    }
}
