<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Activity Logging
    |--------------------------------------------------------------------------
    |
    | Controls the LogUserActivity middleware, which records every authenticated
    | request into the `user_logs` table (acting user, company, route, payload,
    | client IP/agent and response status).
    |
    */

    'enabled' => env('ACTIVITY_LOG_ENABLED', true),

    // Log GET/HEAD reads in addition to data-changing requests.
    'log_reads' => env('ACTIVITY_LOG_READS', true),

    // Request paths that must never be logged. Matched with Str::is(), so
    // wildcards are allowed. Paths have no leading slash.
    'exclude' => [
        'up',
        '*whatsapp',
        'telescope*',
        'horizon*',
        '*/system/optimize/*',
        'system/optimize/*',
        '*authenticate',
        '*/refresh',
        '*/refresh-token',
        '*/logout',
        '*/change-password',
    ],

    // Request keys stripped from the stored payload (also matched case-insensitively).
    'sensitive_keys' => [
        'password',
        'password_confirmation',
        'current_password',
        'new_password',
        'new_password_confirmation',
        'token',
        'refresh_token',
        'access_token',
        'secret',
        'pin',
        'otp',
    ],

    // Hard cap on the serialised `details` payload.
    'max_body_bytes' => 8192,

];
