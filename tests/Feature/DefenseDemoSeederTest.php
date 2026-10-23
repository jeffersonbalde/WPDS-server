<?php

use App\Enums\UserRole;
use App\Models\Announcement;
use App\Models\ClassSection;
use App\Models\User;
use Database\Seeders\DefenseDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(fn () => DefenseDemoSeeder::$passwordOverride = null);

afterEach(fn () => DefenseDemoSeeder::$passwordOverride = null);

const CORE_EMAILS = [
    'teacher@westprime.edu',
    'registrar@westprime.edu',
    'admin@westprime.edu',
    'it@westprime.edu',
    'stakeholder@westprime.edu',
];

it('adds a presenter account for every role and lets each one log in', function () {
    $this->seed();
    $this->seed(DefenseDemoSeeder::class);

    $accounts = [
        'aaron.registrar@westprime.edu' => 'registrar',
        'aaron.admin@westprime.edu' => 'admin',
        'aaron.it@westprime.edu' => 'it',
        'aaron.stakeholder@westprime.edu' => 'stakeholder',
        'aaron.teacher@westprime.edu' => 'teacher',
        'aaron.student@westprime.edu' => 'student',
    ];

    foreach ($accounts as $email => $role) {
        $user = User::where('email', $email)->firstOrFail();
        expect($user->role->value)->toBe($role)->and($user->is_active)->toBeTrue();

        $this->postJson('/api/login', ['email' => $email, 'password' => 'password'])
            ->assertOk()
            ->assertJsonPath('user.role', $role);
    }

    expect(User::where('email', 'aaron.student@westprime.edu')->first()->name)->toBe('LARIDE, AARON N')
        ->and(User::where('email', 'aaron.teacher@westprime.edu')->first()->name)->toBe(DefenseDemoSeeder::DEMO_NAME);
});

it('leaves the standard demo accounts and every student untouched', function () {
    $this->seed();

    $before = User::whereIn('email', [...CORE_EMAILS, 'jefferson.balde@westprime.edu'])
        ->get(['email', 'name', 'password'])->keyBy('email')->toArray();
    $studentCount = User::where('role', UserRole::Student->value)->count();

    $this->seed(DefenseDemoSeeder::class);

    $after = User::whereIn('email', [...CORE_EMAILS, 'jefferson.balde@westprime.edu'])
        ->get(['email', 'name', 'password'])->keyBy('email')->toArray();

    expect($after)->toBe($before)
        ->and(User::where('role', UserRole::Student->value)->count())->toBe($studentCount + 1);
});

it('gives the presenter teacher a class with the presenter student enrolled', function () {
    $this->seed();
    $this->seed(DefenseDemoSeeder::class);

    $teacher = User::where('email', 'aaron.teacher@westprime.edu')->firstOrFail();
    $section = ClassSection::where('teacher_id', $teacher->id)->firstOrFail();
    $studentNo = User::where('email', 'aaron.student@westprime.edu')->firstOrFail()->studentProfile->student_no;

    expect($section->enrollmentSubjects()->count())->toBe(1);

    $this->actingAs($teacher, 'sanctum')
        ->getJson("/api/class-sections/{$section->id}")
        ->assertOk()
        ->assertJsonPath('enrollment_subjects.0.admission.student_profile.student_no', $studentNo);
});

it('reuses an existing presenter teacher account instead of creating another', function () {
    $this->seed();
    $existing = User::factory()->create([
        'name' => 'AARON N. LARIDE',
        'email' => 'aaronlaride998@gmail.com',
        'role' => UserRole::Teacher,
    ]);

    $this->seed(DefenseDemoSeeder::class);

    expect(User::find($existing->id))->not->toBeNull()
        ->and(User::where('email', 'aaron.teacher@westprime.edu')->exists())->toBeFalse()
        ->and(ClassSection::where('teacher_id', $existing->id)->exists())->toBeTrue();
});

it('removes other non-student users and hands their data to the standard accounts', function () {
    $this->seed();

    $demoTeacher = User::where('email', 'teacher@westprime.edu')->firstOrFail();
    $demoIt = User::where('email', 'it@westprime.edu')->firstOrFail();

    $extraTeacher = User::factory()->create(['role' => UserRole::Teacher, 'email' => 'extra.teacher@example.com']);
    $extraRegistrar = User::factory()->create(['role' => UserRole::Registrar, 'email' => 'extra.registrar@example.com']);
    $section = ClassSection::firstOrFail();
    $section->update(['teacher_id' => $extraTeacher->id]);
    $announcement = Announcement::create(['title' => 'Old', 'body' => 'Body', 'created_by' => $extraRegistrar->id]);

    $this->seed(DefenseDemoSeeder::class);

    expect(User::find($extraTeacher->id))->toBeNull()
        ->and(User::find($extraRegistrar->id))->toBeNull()
        ->and($section->fresh()->teacher_id)->toBe($demoTeacher->id)
        ->and($announcement->fresh()->created_by)->toBe($demoIt->id)
        ->and(User::where('role', '!=', UserRole::Student->value)->count())->toBe(10);
});

it('refuses a too-short demo password and changes nothing', function () {
    $this->seed();
    $before = User::count();
    DefenseDemoSeeder::$passwordOverride = 'short';

    expect(fn () => $this->seed(DefenseDemoSeeder::class))->toThrow(RuntimeException::class);
    expect(User::count())->toBe($before);
});

it('is safe to run twice', function () {
    $this->seed();
    $this->seed(DefenseDemoSeeder::class);
    $count = User::count();
    $sections = ClassSection::count();

    $this->seed(DefenseDemoSeeder::class);

    expect(User::count())->toBe($count)->and(ClassSection::count())->toBe($sections);
});
