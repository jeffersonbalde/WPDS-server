<?php

use App\Enums\UserRole;
use App\Models\ClassSection;
use App\Models\Program;
use App\Models\SchoolTerm;
use App\Models\StudentProfile;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\IOFactory;

uses(RefreshDatabase::class);

function programRegistrarUser(): User
{
    return User::factory()->create([
        'role' => UserRole::Registrar,
        'is_active' => true,
    ]);
}

function makeTestProgram(array $overrides = []): Program
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

it('requires authentication to list programs', function () {
    $this->getJson('/api/programs')->assertUnauthorized();
});

it('returns a full array for dropdowns when pagination is not requested', function () {
    makeTestProgram();
    makeTestProgram([
        'code' => 'STEM',
        'name' => 'Science, Technology, Engineering and Mathematics',
        'academic_level' => 'shs',
        'track_type' => 'academic',
        'duration_years' => 2,
    ]);

    $response = $this->actingAs(programRegistrarUser(), 'sanctum')
        ->getJson('/api/programs')
        ->assertOk();

    expect($response->json())->toBeArray()
        ->and($response->json('data'))->toBeNull()
        ->and($response->json())->toHaveCount(2)
        ->and($response->json('0.code'))->toBe('BSIT')
        ->and($response->json('0.majors'))->toBeArray()
        ->and($response->json('0.in_use'))->toBeFalse();
});

it('paginates programs and includes summary counts', function () {
    makeTestProgram();
    makeTestProgram([
        'code' => 'STEM',
        'name' => 'Science, Technology, Engineering and Mathematics',
        'academic_level' => 'shs',
        'track_type' => 'academic',
        'duration_years' => 2,
    ]);
    makeTestProgram([
        'code' => 'ABM',
        'name' => 'Accountancy, Business and Management',
        'academic_level' => 'shs',
        'track_type' => 'academic',
        'duration_years' => 2,
    ]);

    $this->actingAs(programRegistrarUser(), 'sanctum')
        ->getJson('/api/programs?page=1&per_page=10')
        ->assertOk()
        ->assertJsonPath('total', 3)
        ->assertJsonPath('summary.total', 3)
        ->assertJsonPath('summary.college', 1)
        ->assertJsonPath('summary.shs', 2)
        ->assertJsonPath('data.0.code', 'BSIT');
});

it('filters and searches programs', function () {
    makeTestProgram();
    makeTestProgram([
        'code' => 'STEM',
        'name' => 'Science, Technology, Engineering and Mathematics',
        'academic_level' => 'shs',
        'track_type' => 'academic',
        'duration_years' => 2,
    ]);

    $this->actingAs(programRegistrarUser(), 'sanctum')
        ->getJson('/api/programs?page=1&academic_level=shs&search=STEM')
        ->assertOk()
        ->assertJsonPath('total', 1)
        ->assertJsonPath('data.0.code', 'STEM');
});

it('lets a registrar create a college program with multiple majors', function () {
    $response = $this->actingAs(programRegistrarUser(), 'sanctum')
        ->postJson('/api/programs', [
            'code' => 'btvted',
            'name' => 'Bachelor of Technical Vocational Teacher Education',
            'academic_level' => 'college',
            'track_type' => 'degree',
            'duration_years' => 4,
            'majors' => [
                ['code' => 'chs', 'name' => 'Computer Hardware Servicing'],
                ['code' => 'waft', 'name' => 'Welding and Fabrication Technology'],
            ],
        ])
        ->assertCreated()
        ->assertJsonPath('code', 'BTVTED')
        ->assertJsonPath('is_active', true)
        ->assertJsonPath('majors.0.code', 'CHS')
        ->assertJsonPath('majors.1.code', 'WAFT');

    expect($response->json('majors'))->toHaveCount(2);
    $this->assertDatabaseHas('programs', ['code' => 'BTVTED']);
    $this->assertDatabaseHas('program_majors', [
        'name' => 'Computer Hardware Servicing',
        'code' => 'CHS',
    ]);
});

it('rejects a duplicate program code', function () {
    makeTestProgram();

    $this->actingAs(programRegistrarUser(), 'sanctum')
        ->postJson('/api/programs', [
            'code' => 'BSIT',
            'name' => 'Duplicate',
            'academic_level' => 'college',
            'track_type' => 'degree',
            'duration_years' => 4,
        ])
        ->assertUnprocessable()
        ->assertJsonPath('errors.code.0', 'This program code is already in use.');
});

it('rejects a duplicate program name regardless of case or spacing', function () {
    makeTestProgram();

    $this->actingAs(programRegistrarUser(), 'sanctum')
        ->postJson('/api/programs', [
            'code' => 'IT',
            'name' => '  bachelor of science in information technology  ',
            'academic_level' => 'college',
            'track_type' => 'degree',
            'duration_years' => 4,
        ])
        ->assertUnprocessable()
        ->assertJsonPath('errors.name.0', 'This program name is already in use.');
});

it('rejects renaming a program to an existing name', function () {
    makeTestProgram();
    $other = makeTestProgram([
        'code' => 'BSED',
        'name' => 'Bachelor of Secondary Education',
    ]);

    $this->actingAs(programRegistrarUser(), 'sanctum')
        ->putJson("/api/programs/{$other->id}", [
            'name' => 'Bachelor of Science in Information Technology',
        ])
        ->assertUnprocessable()
        ->assertJsonPath('errors.name.0', 'This program name is already in use.');
});

it('allows saving a program without changing its own name', function () {
    $program = makeTestProgram();

    $this->actingAs(programRegistrarUser(), 'sanctum')
        ->putJson("/api/programs/{$program->id}", [
            'name' => 'Bachelor of Science in Information Technology',
            'duration_years' => 4,
        ])
        ->assertOk()
        ->assertJsonPath('name', 'Bachelor of Science in Information Technology');
});

it('rejects a college track on a senior high program', function () {
    $this->actingAs(programRegistrarUser(), 'sanctum')
        ->postJson('/api/programs', [
            'code' => 'HUMSS',
            'name' => 'Humanities and Social Sciences',
            'academic_level' => 'shs',
            'track_type' => 'degree',
            'duration_years' => 2,
        ])
        ->assertUnprocessable()
        ->assertJsonPath('errors.track_type.0', 'Senior High tracks must be Academic or TVL.');
});

it('rejects duplicate major names on the same program', function () {
    $this->actingAs(programRegistrarUser(), 'sanctum')
        ->postJson('/api/programs', [
            'code' => 'BTVTED',
            'name' => 'Bachelor of Technical Vocational Teacher Education',
            'academic_level' => 'college',
            'track_type' => 'degree',
            'duration_years' => 4,
            'majors' => [
                ['name' => 'Computer Hardware Servicing'],
                ['name' => 'computer hardware servicing'],
            ],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['majors.1.name']);
});

it('forbids a teacher from creating programs', function () {
    $teacher = User::factory()->create([
        'role' => UserRole::Teacher,
        'is_active' => true,
    ]);

    $this->actingAs($teacher, 'sanctum')
        ->postJson('/api/programs', [
            'code' => 'GAS',
            'name' => 'General Academic Strand',
            'academic_level' => 'shs',
            'track_type' => 'academic',
            'duration_years' => 2,
        ])
        ->assertForbidden();
});

it('updates an existing program and syncs majors', function () {
    $program = makeTestProgram();
    $major = $program->majors()->create([
        'name' => 'Information Technology',
        'code' => 'IT',
        'sort_order' => 0,
        'is_active' => true,
    ]);

    $this->actingAs(programRegistrarUser(), 'sanctum')
        ->putJson("/api/programs/{$program->id}", [
            'code' => 'BSIT',
            'name' => 'BS Information Technology',
            'academic_level' => 'college',
            'track_type' => 'degree',
            'duration_years' => 4,
            'is_active' => true,
            'majors' => [
                ['id' => $major->id, 'code' => 'IT', 'name' => 'Software Engineering', 'is_active' => true],
                ['code' => 'NET', 'name' => 'Networking'],
            ],
        ])
        ->assertOk()
        ->assertJsonPath('name', 'BS Information Technology')
        ->assertJsonPath('majors.0.name', 'Software Engineering')
        ->assertJsonPath('majors.1.code', 'NET');

    expect($program->fresh()->majors)->toHaveCount(2);
});

it('deactivates a program without deleting it or touching majors', function () {
    $program = makeTestProgram();
    $program->majors()->create([
        'name' => 'Information Technology',
        'is_active' => true,
        'sort_order' => 0,
    ]);

    $this->actingAs(programRegistrarUser(), 'sanctum')
        ->putJson("/api/programs/{$program->id}", [
            'is_active' => false,
        ])
        ->assertOk()
        ->assertJsonPath('is_active', false);

    $this->assertDatabaseHas('programs', ['id' => $program->id, 'is_active' => 0]);
    $this->assertDatabaseCount('program_majors', 1);
});

it('deletes an unused program', function () {
    $program = makeTestProgram(['code' => 'TEMP']);

    $this->actingAs(programRegistrarUser(), 'sanctum')
        ->deleteJson("/api/programs/{$program->id}")
        ->assertOk();

    $this->assertDatabaseMissing('programs', ['id' => $program->id]);
});

it('does not delete a program that students still use', function () {
    $program = makeTestProgram();
    $user = User::factory()->create(['role' => UserRole::Student]);
    StudentProfile::create([
        'user_id' => $user->id,
        'student_no' => '2026-0001',
        'academic_level' => 'college',
        'program_id' => $program->id,
        'last_name' => 'Dela Cruz',
        'first_name' => 'Juan',
    ]);

    $this->actingAs(programRegistrarUser(), 'sanctum')
        ->getJson('/api/programs?page=1&per_page=10')
        ->assertOk()
        ->assertJsonPath('data.0.in_use', true);

    $this->actingAs(programRegistrarUser(), 'sanctum')
        ->deleteJson("/api/programs/{$program->id}")
        ->assertUnprocessable()
        ->assertJsonPath('message', 'This program cannot be deleted because students, admissions, or curriculum records already use it. Deactivate it instead.')
        ->assertJsonPath('usage.in_use', true)
        ->assertJsonPath('usage.students.total', 1)
        ->assertJsonPath('usage.students.preview.0.student_no', '2026-0001');

    $this->assertDatabaseHas('programs', ['id' => $program->id]);
});

it('returns program usage records for the registrar', function () {
    $program = makeTestProgram();
    $user = User::factory()->create(['role' => UserRole::Student]);
    StudentProfile::create([
        'user_id' => $user->id,
        'student_no' => '2026-0001',
        'academic_level' => 'college',
        'program_id' => $program->id,
        'last_name' => 'Dela Cruz',
        'first_name' => 'Juan',
    ]);

    $this->actingAs(programRegistrarUser(), 'sanctum')
        ->getJson("/api/programs/{$program->id}/usage")
        ->assertOk()
        ->assertJsonPath('in_use', true)
        ->assertJsonPath('can_delete', false)
        ->assertJsonPath('students.total', 1)
        ->assertJsonPath('students.preview.0.student_no', '2026-0001')
        ->assertJsonPath('students.preview.0.name', 'Dela Cruz, Juan')
        ->assertJsonPath('admissions.total', 0)
        ->assertJsonPath('curriculum.total', 0);
});

it('forbids a teacher from viewing program usage', function () {
    $program = makeTestProgram(['code' => 'TEMP']);
    $teacher = User::factory()->create([
        'role' => UserRole::Teacher,
        'is_active' => true,
    ]);

    $this->actingAs($teacher, 'sanctum')
        ->getJson("/api/programs/{$program->id}/usage")
        ->assertForbidden();
});

it('does not remove a major that students still use', function () {
    $program = makeTestProgram();
    $major = $program->majors()->create([
        'name' => 'Computer Hardware Servicing',
        'code' => 'CHS',
        'is_active' => true,
        'sort_order' => 0,
    ]);
    $user = User::factory()->create(['role' => UserRole::Student]);
    StudentProfile::create([
        'user_id' => $user->id,
        'student_no' => '2026-0002',
        'academic_level' => 'college',
        'program_id' => $program->id,
        'program_major_id' => $major->id,
        'last_name' => 'Test',
        'first_name' => 'Student',
    ]);

    $this->actingAs(programRegistrarUser(), 'sanctum')
        ->putJson("/api/programs/{$program->id}", [
            'majors' => [],
        ])
        ->assertUnprocessable();

    $this->assertDatabaseHas('program_majors', ['id' => $major->id]);
});

it('deletes an unused major immediately', function () {
    $program = makeTestProgram();
    $major = $program->majors()->create([
        'name' => 'Computer Hardware Servicing',
        'code' => 'CHS',
        'is_active' => true,
        'sort_order' => 0,
    ]);

    $this->actingAs(programRegistrarUser(), 'sanctum')
        ->deleteJson("/api/programs/{$program->id}/majors/{$major->id}")
        ->assertOk()
        ->assertJsonPath('message', 'Major deleted.');

    $this->assertDatabaseMissing('program_majors', ['id' => $major->id]);
});

it('does not immediately delete a major that students still use', function () {
    $program = makeTestProgram();
    $major = $program->majors()->create([
        'name' => 'Computer Hardware Servicing',
        'code' => 'CHS',
        'is_active' => true,
        'sort_order' => 0,
    ]);
    $user = User::factory()->create(['role' => UserRole::Student]);
    StudentProfile::create([
        'user_id' => $user->id,
        'student_no' => '2026-0002',
        'academic_level' => 'college',
        'program_id' => $program->id,
        'program_major_id' => $major->id,
        'last_name' => 'Dela Cruz',
        'first_name' => 'Juan',
    ]);

    $this->actingAs(programRegistrarUser(), 'sanctum')
        ->deleteJson("/api/programs/{$program->id}/majors/{$major->id}")
        ->assertUnprocessable()
        ->assertJsonPath('usage.in_use', true)
        ->assertJsonPath('usage.students.total', 1)
        ->assertJsonPath('usage.students.preview.0.student_no', '2026-0002');

    $this->assertDatabaseHas('program_majors', ['id' => $major->id]);
});

it('searches programs by major name', function () {
    $program = makeTestProgram(['code' => 'BTVTED', 'name' => 'Bachelor of Technical Vocational Teacher Education']);
    $program->majors()->create([
        'name' => 'Welding and Fabrication Technology',
        'code' => 'WAFT',
        'is_active' => true,
        'sort_order' => 0,
    ]);

    $this->actingAs(programRegistrarUser(), 'sanctum')
        ->getJson('/api/programs?page=1&search=Welding')
        ->assertOk()
        ->assertJsonPath('total', 1)
        ->assertJsonPath('data.0.code', 'BTVTED');
});

it('exports programs to excel with full major names', function () {
    $program = makeTestProgram(['code' => 'BTVTED', 'name' => 'Bachelor of Technical Vocational Teacher Education']);
    $program->majors()->create([
        'name' => 'Computer Hardware Servicing',
        'code' => 'CHS',
        'is_active' => true,
        'sort_order' => 0,
    ]);
    $program->majors()->create([
        'name' => 'Welding and Fabrication Technology',
        'code' => 'WAFT',
        'is_active' => true,
        'sort_order' => 1,
    ]);
    makeTestProgram([
        'code' => 'STEM',
        'name' => 'Science, Technology, Engineering and Mathematics',
        'academic_level' => 'shs',
        'track_type' => 'academic',
        'duration_years' => 2,
    ]);

    $response = $this->actingAs(programRegistrarUser(), 'sanctum')
        ->get('/api/programs/export')
        ->assertOk();

    expect($response->headers->get('content-type'))
        ->toContain('spreadsheetml');
    expect($response->headers->get('content-disposition'))
        ->toContain('programs-export-');

    $path = tempnam(sys_get_temp_dir(), 'wpds-prog-').'.xlsx';
    file_put_contents($path, $response->streamedContent());
    $sheet = IOFactory::load($path)->getActiveSheet();
    unlink($path);

    expect($sheet->getCell('A1')->getValue())->toBe('Code');
    expect($sheet->getCell('C1')->getValue())->toBe('Majors');

    $codes = [$sheet->getCell('A2')->getValue(), $sheet->getCell('A3')->getValue()];
    expect($codes)->toContain('BTVTED');

    $btvtedRow = $sheet->getCell('A2')->getValue() === 'BTVTED' ? 2 : 3;
    expect($sheet->getCell('C'.$btvtedRow)->getValue())
        ->toContain('CHS — Computer Hardware Servicing')
        ->toContain('WAFT — Welding and Fabrication Technology');
});

it('forbids a teacher from exporting programs', function () {
    $teacher = User::factory()->create([
        'role' => UserRole::Teacher,
        'is_active' => true,
    ]);

    $this->actingAs($teacher, 'sanctum')
        ->getJson('/api/programs/export')
        ->assertForbidden();
});

it('requires a major when admitting a student to a program with majors', function () {
    $program = makeTestProgram(['code' => 'BTVTED', 'name' => 'Bachelor of Technical Vocational Teacher Education']);
    $major = $program->majors()->create([
        'name' => 'Computer Hardware Servicing',
        'code' => 'CHS',
        'is_active' => true,
        'sort_order' => 0,
    ]);
    $term = SchoolTerm::create([
        'name' => 'FIRST SEMESTER, 2025-2026',
        'term_type' => 'first_semester',
        'school_year' => '2025-2026',
        'is_active' => true,
    ]);
    $subject = Subject::create([
        'code' => 'ITE 112',
        'title' => 'COMPUTER PROGRAMMING 1',
        'academic_level' => 'college',
        'units' => 3,
        'is_active' => true,
    ]);
    $classSection = ClassSection::create([
        'school_term_id' => $term->id,
        'subject_id' => $subject->id,
        'section' => 'A',
        'schedule_day' => 'MWF',
        'schedule_time' => '8:00 AM - 9:00 AM',
        'room' => 'ROOM 201',
    ]);
    $user = User::factory()->create(['role' => UserRole::Student]);
    $student = StudentProfile::create([
        'user_id' => $user->id,
        'student_no' => '2026-0100',
        'academic_level' => 'college',
        'last_name' => 'Test',
        'first_name' => 'Student',
    ]);

    $registrar = programRegistrarUser();

    $this->actingAs($registrar, 'sanctum')
        ->postJson('/api/admissions', [
            'student_profile_id' => $student->id,
            'school_term_id' => $term->id,
            'program_id' => $program->id,
            'year_level' => 1,
            'section' => 'A',
            'class_section_ids' => [$classSection->id],
        ])
        ->assertUnprocessable()
        ->assertJsonPath('errors.program_major_id.0', 'Select a major for this program.');

    $this->actingAs($registrar, 'sanctum')
        ->postJson('/api/admissions', [
            'student_profile_id' => $student->id,
            'school_term_id' => $term->id,
            'program_id' => $program->id,
            'program_major_id' => $major->id,
            'year_level' => 1,
            'section' => 'A',
            'class_section_ids' => [$classSection->id],
        ])
        ->assertCreated()
        ->assertJsonPath('program_major_id', $major->id)
        ->assertJsonPath('program_major.code', 'CHS');

    expect($student->fresh()->program_major_id)->toBe($major->id);
});
