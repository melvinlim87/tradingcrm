<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Scheduled Tasks
|--------------------------------------------------------------------------
| Runs when `php artisan schedule:run` fires. On Windows hook this into
| Task Scheduler every minute (see setup.md).
*/

// Pre-generate AI analysis for the 9 major pairs every Monday at 07:00 GMT+8
// so users see fresh results the moment they enter the dashboard.
Schedule::command('analysis:generate-weekly')
    ->mondays()
    ->at('07:00')
    ->timezone('Asia/Singapore')
    ->onOneServer()
    ->withoutOverlapping();

// Daily ForexFactory scrape (last + this + next week) at 06:00 GMT+8.
Schedule::command('news:scrape')
    ->dailyAt('06:00')
    ->timezone('Asia/Singapore')
    ->onOneServer()
    ->withoutOverlapping();
