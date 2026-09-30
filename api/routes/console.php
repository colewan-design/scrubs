<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Scheduled tasks
|--------------------------------------------------------------------------
| Requires the one cron entry Laravel needs on the VPS:
|
|   * * * * * cd /var/www/bulkscrubs/api && php artisan schedule:run >> /dev/null 2>&1
*/

// §12 — nightly backup, before the overnight traffic trough ends.
Schedule::command('backup:run')
    ->dailyAt('02:30')
    ->withoutOverlapping()
    ->onOneServer()
    // A backup that stops running silently is the whole failure mode worth
    // guarding against, so failures go to the log rather than to /dev/null.
    ->emailOutputOnFailure(config('mail.from.address'));

/*
 * Once a week the dump is actually restored into a scratch database and
 * checked, because an untested backup is not a backup. Weekly rather than
 * nightly: it doubles the runtime and needs CREATE DATABASE, and a weekly
 * proof is enough to catch a dump that has quietly started coming out broken.
 */
Schedule::command('backup:run', ['--verify'])
    ->weeklyOn(0, '03:30')
    ->withoutOverlapping()
    ->onOneServer()
    ->emailOutputOnFailure(config('mail.from.address'));

/*
 * §4/§7 — return stock held by card checkouts that were never finished.
 *
 * Every five minutes rather than hourly: the reservation window is twenty
 * minutes by default, and a sweep that runs less often than the window makes
 * the setting mean something other than what it says. The query is indexed and
 * matches nothing on a quiet store, so the cost of running it often is noise.
 */
Schedule::command('orders:release-abandoned')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->onOneServer()
    ->emailOutputOnFailure(config('mail.from.address'));
