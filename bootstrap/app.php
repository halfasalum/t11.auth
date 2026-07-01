<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        api: __DIR__ . '/../routes/api.php',
        commands: __DIR__ . '/../routes/console.php',
        channels: __DIR__ . '/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->validateCsrfTokens(except: [
            '/whatsapp',
        ]);

        $middleware->web(append: [
            \App\Http\Middleware\HandleInertiaRequests::class,
            \Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets::class,
            \App\Http\Middleware\JwtMiddleware::class,
            \App\Http\Middleware\HandleCors::class,
            \App\Http\Middleware\CheckSubscriptionStatus::class,
        ]);

        // Per-route middleware aliases (require parameters, must NOT be in global group)
        $middleware->alias([
            'control.access'       => \App\Http\Middleware\ControlAccessMiddleware::class,
            'subscription.limits'  => \App\Http\Middleware\CheckSubscriptionLimits::class,
            'subscription.feature' => \App\Http\Middleware\CheckSubscriptionFeature::class,
        ]);

        //
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
