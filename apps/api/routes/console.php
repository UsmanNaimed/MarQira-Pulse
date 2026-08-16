<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// MarQira Pulse scheduled tasks
Schedule::command('marqira:check-stale-sites')
    ->everyFiveMinutes()
    ->withoutOverlapping();

Schedule::command('marqira:prune-old-heartbeats')
    ->daily()
    ->at('03:00');
