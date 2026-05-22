<?php

use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
/*
Route::get('/', function () {
    return Inertia::render('Welcome', [
        'canLogin' => Route::has('login'),
        'canRegister' => Route::has('register'),
        'laravelVersion' => Application::VERSION,
        'phpVersion' => PHP_VERSION,
    ]);
});

Route::middleware([
    'auth:sanctum',
    config('jetstream.auth_session'),
    'verified',
])->group(function () {
    Route::get('/dashboard', function () {
        return Inertia::render('Dashboard');
    })->name('dashboard');
}); */

Route::get('/system/optimize/{key}', function ($key) {

    // Change this secret key
    if ($key !== 'Irfan@0723') {
        abort(403);
    }

    $commands = [
        'config:clear',
        'cache:clear',
        'route:clear',
        'view:clear',
        'optimize:clear',

        // Production caches
        'config:cache',
        'route:cache',
        'view:cache',
    ];

    $results = [];

    foreach ($commands as $command) {
        Artisan::call($command);

        $results[] = [
            'command' => $command,
            'output' => Artisan::output()
        ];
    }

    return response()->json([
        'status' => 'completed',
        'results' => $results
    ]);
});
