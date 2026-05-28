<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

/* Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote')->hourly(); */

Schedule::command('app:check-company-subscription')->dailyAt('00:05')->runInBackground();
Schedule::command('app:update-overdue-loans')->dailyAt('00:10')->runInBackground();
Schedule::command('loans:handle-overdue-schedules')->dailyAt('00:15')->runInBackground();
Schedule::command('loans:process-workflow')
    ->dailyAt('00:30')
    ->appendOutputTo(storage_path('logs/loan-workflow.log'))
    ->runInBackground();

// Daily backup at 1 AM
Schedule::command('backup:database-email --compress --email=zemburetheson@gmail.com')
    ->dailyAt('00:45')
    ->appendOutputTo(storage_path('logs/dbbackup.log'))
    ->runInBackground();

Schedule::command('payments:process')->everyMinute()->runInBackground();
