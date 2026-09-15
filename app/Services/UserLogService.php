<?php

namespace App\Services;

use App\Models\UserLog;
use Illuminate\Support\Facades\Request;
use Throwable;
use Tymon\JWTAuth\Facades\JWTAuth;

class UserLogService
{
    /**
     * Record a semantic activity entry.
     *
     * Safe to call from controllers, services, queued jobs and console
     * commands: when no authenticated user can be resolved the entry is still
     * written with a null user_id rather than throwing.
     *
     * @param  string       $action   Short action name (e.g. "Approve", "login").
     * @param  mixed         $details  String, array or object with extra context.
     * @param  int|null      $user     Override the acting user id.
     * @param  int|null      $company  Override the acting company id.
     */
    public function log($action, $details = null, $user = null, $company = null): ?UserLog
    {
        if (is_null($user) || is_null($company)) {
            [$tokenUser, $tokenCompany] = $this->resolveActor();
            $user ??= $tokenUser;
            $company ??= $tokenCompany;
        }

        return $this->logRequest([
            'user_id' => $user,
            'company' => $company,
            'action'  => $action,
            'details' => $this->formatDetails($details),
        ]);
    }

    /**
     * Low-level writer. Fills in request context (method, route, ip, agent)
     * for any attributes not explicitly provided and never lets a logging
     * failure bubble up to the caller.
     */
    public function logRequest(array $attributes): ?UserLog
    {
        try {
            $hasRequest = ! app()->runningInConsole();

            $defaults = [
                'method'     => $hasRequest ? Request::method() : null,
                'route'      => $hasRequest ? (optional(Request::route())->getName() ?: Request::path()) : null,
                'ip_address' => $hasRequest ? Request::ip() : null,
                'user_agent' => $hasRequest ? substr((string) Request::header('User-Agent'), 0, 1000) : null,
            ];

            return UserLog::create(array_merge($defaults, $attributes));
        } catch (Throwable $e) {
            report($e);

            return null;
        }
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

    private function formatDetails($details): ?string
    {
        if (is_null($details)) {
            return null;
        }

        if (is_array($details) || is_object($details)) {
            return json_encode($details);
        }

        return (string) $details;
    }
}
