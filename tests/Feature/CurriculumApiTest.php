<?php

use App\Enums\UserRole;
use App\Models\CurriculumItem;
use App\Models\Program;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function curriculumRegistrar(): User
{
    return User::factory()->create([
        'role' => UserRole::Registrar,
        'is_active' => true,
    ]);
}

function makeCurriculumProgram(array $overrides = []): Program
{
    return Program::create(array_merge([
        'code' => 'BSIT',
        'name' => 'Bachelor of Science in Information Technology',
        'academic_level' => 'college',
        'track_type' => 'degree',
        'duration_years' => 4,
        'is_active' => true,
    ], $overrides));
}

function makeCurriculumSubject(array $overrides = []): Subject
{
    return Subject::create(array_merge([
        'code' => 'ITE 111',
        'title' => 'INTRODUCTION TO COMPUTING',
        'units' => 3,
        'academic_level' => 'college',
        'is_active' => true,
    ], $overrides));
}

it('requires a program_id to list curriculum', function () {
    $this->actingAs(curriculumRegistrar(), 'sanctum')
        ->getJson('/api/curriculum')
        ->assertUnprocessable();
});

it('lists curriculum items for a program with summary', function () {
    $program = makeCurriculumProgram();
    $subject = makeCurriculumSubject();
    CurriculumItem::create([
        'program_id' => $program->id,
        'subject_id' => $subject->id,
        'year_level' => 1,
        'semester' => 1,
    ]);

    $this->actingAs(curriculumRegistrar(), 'sanctum')
        ->getJson('/api/curriculum?program_id='.$program->id)
        ->assertOk()
        ->assertJsonPath('program.code', 'BSIT')
        ->assertJsonPath('summary.total', 1)
        ->assertJsonPath('items.0.subject.code', 'ITE 111');
});

it('lets a registrar add a subject to a program curriculum', function () {
    $program = makeCurriculumProgram();
    $subject = makeCurriculumSubject();

    $this->actingAs(curriculumRegistrar(), 'sanctum')
        ->postJson('/api/curriculum', [
            'program_id' => $program->id,
            'subject_id' => $subject->id,
            'year_level' => 1,
            'semester' => 1,
        ])
        ->assertCreated()
        ->assertJsonPath('subject.code', 'ITE 111')
        ->assertJsonPath('year_level', 1);

    $this->assertDatabaseHas('curriculum_items', [
        'program_id' => $program->id,
        'subject_id' => $subject->id,
        'year_level' => 1,
        'semester' => 1,
    ]);
});

it('rejects duplicate curriculum placements', function () {
    $program = makeCurriculumProgram();
    $subject = makeCurriculumSubject();
    CurriculumItem::create([
        'program_id' => $program->id,
        'subject_id' => $subject->id,
        'year_level' => 1,
        'semester' => 1,
    ]);

    $this->actingAs(curriculumRegistrar(), 'sanctum')
        ->postJson('/api/curriculum', [
            'program_id' => $program->id,
            'subject_id' => $subject->id,
            'year_level' => 1,
            'semester' => 1,
        ])
        ->assertUnprocessable()
        ->assertJsonPath('errors.subject_id.0', 'This subject is already in the curriculum for that year and semester.');
});

it('rejects subjects from a different academic level', function () {
    $program = makeCurriculumProgram();
    $subject = makeCurriculumSubject([
        'code' => 'ENG 1',
        'title' => 'ORAL COMMUNICATION',
        'academic_level' => 'shs',
        'units' => 0,
    ]);

    $this->actingAs(curriculumRegistrar(), 'sanctum')
        ->postJson('/api/curriculum', [
            'program_id' => $program->id,
            'subject_id' => $subject->id,
            'year_level' => 1,
            'semester' => 1,
        ])
        ->assertUnprocessable()
        ->assertJsonPath('errors.subject_id.0', 'This subject belongs to a different academic level than the selected program.');
});

it('updates and deletes curriculum items', function () {
    $program = makeCurriculumProgram();
    $subject = makeCurriculumSubject();
    $other = makeCurriculumSubject([
        'code' => 'ITE 112',
        'title' => 'COMPUTER PROGRAMMING 1',
    ]);
    $item = CurriculumItem::create([
        'program_id' => $program->id,
        'subject_id' => $subject->id,
        'year_level' => 1,
        'semester' => 1,
    ]);

    $this->actingAs(curriculumRegistrar(), 'sanctum')
        ->putJson('/api/curriculum/'.$item->id, [
            'subject_id' => $other->id,
            'year_level' => 1,
            'semester' => 2,
        ])
        ->assertOk()
        ->assertJsonPath('subject.code', 'ITE 112')
        ->assertJsonPath('semester', 2);

    $this->actingAs(curriculumRegistrar(), 'sanctum')
        ->deleteJson('/api/curriculum/'.$item->id)
        ->assertOk();

    $this->assertDatabaseMissing('curriculum_items', ['id' => $item->id]);
});

it('forbids a teacher from managing curriculum', function () {
    $program = makeCurriculumProgram();
    $subject = makeCurriculumSubject();
    $teacher = User::factory()->create([
        'role' => UserRole::Teacher,
        'is_active' => true,
    ]);

    $this->actingAs($teacher, 'sanctum')
        ->postJson('/api/curriculum', [
            'program_id' => $program->id,
            'subject_id' => $subject->id,
            'year_level' => 1,
            'semester' => 1,
        ])
        ->assertForbidden();
});

it('exports curriculum for a selected program as excel', function () {
    $program = makeCurriculumProgram();
    $subject = makeCurriculumSubject();
    CurriculumItem::create([
        'program_id' => $program->id,
        'subject_id' => $subject->id,
        'year_level' => 1,
        'semester' => 1,
    ]);

    $response = $this->actingAs(curriculumRegistrar(), 'sanctum')
        ->get('/api/curriculum/export?program_id='.$program->id);

    $response->assertOk();
    expect($response->headers->get('content-type'))->toContain('spreadsheetml.sheet');
    expect($response->headers->get('content-disposition'))->toContain('.xlsx');
});

it('exports all program curriculum workbooks', function () {
    $program = makeCurriculumProgram();
    $subject = makeCurriculumSubject();
    CurriculumItem::create([
        'program_id' => $program->id,
        'subject_id' => $subject->id,
        'year_level' => 1,
        'semester' => 1,
    ]);

    $response = $this->actingAs(curriculumRegistrar(), 'sanctum')
        ->get('/api/curriculum/export?scope=all');

    $response->assertOk();
    expect($response->headers->get('content-type'))->toContain('spreadsheetml.sheet');
});
