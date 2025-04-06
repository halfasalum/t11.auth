<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Tymon\JWTAuth\Facades\JWTAuth;

class ControlAccessMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next, int $requiredControl): Response
    {
         try {
            $user = JWTAuth::parseToken()->getPayload();
            $controls = $user->get('controls', []); // Default to an empty array if null

            if (!is_array($controls)) {
                return response()->json(['message' => 'Invalid token structure.'], 403);
            }

            if (!in_array($requiredControl, $controls)) {
                return response()->json(['message' => 'Access denied. Insufficient permissions.'], 403);
            }
        } catch (\Exception $e) {
            return response()->json(['message' => 'Token error: ' . $e->getMessage()], 403);
        } 

        return $next($request);
    }
}
