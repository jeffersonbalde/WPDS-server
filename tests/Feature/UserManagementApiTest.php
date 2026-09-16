<?php

use App\Enums\UserRole;
use App\Models\ClassSection;
use App\Models\SchoolTerm;
use App\Models\StaffProfile;
use App\Models\StudentProfile;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

function usersItAccount(): User
{
    return User::factory()->create([
        'role' => UserRole::It,
        'is_active' => true,
        'email' => 'it-test@westprime.edu',
    ]);
}

it('paginates users and returns summary for IT', function () {
    $it = usersItAccount();
    User::factory()->count(12)->create(['role' => UserRole::Student, 'is_active' => true]);
    User::factory()->count(2)->create(['role' => UserRole::Teacher, 'is_active' => false]);

    $response = $this->actingAs($it, 'sanctum')
        ->getJson('/api/users?page=1&per_page=10')
        ->assertOk();

    expect($response->json('data'))->toHaveCount(10);
    expect($response->json('last_page'))->toBeGreaterThan(1);
    expect($response->json('summary.total'))->toBe(15);
    expect($response->json('summary.active'))->toBe(13);
    expect($response->json('summary.inactive'))->toBe(2);
    expect($response->json('summary.students'))->toBe(12);
});

it('filters users by role and status', function () {
    $it = usersItAccount();
    User::factory()->count(3)->create(['role' => UserRole::Teacher, 'is_active' => true]);
    User::factory()->count(2)->create(['role' => UserRole::Teacher, 'is_active' => false]);
    User::factory()->count(4)->create(['role' => UserRole::Student, 'is_active' => true]);

    $this->actingAs($it, 'sanctum')
        ->getJson('/api/users?role=teacher&status=inactive&per_page=10')
        ->assertOk()
        ->assertJsonPath('total', 2)
        ->assertJsonPath('summary.total', 10);
});

it('searches users by name or email', function () {
    $it = usersItAccount();
    User::factory()->create([
        'name' => 'Unique Finder',
        'email' => 'unique.finder@westprime.edu',
        'role' => UserRole::Registrar,
        'is_active' => true,
    ]);
    User::factory()->count(3)->create(['role' => UserRole::Admin]);

    $this->actingAs($it, 'sanctum')
        ->getJson('/api/users?search=Unique+Finder&per_page=10')
        ->assertOk()
        ->assertJsonPath('total', 1)
        ->assertJsonPath('data.0.email', 'unique.finder@westprime.edu');
});

it('shows staff user details including teacher class sections', function () {
    $it = usersItAccount();
    $teacher = User::factory()->create([
        'role' => UserRole::Teacher,
        'is_active' => true,
        'name' => 'Teacher Viewer',
    ]);

    StaffProfile::create([
        'user_id' => $teacher->id,
        'employee_no' => 'TCH-VIEW-1',
        'department' => 'IT Department',
        'position' => 'Instructor',
        'mobile' => '09171234567',
    ]);

    $term = SchoolTerm::firstOrCreate(
        ['name' => 'FIRST SEMESTER, 2025-2026'],
        [
            'term_type' => 'first_semester',
            'school_year' => '2025-2026',
            'is_active' => true,
        ]
    );

    $subject = Subject::firstOrCreate(
        ['code' => 'ITE 999', 'academic_level' => 'college'],
        [
            'title' => 'VIEW TEST SUBJECT',
            'units' => 3,
            'lec_hours' => 3,
            'lab_hours' => 0,
        ]
    );

    ClassSection::create([
        'school_term_id' => $term->id,
        'subject_id' => $subject->id,
        'teacher_id' => $teacher->id,
        'section' => 'A',
        'schedule_time' => '08:00-09:00',
        'schedule_day' => 'MWF',
        'room' => 'LAB-1',
    ]);

    $this->actingAs($it, 'sanctum')
        ->getJson("/api/users/{$teacher->id}")
        ->assertOk()
        ->assertJsonPath('email', $teacher->email)
        ->assertJsonPath('staff_profile.employee_no', 'TCH-VIEW-1')
        ->assertJsonPath('class_sections.0.section', 'A')
        ->assertJsonPath('class_sections.0.subject.code', 'ITE 999');
});

it('shows student user with student profile id for record view', function () {
    $it = usersItAccount();
    $studentUser = User::factory()->create([
        'role' => UserRole::Student,
        'is_active' => true,
    ]);

    $profile = StudentProfile::create([
        'user_id' => $studentUser->id,
        'student_no' => '2026-'.substr(uniqid(), -4),
        'academic_level' => 'college',
        'year_level' => 1,
        'last_name' => 'VIEWER',
        'first_name' => 'STUDENT',
    ]);

    $this->actingAs($it, 'sanctum')
        ->getJson("/api/users/{$studentUser->id}")
        ->assertOk()
        ->assertJsonPath('role', 'student')
        ->assertJsonPath('student_profile.id', $profile->id)
        ->assertJsonPath('class_sections', []);
});

it('lets IT create a staff user with a profile photo', function () {
    Storage::fake('public');
    $it = usersItAccount();

    $response = $this->actingAs($it, 'sanctum')->post('/api/users', [
        'name' => 'New Teacher',
        'email' => 'new.teacher@westprime.edu',
        'password' => 'password',
        'role' => 'teacher',
        'employee_no' => 'TCH-100',
        'avatar' => UploadedFile::fake()->image('photo.jpg'),
    ]);

    $response->assertCreated();
    expect($response->json('avatar_url'))->not->toBeNull();

    $user = User::where('email', 'new.teacher@westprime.edu')->firstOrFail();
    Storage::disk('public')->assertExists($user->getRawOriginal('avatar_path'));
});

it('creates a staff user without a photo', function () {
    $it = usersItAccount();

    $response = $this->actingAs($it, 'sanctum')->postJson('/api/users', [
        'name' => 'No Photo Teacher',
        'email' => 'no.photo@westprime.edu',
        'password' => 'password',
        'role' => 'teacher',
        'employee_no' => 'TCH-101',
    ]);

    $response->assertCreated()->assertJsonPath('avatar_url', null);
});

it('creates a staff user without an employee number', function () {
    $it = usersItAccount();

    $response = $this->actingAs($it, 'sanctum')->postJson('/api/users', [
        'name' => 'No Employee No Teacher',
        'email' => 'no.empno@westprime.edu',
        'password' => 'password',
        'role' => 'teacher',
    ]);

    $response->assertCreated()
        ->assertJsonPath('staff_profile.employee_no', null);
});

it('rejects a duplicate employee number', function () {
    $it = usersItAccount();
    User::factory()->create(['role' => UserRole::Teacher, 'is_active' => true]);
    StaffProfile::create([
        'user_id' => User::where('role', UserRole::Teacher)->value('id'),
        'employee_no' => 'TCH-DUPE',
    ]);

    $this->actingAs($it, 'sanctum')->postJson('/api/users', [
        'name' => 'Dupe Employee Teacher',
        'email' => 'dupe.employee@westprime.edu',
        'password' => 'password',
        'role' => 'teacher',
        'employee_no' => 'TCH-DUPE',
    ])->assertStatus(422)->assertJsonValidationErrors(['employee_no']);
});

it('rejects a non-image file as the avatar', function () {
    Storage::fake('public');
    $it = usersItAccount();

    $this->actingAs($it, 'sanctum')->post('/api/users', [
        'name' => 'Bad Photo Teacher',
        'email' => 'bad.photo@westprime.edu',
        'password' => 'password',
        'role' => 'teacher',
        'employee_no' => 'TCH-102',
        'avatar' => UploadedFile::fake()->create('resume.pdf', 100),
    ])->assertStatus(422)->assertJsonValidationErrors(['avatar']);
});

it('rejects an oversized avatar', function () {
    Storage::fake('public');
    $it = usersItAccount();

    $this->actingAs($it, 'sanctum')->post('/api/users', [
        'name' => 'Huge Photo Teacher',
        'email' => 'huge.photo@westprime.edu',
        'password' => 'password',
        'role' => 'teacher',
        'employee_no' => 'TCH-103',
        'avatar' => UploadedFile::fake()->image('huge.jpg')->size(3000),
    ])->assertStatus(422)->assertJsonValidationErrors(['avatar']);
});

it('lets IT replace a user photo and deletes the old file', function () {
    Storage::fake('public');
    $it = usersItAccount();
    $teacher = User::factory()->create(['role' => UserRole::Teacher, 'is_active' => true]);

    $first = $this->actingAs($it, 'sanctum')
        ->post("/api/users/{$teacher->id}/avatar", ['avatar' => UploadedFile::fake()->image('first.jpg')])
        ->assertOk();
    $firstPath = $teacher->fresh()->getRawOriginal('avatar_path');
    Storage::disk('public')->assertExists($firstPath);

    $second = $this->actingAs($it, 'sanctum')
        ->post("/api/users/{$teacher->id}/avatar", ['avatar' => UploadedFile::fake()->image('second.jpg')])
        ->assertOk();
    $secondPath = $teacher->fresh()->getRawOriginal('avatar_path');

    expect($secondPath)->not->toBe($firstPath);
    Storage::disk('public')->assertExists($secondPath);
    Storage::disk('public')->assertMissing($firstPath);
    expect($first->json('avatar_url'))->not->toBe($second->json('avatar_url'));
});

it('lets IT remove a user photo', function () {
    Storage::fake('public');
    $it = usersItAccount();
    $teacher = User::factory()->create(['role' => UserRole::Teacher, 'is_active' => true]);

    $this->actingAs($it, 'sanctum')
        ->post("/api/users/{$teacher->id}/avatar", ['avatar' => UploadedFile::fake()->image('photo.jpg')])
        ->assertOk();
    $path = $teacher->fresh()->getRawOriginal('avatar_path');

    $this->actingAs($it, 'sanctum')
        ->deleteJson("/api/users/{$teacher->id}/avatar")
        ->assertOk()
        ->assertJsonPath('avatar_url', null);

    Storage::disk('public')->assertMissing($path);
});

it('forbids non-IT roles from managing user photos', function () {
    Storage::fake('public');
    $registrar = User::factory()->create(['role' => UserRole::Registrar, 'is_active' => true]);
    $teacher = User::factory()->create(['role' => UserRole::Teacher, 'is_active' => true]);

    $this->actingAs($registrar, 'sanctum')
        ->post("/api/users/{$teacher->id}/avatar", ['avatar' => UploadedFile::fake()->image('photo.jpg')])
        ->assertForbidden();

    $this->actingAs($registrar, 'sanctum')
        ->deleteJson("/api/users/{$teacher->id}/avatar")
        ->assertForbidden();
});
