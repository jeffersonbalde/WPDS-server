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

it('names every demo staff account after the presenter and lets each one log in', function () {
    $this->seed();
    $this->seed(DefenseDemoSeeder::class);

    $emails = [
        'teacher@westprime.edu' => 'teacher',
        'registrar@westprime.edu' => 'registrar',
        'admin@westprime.edu' => 'admin',
        'it@westprime.edu' => 'it',
        'stakeholder@westprime.edu' => 'stakeholder',
    ];

    foreach ($emails as $email => $role) {
        $user = User::where('email', $email)->firstOrFail();
        expect($user->name)->toBe(DefenseDemoSeeder::DEMO_NAME)
            ->and($user->role->value)->toBe($role)
            ->and($user->is_active)->toBeTrue();

        $this->postJson('/api/login', ['email' => $email, 'password' => 'password'])
            ->assertOk()
            ->assertJsonPath('user.role', $role);
    }
});

it('removes other non-student users but keeps students and hands their data to the demo accounts', function () {
    $this->seed();

    $studentCount = User::where('role', UserRole::Student->value)->count();
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
        ->and(User::where('role', UserRole::Student->value)->count())->toBe($studentCount)
        ->and($section->fresh()->teacher_id)->toBe($demoTeacher->id)
        ->and($announcement->fresh()->created_by)->toBe($demoIt->id)
        ->and(User::where('role', '!=', UserRole::Student->value)->count())->toBe(5);
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
    $this->seed(DefenseDemoSeeder::class);

    expect(User::where('role', '!=', UserRole::Student->value)->count())->toBe(5);
});
