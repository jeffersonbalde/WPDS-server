<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('wpds:backup-run-scheduled')
    ->everyMinute()
    ->name('wpds-scheduled-backup')
    ->withoutOverlapping();

Schedule::command('sanctum:prune-expired --hours=24')
    ->daily()
    ->name('sanctum-prune-expired-tokens');

Schedule::command('wpds:audit-logs-prune')
    ->daily()
    ->name('wpds-audit-logs-prune')
    ->withoutOverlapping();
