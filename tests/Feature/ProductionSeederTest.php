<?php

use App\Models\CurriculumItem;
use App\Models\Program;
use App\Models\SchoolTerm;
use App\Models\StudentProfile;
use App\Models\Subject;
use App\Models\User;
use Database\Seeders\ProductionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

uses(RefreshDatabase::class);

it('seeds only master data and staff accounts, no students', function () {
    Artisan::call('db:seed', ['--class' => ProductionSeeder::class, '--force' => true]);

    expect(Program::count())->toBeGreaterThan(0)
        ->and(Subject::count())->toBeGreaterThan(0)
        ->and(CurriculumItem::count())->toBeGreaterThan(0)
        ->and(SchoolTerm::where('is_active', true)->count())->toBe(1)
        ->and(User::count())->toBe(5)
        ->and(User::whereIn('role', ['student'])->count())->toBe(0)
        ->and(StudentProfile::count())->toBe(0);

    foreach (['teacher', 'registrar', 'admin', 'it', 'stakeholder'] as $role) {
        $this->assertDatabaseHas('users', ['email' => "{$role}@westprime.edu", 'role' => $role]);
    }
});

it('is safe to run twice without duplicating staff accounts', function () {
    Artisan::call('db:seed', ['--class' => ProductionSeeder::class, '--force' => true]);
    Artisan::call('db:seed', ['--class' => ProductionSeeder::class, '--force' => true]);

    expect(User::count())->toBe(5)
        ->and(Program::where('code', 'BSIT')->count())->toBe(1);
});
