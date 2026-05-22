<?php
// app/Http/Middleware/CheckSubscriptionFeature.php

namespace App\Http\Middleware;

use App\Models\Subscription;
use Closure;
use Illuminate\Http\Request;

class CheckSubscriptionFeature
{
    public function handle(Request $request, Closure $next, $feature)
    {
        $user = $request->user();
        
        if (!$user) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }
        
        $companyId = $user->company_id;
        
        // Check if company has active subscription with this feature
        $subscription = Subscription::with('plan')
            ->where('company_id', $companyId)
            ->where('status', 'active')
            ->where('start_date', '<=', now())
            ->where('end_date', '>=', now())
            ->first();
            
        if (!$subscription || !$subscription->plan->{$feature}) {
            if ($request->wantsJson()) {
                return response()->json([
                    'message' => "This feature requires a higher plan. Please upgrade your subscription.",
                    'feature' => $feature,
                    'requires_upgrade' => true
                ], 403);
            }
            
            return redirect()->route('subscription.plans')
                ->with('error', 'This feature requires a higher plan. Please upgrade your subscription.');
        }
        
        return $next($request);
    }
}