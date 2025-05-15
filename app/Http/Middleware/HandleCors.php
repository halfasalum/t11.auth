<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class HandleCors
{
    public function handle(Request $request, Closure $next): Response
    {
        $allowedOrigins = ['https://app.flux.co.tz', 'http://app.flux.co.tz','app.flux.co.tz'];
        $origin = $request->headers->get('Origin');

        // Handle preflight request
        if ($request->isMethod('OPTIONS')) {
            $response = response()->json([], 204); // 204 No Content is best for OPTIONS requests
            return $this->addCorsHeaders($response, $origin, $allowedOrigins);
        }

        // Handle actual request
        $response = $next($request);
        return $this->addCorsHeaders($response, $origin, $allowedOrigins);
    }

   /*  private function addCorsHeaders(Response $response, ?string $origin, array $allowedOrigins): Response
    {
        if ($origin && in_array($origin, $allowedOrigins)) {
            $response->headers->set('Access-Control-Allow-Origin', $origin);
            //$response->headers->set('Access-Control-Allow-Credentials', 'true'); // Required for authentication-based APIs
        }

        $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS');
        //$response->headers->set('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With, Accept');

        return $response;
    } */

    private function addCorsHeaders(Response $response, ?string $origin, array $allowedOrigins): Response
{
    if ($origin && in_array($origin, $allowedOrigins)) {
        $response->headers->set('Access-Control-Allow-Origin', $origin);
        $response->headers->set('Access-Control-Allow-Credentials', 'true');
    }

    $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS');
    $response->headers->set('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With, Accept');


    return $response;
}
}
