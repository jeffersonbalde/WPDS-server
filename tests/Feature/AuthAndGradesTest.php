<?php

use App\Models\ClassSection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('logs in demo users and rejects inactive accounts', function () {
    $this->seed();

    $response = $this->postJson('/api/login', [
        'email' => 'teacher@westprime.edu',
        'password' => 'password',
    ]);

    $response->assertOk()->assertJsonStructure(['token', 'user' => ['role']]);
    expect($response->json('user.role'))->toBe('teacher');
});

it('forbids registrar from encoding grades', function () {
    $this->seed();

    $registrar = User::where('email', 'registrar@westprime.edu')->first();
    $sectionId = ClassSection::first()->id;

    $this->actingAs($registrar, 'sanctum')
        ->postJson("/api/class-sections/{$sectionId}/grades", [
            'grades' => [],
        ])
        ->assertForbidden();
});

it('allows only assigned teacher to encode grades', function () {
    $this->seed();

    $teacher = User::where('email', 'teacher@westprime.edu')->first();
    $section = ClassSection::where('teacher_id', $teacher->id)
        ->whereHas('enrollmentSubjects')
        ->first();
    $enrollment = $section->enrollmentSubjects()->first();

    $this->actingAs($teacher, 'sanctum')
        ->postJson("/api/class-sections/{$section->id}/grades", [
            'grades' => [[
                'enrollment_subject_id' => $enrollment->id,
                'prelim' => 1.5,
                'midterm' => 1.5,
                'semi_final' => 1.5,
                'final' => 1.5,
            ]],
            'lock' => false,
        ])
        ->assertOk();
});
