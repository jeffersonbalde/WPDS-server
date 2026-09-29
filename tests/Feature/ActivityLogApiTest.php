<?php

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $path = app(\App\Services\AuditRetentionService::class)->path();
    if (is_file($path)) {
        unlink($path);
    }
});

function activityItUser(): User
{
    return User::factory()->create([
        'email' => 'it-audit@westprime.edu',
        'password' => 'password',
        'role' => UserRole::It,
        'is_active' => true,
    ]);
}

function activityAdminUser(): User
{
    return User::factory()->create([
        'role' => UserRole::Admin,
        'is_active' => true,
    ]);
}

it('records login and logout in the activity log', function () {
    $user = activityItUser();

    $login = $this->postJson('/api/login', [
        'email' => $user->email,
        'password' => 'password',
    ])->assertOk();

    $this->assertDatabaseHas('audit_logs', [
        'user_id' => $user->id,
        'action' => 'auth.login',
    ]);

    $this->withToken($login->json('token'))
        ->postJson('/api/logout')
        ->assertOk();

    $this->assertDatabaseHas('audit_logs', [
        'user_id' => $user->id,
        'action' => 'auth.logout',
    ]);
});

it('records failed login attempts without a user id', function () {
    $this->postJson('/api/login', [
        'email' => 'nobody@westprime.edu',
        'password' => 'wrong',
    ])->assertUnprocessable();

    $this->assertDatabaseHas('audit_logs', [
        'action' => 'auth.login_failed',
        'user_id' => null,
    ]);
});

it('lets it delete activity log entries but forbids admin', function () {
    $it = activityItUser();
    $admin = activityAdminUser();

    $log = AuditLog::create([
        'user_id' => $it->id,
        'action' => 'auth.login',
        'ip_address' => '127.0.0.1',
    ]);

    $this->actingAs($admin, 'sanctum')
        ->deleteJson('/api/audit-logs/'.$log->id)
        ->assertForbidden();

    $this->actingAs($it, 'sanctum')
        ->deleteJson('/api/audit-logs/'.$log->id)
        ->assertOk();

    $this->assertDatabaseMissing('audit_logs', ['id' => $log->id]);
});

it('lets it bulk-delete selected activity entries', function () {
    $it = activityItUser();
    $a = AuditLog::create(['user_id' => $it->id, 'action' => 'auth.login']);
    $b = AuditLog::create(['user_id' => $it->id, 'action' => 'auth.logout']);

    $this->actingAs($it, 'sanctum')
        ->deleteJson('/api/audit-logs', ['ids' => [$a->id, $b->id]])
        ->assertOk()
        ->assertJsonPath('deleted', 2);
});

it('prunes activity logs older than the retention window', function () {
    $it = activityItUser();

    $old = AuditLog::create([
        'user_id' => $it->id,
        'action' => 'auth.login',
        'ip_address' => '127.0.0.1',
    ]);
    $old->forceFill(['created_at' => now()->subDays(45)])->save();

    $fresh = AuditLog::create([
        'user_id' => $it->id,
        'action' => 'auth.logout',
        'ip_address' => '127.0.0.1',
    ]);

    $this->artisan('wpds:audit-logs-prune', ['--days' => 30])
        ->assertSuccessful();

    $this->assertDatabaseMissing('audit_logs', ['id' => $old->id]);
    $this->assertDatabaseHas('audit_logs', ['id' => $fresh->id]);
});

it('returns retention days with the activity log list', function () {
    $it = activityItUser();

    $this->actingAs($it, 'sanctum')
        ->getJson('/api/audit-logs')
        ->assertOk()
        ->assertJsonPath('meta.retention_days', 30)
        ->assertJsonPath('meta.retention.enabled', true)
        ->assertJsonPath('meta.retention.label', '1 month');
});

it('decorates activity rows with user name and record summary', function () {
    $it = activityItUser();

    AuditLog::create([
        'user_id' => $it->id,
        'action' => 'student.created',
        'auditable_type' => \App\Models\StudentProfile::class,
        'auditable_id' => 1,
        'new_values' => [
            'name' => 'Dela Cruz, Juan',
            'student_no' => '2026-0001',
        ],
        'ip_address' => '127.0.0.1',
    ]);

    $this->actingAs($it, 'sanctum')
        ->getJson('/api/audit-logs?per_page=10')
        ->assertOk()
        ->assertJsonPath('per_page', 10)
        ->assertJsonPath('data.0.user_name', $it->name)
        ->assertJsonPath('data.0.record_summary', 'Dela Cruz, Juan · No. 2026-0001');
});

it('lets it update the auto-delete schedule', function () {
    $it = activityItUser();
    $admin = activityAdminUser();

    $this->actingAs($admin, 'sanctum')
        ->putJson('/api/audit-logs/retention', [
            'enabled' => true,
            'retention_days' => 60,
        ])
        ->assertForbidden();

    $this->actingAs($it, 'sanctum')
        ->putJson('/api/audit-logs/retention', [
            'enabled' => true,
            'retention_days' => 60,
        ])
        ->assertOk()
        ->assertJsonPath('retention.retention_days', 60)
        ->assertJsonPath('retention.label', '2 months');

    $this->actingAs($it, 'sanctum')
        ->getJson('/api/audit-logs/retention')
        ->assertOk()
        ->assertJsonPath('retention_days', 60)
        ->assertJsonPath('label', '2 months');
});

it('skips scheduled pruning when auto-delete is disabled', function () {
    $it = activityItUser();
    $retention = app(\App\Services\AuditRetentionService::class);
    $retention->save([
        'enabled' => false,
        'retention_days' => 30,
    ]);

    $old = AuditLog::create([
        'user_id' => $it->id,
        'action' => 'auth.login',
    ]);
    $old->forceFill(['created_at' => now()->subDays(45)])->save();

    $this->artisan('wpds:audit-logs-prune')->assertSuccessful();

    $this->assertDatabaseHas('audit_logs', ['id' => $old->id]);
});
