<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use App\Events\LogUpdated;

class LogController extends Controller
{
    public function streamLogs()
{
    $logFile = storage_path('logs/laravel.log');

    if (!File::exists($logFile)) {
        return response()->json(['message' => 'Log file not found'], 404);
    }

    $lines = File::lines($logFile)->reverse()->take(10)->implode("\n");

    event(new LogUpdated($lines));

    return response()->json(['message' => 'Logs broadcasted']);
}
}
