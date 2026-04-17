<?php

namespace App\Http\Middleware;

use Closure;
use Exception;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Tymon\JWTAuth\Exceptions\TokenExpiredException;
use Tymon\JWTAuth\Exceptions\TokenInvalidException;
use Tymon\JWTAuth\Facades\JWTAuth;

class JwtMiddleware
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $newToken = null;
        
        try {
            JWTAuth::parseToken()->authenticate();
        } catch (TokenExpiredException $e) {
            try {
                // Refresh the token if it has expired
                $newToken = JWTAuth::refresh(JWTAuth::getToken());
                // Set the new token for the current request
                JWTAuth::setToken($newToken);
                $request->headers->set('Authorization', 'Bearer ' . $newToken);
            } catch (Exception $refreshException) {
                return response()->json([
                    'message' => 'Token has expired and cannot be refreshed: ' . $refreshException->getMessage()
                ], 401);
            }
        } catch (TokenInvalidException $e) {
            return response()->json(['message' => 'Invalid token'], 401);
        } catch (Exception $e) {
            return response()->json(['message' => $e->getMessage()], 401);
        }
        
        // Proceed with the request
        $response = $next($request);
        
        // Add the new token to response headers if it was refreshed
        if ($newToken) {
            $response->headers->set('Authorization', 'Bearer ' . $newToken);
            // Also add to response body for frontend to capture
            $content = json_decode($response->getContent(), true) ?? [];
            $content['refreshed_token'] = $newToken;
            $response->setContent(json_encode($content));
        }
        
        return $response;
    }
}