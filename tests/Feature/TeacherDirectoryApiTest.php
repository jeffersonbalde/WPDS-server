<?php

use App\Enums\UserRole;
use App\Models\ClassSection;
use App\Models\SchoolTerm;
use App\Models\StaffProfile;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function directoryAdmin(): User
{
    return User::factory()->create(['role' => UserRole::Admin, 'is_active' => true]);
}

function directoryStakeholder(): User
{
    return User::factory()->create(['role' => UserRole::Stakeholder, 'is_active' => true]);
}

it('lets registrar, admin, stakeholder, and it list the teacher directory with summary', function () {
    User::factory()->count(3)->create(['role' => UserRole::Teacher, 'is_active' => true]);
    User::factory()->count(2)->create(['role' => UserRole::Teacher, 'is_active' => false]);
    User::factory()->create(['role' => UserRole::Student]);

    foreach ([
        User::factory()->create(['role' => UserRole::Registrar]),
        directoryAdmin(),
        directoryStakeholder(),
        User::factory()->create(['role' => UserRole::It]),
    ] as $user) {
        $res = $this->actingAs($user, 'sanctum')->getJson('/api/teacher-directory')->assertOk();

        expect($res->json('summary.total'))->toBe(5)
            ->and($res->json('summary.active'))->toBe(3)
            ->and($res->json('summary.inactive'))->toBe(2)
            ->and($res->json('data'))->toHaveCount(5);
    }
});

it('forbids teachers and students from the teacher directory', function () {
    foreach ([
        User::factory()->create(['role' => UserRole::Teacher]),
        User::factory()->create(['role' => UserRole::Student]),
    ] as $user) {
        $this->actingAs($user, 'sanctum')->getJson('/api/teacher-directory')->assertForbidden();
    }
});

it('filters the teacher directory by search and status', function () {
    $admin = directoryAdmin();
    $match = User::factory()->create(['role' => UserRole::Teacher, 'name' => 'Zayn Vega', 'is_active' => true]);
    User::factory()->create(['role' => UserRole::Teacher, 'name' => 'Other Teacher', 'is_active' => false]);

    $res = $this->actingAs($admin, 'sanctum')
        ->getJson('/api/teacher-directory?search=Zayn')
        ->assertOk();
    expect($res->json('data'))->toHaveCount(1)
        ->and($res->json('data.0.id'))->toBe($match->id);

    $res = $this->actingAs($admin, 'sanctum')
        ->getJson('/api/teacher-directory?status=inactive')
        ->assertOk();
    expect($res->json('data'))->toHaveCount(1)
        ->and($res->json('data.0.is_active'))->toBeFalse();
});

it('shows full teacher details including staff profile and assigned class sections', function () {
    $this->seed();
    $admin = directoryAdmin();
    $teacher = User::factory()->create(['role' => UserRole::Teacher]);
    StaffProfile::create([
        'user_id' => $teacher->id,
        'employee_no' => 'TCH-900',
        'department' => 'IT Department',
        'position' => 'Instructor',
        'mobile' => '09171234567',
    ]);
    $term = SchoolTerm::query()->firstOrFail();
    $subject = Subject::query()->firstOrFail();
    ClassSection::create([
        'teacher_id' => $teacher->id,
        'school_term_id' => $term->id,
        'subject_id' => $subject->id,
        'section' => 'A',
    ]);

    $res = $this->actingAs($admin, 'sanctum')
        ->getJson("/api/teacher-directory/{$teacher->id}")
        ->assertOk();

    expect($res->json('staff_profile.employee_no'))->toBe('TCH-900')
        ->and($res->json('class_sections'))->toHaveCount(1);
});

it('returns 404 when viewing a non-teacher through the teacher directory', function () {
    $admin = directoryAdmin();
    $student = User::factory()->create(['role' => UserRole::Student]);

    $this->actingAs($admin, 'sanctum')
        ->getJson("/api/teacher-directory/{$student->id}")
        ->assertNotFound();
});

it('lets admin and stakeholder export the teacher directory to excel', function () {
    User::factory()->create(['role' => UserRole::Teacher]);

    foreach ([directoryAdmin(), directoryStakeholder()] as $user) {
        $this->actingAs($user, 'sanctum')
            ->get('/api/teacher-directory/export')
            ->assertOk()
            ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }
});
