<?php

namespace App\Http\Middleware;

use App\Services\UserLogService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Throwable;
use Tymon\JWTAuth\Facades\JWTAuth;

class LogUserActivity
{
    private static bool $recorded = false;

    public function __construct(private UserLogService $userLogService)
    {
    }

    /**
     * All work happens in terminate() so activity logging never adds latency
     * to the response.
     */
    public function handle(Request $request, Closure $next): Response
    {
        self::$recorded = false;

        return $next($request);
    }

    /**
     * Record the request after the response has been sent. Any failure here is
     * swallowed - logging must never break a request.
     */
    public function terminate(Request $request, Response $response): void
    {
        try {
            // Guard against the middleware being present in more than one group
            // for the same request.
            if (self::$recorded) {
                return;
            }

            if (! config('activity.enabled', true)) {
                return;
            }

            if (! config('activity.log_reads', true) && $request->isMethod('GET')) {
                return;
            }

            if ($this->isExcluded($request)) {
                return;
            }

            [$userId, $companyId] = $this->resolveActor();

            // Only authenticated traffic is recorded.
            if (is_null($userId)) {
                return;
            }

            self::$recorded = true;

            $this->userLogService->logRequest([
                'user_id'     => $userId,
                'company'     => $companyId,
                'action'      => $this->action($request),
                'method'      => $request->method(),
                'route'       => optional($request->route())->getName() ?: $request->path(),
                'status_code' => $response->getStatusCode(),
                'ip_address'  => $request->ip(),
                'user_agent'  => substr((string) $request->userAgent(), 0, 1000),
                'details'     => $this->details($request),
            ]);
        } catch (Throwable $e) {
            report($e);
        }
    }

    private function isExcluded(Request $request): bool
    {
        $path = $request->path();

        foreach ((array) config('activity.exclude', []) as $pattern) {
            if (Str::is($pattern, $path)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{0: int|null, 1: int|null}  [user_id, company]
     */
    private function resolveActor(): array
    {
        try {
            $payload = JWTAuth::parseToken()->getPayload();

            return [$payload->get('user_id'), $payload->get('company')];
        } catch (Throwable $e) {
            return [null, null];
        }
    }

    private function action(Request $request): string
    {
        $name = optional($request->route())->getName();

        return $name ?: $request->method() . ' ' . $request->path();
    }

    private function details(Request $request): string
    {
        $payload = [
            'path'  => $request->path(),
            'query' => $request->query(),
        ];

        if ($this->hasUpload($request)) {
            $payload['body'] = '<multipart upload>';
        } else {
            $payload['body'] = $this->sanitize($request->except('_token'));
        }

        $json = json_encode($payload) ?: '{}';
        $max = (int) config('activity.max_body_bytes', 8192);

        if (strlen($json) > $max) {
            $payload['body'] = '<truncated>';
            $payload['_truncated'] = true;
            $json = json_encode($payload) ?: '{}';
        }

        return $json;
    }

    private function hasUpload(Request $request): bool
    {
        return str_contains((string) $request->header('Content-Type'), 'multipart/form-data')
            || count($request->allFiles()) > 0;
    }

    /**
     * Recursively redact sensitive keys nested inside the payload.
     */
    private function sanitize(array $data): array
    {
        $sensitive = array_map('strtolower', (array) config('activity.sensitive_keys', []));

        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = $this->sanitize($value);
            } elseif (in_array(strtolower((string) $key), $sensitive, true)) {
                $data[$key] = '***';
            }
        }

        return $data;
    }
}
