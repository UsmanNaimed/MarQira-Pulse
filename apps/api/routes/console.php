<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// MarQira Pulse scheduled tasks
//
// The stale-site monitor runs EVERY MINUTE. Running frequently does NOT mean a
// site is emailed every minute — the command is timestamp-driven: it only sends
// a repeat alert once `marqira.alerts.offline_repeat_minutes` has elapsed since
// the last alert (see CheckStaleSitesCommand::sendRepeatAlerts). A 1-minute
// cadence is what lets short repeat intervals (e.g. 2 minutes, for testing)
// actually be honored — a 5-minute scheduler could never satisfy a 2-minute
// repeat. `withoutOverlapping()` prevents two runs from colliding, and the
// alert sender additionally uses atomic DB claims so concurrent runs can never
// double-send.
Schedule::command('marqira:check-stale-sites')
    ->everyMinute()
    ->withoutOverlapping();

Schedule::command('marqira:prune-old-heartbeats')
    ->daily()
    ->at('03:00');
