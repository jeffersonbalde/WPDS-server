<?php

use App\Models\SchoolTerm;
use App\Models\User;
use App\Services\DatabaseBackupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

it('lets IT download a fresh SQL backup but forbids other roles', function () {
    $this->seed();

    $dir = storage_path('app/testing-backups-download');
    File::deleteDirectory($dir);
    config(['backup.path' => $dir]);

    $it = User::where('email', 'it@westprime.edu')->firstOrFail();
    $response = $this->actingAs($it, 'sanctum')->get('/api/system/backup');
    $response->assertOk();

    $body = $response->streamedContent();
    expect($body)->toContain('SET FOREIGN_KEY_CHECKS=0')
        ->and($body)->toContain('INSERT INTO `users`');

    $registrar = User::where('email', 'registrar@westprime.edu')->firstOrFail();
    $this->actingAs($registrar, 'sanctum')->get('/api/system/backup')->assertForbidden();

    File::deleteDirectory($dir);
});

it('writes SQL and JSON backup files with the wpds:backup command', function () {
    $this->seed();

    $dir = storage_path('app/testing-backups');
    File::deleteDirectory($dir);

    Artisan::call('wpds:backup', ['--path' => $dir]);

    $sqlFiles = File::glob($dir.'/wpds-backup-*.sql');
    $jsonFiles = File::glob($dir.'/wpds-backup-*.json');
    expect($sqlFiles)->not->toBeEmpty()
        ->and($jsonFiles)->not->toBeEmpty();

    $sql = file_get_contents($sqlFiles[0]);
    expect($sql)->toContain('INSERT INTO `users`');

    $snapshot = json_decode(file_get_contents($jsonFiles[0]), true);
    expect($snapshot['tables']['users'])->not->toBeEmpty();

    File::deleteDirectory($dir);
});

it('lists creates and downloads stored backups for IT', function () {
    $this->seed();

    $dir = storage_path('app/testing-backups-api');
    File::deleteDirectory($dir);
    config(['backup.path' => $dir]);

    $it = User::where('email', 'it@westprime.edu')->firstOrFail();

    $create = $this->actingAs($it, 'sanctum')->postJson('/api/system/backups')->assertCreated();
    $name = $create->json('backup.name');
    expect($name)->toEndWith('.sql');

    $this->actingAs($it, 'sanctum')
        ->getJson('/api/system/backups')
        ->assertOk()
        ->assertJsonFragment(['name' => $name]);

    $this->actingAs($it, 'sanctum')
        ->get("/api/system/backups/{$name}")
        ->assertOk();

    $this->actingAs($it, 'sanctum')
        ->deleteJson("/api/system/backups/{$name}")
        ->assertOk();

    File::deleteDirectory($dir);
});

it('lets IT change their own password with current password check', function () {
    $this->seed();
    $it = User::where('email', 'it@westprime.edu')->firstOrFail();
    $it->update(['password' => 'password']);

    $this->actingAs($it, 'sanctum')
        ->putJson('/api/system/password', [
            'current_password' => 'wrong',
            'password' => 'newpass1',
            'password_confirmation' => 'newpass1',
        ])
        ->assertStatus(422);

    $this->actingAs($it, 'sanctum')
        ->putJson('/api/system/password', [
            'current_password' => 'password',
            'password' => 'newpass1',
            'password_confirmation' => 'newpass1',
        ])
        ->assertOk();

    $it->refresh();
    expect(Hash::check('newpass1', $it->password))->toBeTrue();
});

it('restores data from a backup snapshot, replacing what is there', function () {
    $this->seed();

    $backups = app(DatabaseBackupService::class);
    $snapshot = $backups->snapshot();

    $termCountBefore = SchoolTerm::count();
    expect($termCountBefore)->toBeGreaterThan(0);

    SchoolTerm::query()->delete();
    expect(SchoolTerm::count())->toBe(0);

    $backups->restore($snapshot);

    expect(SchoolTerm::count())->toBe($termCountBefore);
});

it('restores via the wpds:restore command with --force', function () {
    $this->seed();

    $dir = storage_path('app/testing-backups-cmd');
    File::deleteDirectory($dir);
    Artisan::call('wpds:backup', ['--path' => $dir]);
    $file = File::glob($dir.'/wpds-backup-*.json')[0];

    $userCountBefore = User::count();
    User::query()->delete();
    expect(User::count())->toBe(0);

    Artisan::call('wpds:restore', ['file' => $file, '--force' => true]);

    expect(User::count())->toBe($userCountBefore);

    File::deleteDirectory($dir);
});
