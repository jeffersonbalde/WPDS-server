<?php

use App\Enums\UserRole;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function dashboardAdmin(): User
{
    return User::factory()->create([
        'role' => UserRole::Admin,
        'is_active' => true,
    ]);
}

function dashboardRegistrar(): User
{
    return User::factory()->create([
        'role' => UserRole::Registrar,
        'is_active' => true,
    ]);
}

it('returns all-time dashboard summary with filter metadata', function () {
    $this->actingAs(dashboardAdmin(), 'sanctum')
        ->getJson('/api/dashboard')
        ->assertOk()
        ->assertJsonPath('filter.period', 'all')
        ->assertJsonPath('filter.active', false)
        ->assertJsonStructure([
            'students',
            'teachers',
            'admissions',
            'analytics' => [
                'students_by_level',
                'users_by_role',
                'admissions_by_status',
            ],
            'filter' => ['period', 'date_from', 'date_to', 'active', 'label'],
        ]);
});

it('filters dashboard student counts by this month', function () {
    $old = User::factory()->create(['role' => UserRole::Student, 'is_active' => true]);
    $oldProfile = StudentProfile::create([
        'user_id' => $old->id,
        'student_no' => '2025-OLD1',
        'academic_level' => 'college',
        'last_name' => 'OLD',
        'first_name' => 'STUDENT',
        'contact_email' => 'old.student@example.com',
    ]);
    $oldProfile->forceFill(['created_at' => now()->subMonths(2), 'updated_at' => now()->subMonths(2)])->save();

    $new = User::factory()->create(['role' => UserRole::Student, 'is_active' => true]);
    StudentProfile::create([
        'user_id' => $new->id,
        'student_no' => '2026-NEW1',
        'academic_level' => 'college',
        'last_name' => 'NEW',
        'first_name' => 'STUDENT',
        'contact_email' => 'new.student@example.com',
    ]);

    $this->actingAs(dashboardRegistrar(), 'sanctum')
        ->getJson('/api/dashboard?period=this_month')
        ->assertOk()
        ->assertJsonPath('filter.period', 'this_month')
        ->assertJsonPath('filter.active', true)
        ->assertJsonPath('students', 1);
});

it('filters dashboard by custom date range', function () {
    $inRange = User::factory()->create(['role' => UserRole::Student, 'is_active' => true]);
    $inProfile = StudentProfile::create([
        'user_id' => $inRange->id,
        'student_no' => '2026-RNG1',
        'academic_level' => 'shs',
        'last_name' => 'RANGE',
        'first_name' => 'IN',
        'contact_email' => 'in.range@example.com',
    ]);
    $inProfile->forceFill([
        'created_at' => '2026-03-15 10:00:00',
        'updated_at' => '2026-03-15 10:00:00',
    ])->save();

    $outRange = User::factory()->create(['role' => UserRole::Student, 'is_active' => true]);
    $outProfile = StudentProfile::create([
        'user_id' => $outRange->id,
        'student_no' => '2026-RNG2',
        'academic_level' => 'college',
        'last_name' => 'RANGE',
        'first_name' => 'OUT',
        'contact_email' => 'out.range@example.com',
    ]);
    $outProfile->forceFill([
        'created_at' => '2026-01-05 10:00:00',
        'updated_at' => '2026-01-05 10:00:00',
    ])->save();

    $this->actingAs(dashboardAdmin(), 'sanctum')
        ->getJson('/api/dashboard?period=custom&date_from=2026-03-01&date_to=2026-03-31')
        ->assertOk()
        ->assertJsonPath('filter.period', 'custom')
        ->assertJsonPath('filter.date_from', '2026-03-01')
        ->assertJsonPath('filter.date_to', '2026-03-31')
        ->assertJsonPath('students', 1)
        ->assertJsonPath('students_shs', 1)
        ->assertJsonPath('students_college', 0);
});

it('rejects invalid custom ranges', function () {
    $this->actingAs(dashboardAdmin(), 'sanctum')
        ->getJson('/api/dashboard?period=custom')
        ->assertStatus(422);

    $this->actingAs(dashboardAdmin(), 'sanctum')
        ->getJson('/api/dashboard?period=custom&date_from=2026-04-01&date_to=2026-03-01')
        ->assertStatus(422);
});

it('returns teacher dashboard with filter metadata', function () {
    $teacher = User::factory()->create([
        'role' => UserRole::Teacher,
        'is_active' => true,
    ]);

    $this->actingAs($teacher, 'sanctum')
        ->getJson('/api/dashboard?period=this_year')
        ->assertOk()
        ->assertJsonPath('filter.period', 'this_year')
        ->assertJsonStructure([
            'my_classes',
            'pending_change_requests',
            'returned_submissions',
            'submissions_awaiting_review',
            'filter',
        ]);
});
