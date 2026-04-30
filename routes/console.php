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


