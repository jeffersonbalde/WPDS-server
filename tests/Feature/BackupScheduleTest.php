<?php

use App\Models\User;
use App\Services\BackupScheduleService;
use App\Services\DatabaseBackupService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

uses(RefreshDatabase::class);

beforeEach(function () {
    $path = storage_path('app/backup-schedule.json');
    if (is_file($path)) {
        unlink($path);
    }
    config(['backup.path' => storage_path('app/testing-schedule-backups')]);
    File::deleteDirectory(storage_path('app/testing-schedule-backups'));
});

afterEach(function () {
    Carbon::setTestNow();
    $path = storage_path('app/backup-schedule.json');
    if (is_file($path)) {
        unlink($path);
    }
    File::deleteDirectory(storage_path('app/testing-schedule-backups'));
});

it('lets IT save a weekly backup schedule', function () {
    $this->seed();
    $it = User::where('email', 'it@westprime.edu')->firstOrFail();

    $this->actingAs($it, 'sanctum')
        ->putJson('/api/system/backup-schedule', [
            'enabled' => true,
            'frequency' => 'weekly',
            'time' => '14:30',
            'weekday' => 3,
            'retention_days' => 14,
        ])
        ->assertOk()
        ->assertJsonPath('schedule.frequency', 'weekly')
        ->assertJsonPath('schedule.time', '14:30')
        ->assertJsonPath('schedule.weekday', 3)
        ->assertJsonPath('schedule.retention_days', 14)
        ->assertJsonPath('schedule.label', 'Every Wednesday at 14:30');

    $this->actingAs($it, 'sanctum')
        ->getJson('/api/system/backup-schedule')
        ->assertOk()
        ->assertJsonPath('enabled', true)
        ->assertJsonPath('weekday', 3);
});

it('runs a due daily scheduled backup once per slot', function () {
    $this->seed();

    $schedule = app(BackupScheduleService::class);
    $backups = app(DatabaseBackupService::class);

    $schedule->save([
        'enabled' => true,
        'frequency' => 'daily',
        'time' => '02:00',
        'retention_days' => 30,
    ]);

    Carbon::setTestNow(Carbon::parse('2026-09-14 02:00:15', config('app.timezone')));

    $first = $schedule->runIfDue($backups);
    expect($first)->not->toBeNull()
        ->and($first['name'])->toEndWith('.sql');

    $second = $schedule->runIfDue($backups);
    expect($second)->toBeNull();

    $files = File::glob(storage_path('app/testing-schedule-backups/wpds-backup-*.sql'));
    expect($files)->toHaveCount(1);
});

it('runs weekly schedule only on the selected weekday', function () {
    $this->seed();

    $schedule = app(BackupScheduleService::class);
    $backups = app(DatabaseBackupService::class);

    $schedule->save([
        'enabled' => true,
        'frequency' => 'weekly',
        'time' => '10:00',
        'weekday' => 3, // Wednesday
        'retention_days' => 7,
    ]);

    Carbon::setTestNow(Carbon::parse('2026-09-15 10:00:00', config('app.timezone'))); // Tuesday
    expect($schedule->runIfDue($backups))->toBeNull();

    Carbon::setTestNow(Carbon::parse('2026-09-16 10:00:00', config('app.timezone'))); // Wednesday
    expect($schedule->runIfDue($backups))->not->toBeNull();
});

it('executes the scheduled artisan command when due', function () {
    $this->seed();

    app(BackupScheduleService::class)->save([
        'enabled' => true,
        'frequency' => 'daily',
        'time' => '03:15',
        'retention_days' => 30,
    ]);

    Carbon::setTestNow(Carbon::parse('2026-09-14 03:15:20', config('app.timezone')));
    Artisan::call('wpds:backup-run-scheduled');

    $files = File::glob(storage_path('app/testing-schedule-backups/wpds-backup-*.sql'));
    expect($files)->not->toBeEmpty();
});
