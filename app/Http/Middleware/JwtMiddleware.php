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
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        try {
            /* $token = $request->header('Authorization');
            response()->json(['sample' => $token], 200);
            JWTAuth::setToken(str_replace('Bearer ', '', $token))->authenticate(); */
            JWTAuth::parseToken()->authenticate();
        } catch (TokenExpiredException $e) {
            try {
                // Refresh the token if it has expired
                $newToken = JWTAuth::refresh(JWTAuth::getToken());
                // Add the new token to the response headers
                return response()->json(['message' => 'Token refreshed'])
                    ->header('Authorization', 'Bearer ' . $newToken);
            } catch (Exception $refreshException) {
                return response()->json(['message' => "Token has expired and cannot be refreshed : ".$refreshException->getMessage()], 401);
            }
            return response()->json(['message' => 'Token has expired'], 401);
        } catch (TokenInvalidException $e) {
            return response()->json(['message' => 'Invalid token'], 401);
        } /* catch (Exception $e) {
            return response()->json(['error' => 'Token not found'], 401);
        } */ catch (Exception $e) {
            return response()->json(['message' => $e->getMessage()], 401);
        }
        // Proceed with the request if the token is valid
        $response = $next($request);
        if (isset($newToken)) {
            $response->headers->set('Authorization', 'Bearer ' . $newToken);
        }
        //return $next($request);
        return $response;
    }
}
