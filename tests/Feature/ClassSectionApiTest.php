<?php

use App\Enums\UserRole;
use App\Models\Admission;
use App\Models\ClassSection;
use App\Models\EnrollmentSubject;
use App\Models\Program;
use App\Models\SchoolTerm;
use App\Models\StudentProfile;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function classSectionRegistrar(): User
{
    return User::factory()->create([
        'role' => UserRole::Registrar,
        'is_active' => true,
    ]);
}

function classSectionTeacher(): User
{
    return User::factory()->create([
        'role' => UserRole::Teacher,
        'is_active' => true,
        'name' => 'Teacher Demo',
    ]);
}

function classSectionTerm(): SchoolTerm
{
    return SchoolTerm::firstOrCreate(
        ['name' => 'FIRST SEMESTER, 2025-2026'],
        [
            'term_type' => 'first_semester',
            'school_year' => '2025-2026',
            'is_active' => true,
        ]
    );
}

function classSectionSubject(): Subject
{
    return Subject::firstOrCreate(
        ['code' => 'ITE 112', 'academic_level' => 'college'],
        [
            'title' => 'COMPUTER PROGRAMMING 1',
            'units' => 3,
            'is_active' => true,
        ]
    );
}

function makeClassSectionCatalog(array $overrides = []): ClassSection
{
    return ClassSection::create(array_merge([
        'school_term_id' => classSectionTerm()->id,
        'subject_id' => classSectionSubject()->id,
        'section' => 'A',
        'schedule_day' => 'MWF',
        'schedule_time' => '8:00 AM - 9:00 AM',
        'room' => 'ROOM 201',
    ], $overrides));
}

function attachEnrollmentToSection(ClassSection $section): EnrollmentSubject
{
    $term = $section->schoolTerm ?? classSectionTerm();
    $program = Program::create([
        'code' => 'BSIT',
        'name' => 'BSIT',
        'academic_level' => 'college',
        'track_type' => 'degree',
        'duration_years' => 4,
        'is_active' => true,
    ]);
    $user = User::factory()->create(['role' => UserRole::Student]);
    $student = StudentProfile::create([
        'user_id' => $user->id,
        'student_no' => '2026-'.substr(uniqid(), -4),
        'program_id' => $program->id,
        'academic_level' => 'college',
        'last_name' => 'Doe',
        'first_name' => 'Jane',
    ]);
    $admission = Admission::create([
        'admission_number' => 'ADM-'.uniqid(),
        'student_profile_id' => $student->id,
        'school_term_id' => $term->id,
        'program_id' => $program->id,
        'year_level' => 1,
        'status' => 'enrolled',
    ]);

    return EnrollmentSubject::create([
        'admission_id' => $admission->id,
        'class_section_id' => $section->id,
    ]);
}

it('lists class sections with pagination and summary', function () {
    $registrar = classSectionRegistrar();
    $teacher = classSectionTeacher();
    $withStudents = makeClassSectionCatalog(['teacher_id' => $teacher->id, 'section' => 'A']);
    makeClassSectionCatalog(['section' => 'B', 'teacher_id' => $teacher->id]);
    attachEnrollmentToSection($withStudents);

    $response = $this->actingAs($registrar)->getJson('/api/class-sections?page=1&per_page=10');

    $response->assertOk()
        ->assertJsonPath('summary.total', 2)
        ->assertJsonPath('summary.college', 2)
        ->assertJsonPath('summary.shs', 0)
        ->assertJsonPath('summary.with_students', 1)
        ->assertJsonPath('summary.empty', 1)
        ->assertJsonPath('data.0.subject.code', $withStudents->subject->code);
});

it('creates a class section for registrar', function () {
    $registrar = classSectionRegistrar();
    $teacher = classSectionTeacher();
    $term = classSectionTerm();
    $subject = classSectionSubject();

    $response = $this->actingAs($registrar)->postJson('/api/class-sections', [
        'school_term_id' => $term->id,
        'subject_id' => $subject->id,
        'teacher_id' => $teacher->id,
        'section' => 'C',
        'schedule_day' => 'TTH',
        'schedule_time' => '1:00 PM - 2:30 PM',
        'room' => 'LAB 1',
    ]);

    $response->assertCreated()
        ->assertJsonPath('section', 'C')
        ->assertJsonPath('teacher.name', 'Teacher Demo');

    $this->assertDatabaseHas('class_sections', [
        'section' => 'C',
        'teacher_id' => $teacher->id,
    ]);
});

it('rejects duplicate section for same term and subject', function () {
    $registrar = classSectionRegistrar();
    $section = makeClassSectionCatalog();

    $response = $this->actingAs($registrar)->postJson('/api/class-sections', [
        'school_term_id' => $section->school_term_id,
        'subject_id' => $section->subject_id,
        'teacher_id' => classSectionTeacher()->id,
        'section' => 'A',
        'schedule_day' => 'MWF',
        'schedule_time' => '8:00 AM - 9:00 AM',
        'room' => '201',
    ]);

    $response->assertStatus(422)->assertJsonValidationErrors(['section']);
});

it('updates a class section', function () {
    $registrar = classSectionRegistrar();
    $teacher = classSectionTeacher();
    $section = makeClassSectionCatalog();

    $response = $this->actingAs($registrar)->putJson("/api/class-sections/{$section->id}", [
        'school_term_id' => $section->school_term_id,
        'subject_id' => $section->subject_id,
        'teacher_id' => $teacher->id,
        'section' => 'B',
        'schedule_day' => 'TTH',
        'schedule_time' => '1:00 PM - 2:30 PM',
        'room' => 'LAB 1',
    ]);

    $response->assertOk()
        ->assertJsonPath('section', 'B')
        ->assertJsonPath('teacher_id', $teacher->id)
        ->assertJsonPath('room', 'LAB 1');
});

it('shows enrolled students for a class section', function () {
    $registrar = classSectionRegistrar();
    $teacher = classSectionTeacher();
    $section = makeClassSectionCatalog(['teacher_id' => $teacher->id]);
        $enrollment = attachEnrollmentToSection($section);

    $response = $this->actingAs($registrar)->getJson("/api/class-sections/{$section->id}");

    $response->assertOk()
        ->assertJsonPath('id', $section->id)
        ->assertJsonPath('enrollment_subjects_count', 1)
        ->assertJsonPath('enrollment_subjects.0.id', $enrollment->id)
        ->assertJsonPath('enrollment_subjects.0.admission.student_profile.last_name', 'Doe')
        ->assertJsonPath('enrollment_subjects.0.admission.program.code', 'BSIT')
        ->assertJsonPath('enrollment_subjects.0.admission.year_level', 1);
});

it('exports enrolled students for a class section', function () {
    $registrar = classSectionRegistrar();
    $teacher = classSectionTeacher();
    $section = makeClassSectionCatalog(['teacher_id' => $teacher->id]);
    attachEnrollmentToSection($section);

    $response = $this->actingAs($registrar)->get("/api/class-sections/{$section->id}/students/export");

    $response->assertOk();
    $response->assertHeader(
        'content-type',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
    );
    expect($response->headers->get('content-disposition'))->toContain('.xlsx');
});

it('exports class sections report for registrar', function () {
    $registrar = classSectionRegistrar();
    $teacher = classSectionTeacher();
    $withStudents = makeClassSectionCatalog(['teacher_id' => $teacher->id, 'section' => 'A']);
    makeClassSectionCatalog(['section' => 'B', 'teacher_id' => $teacher->id]);
    attachEnrollmentToSection($withStudents);

    $response = $this->actingAs($registrar)->get('/api/class-sections/export');

    $response->assertOk();
    $response->assertHeader(
        'content-type',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
    );
    expect($response->headers->get('content-disposition'))->toContain('.xlsx');
});

it('exports class sections report with active filters', function () {
    $registrar = classSectionRegistrar();
    $teacher = classSectionTeacher();
    $withStudents = makeClassSectionCatalog(['teacher_id' => $teacher->id, 'section' => 'A']);
    makeClassSectionCatalog(['section' => 'B', 'teacher_id' => $teacher->id]);
    attachEnrollmentToSection($withStudents);

    $response = $this->actingAs($registrar)->get('/api/class-sections/export?enrollment_status=with_students');

    $response->assertOk();
    $response->assertHeader(
        'content-type',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
    );
});

it('filters class sections by academic level', function () {
    $registrar = classSectionRegistrar();
    $teacher = classSectionTeacher();
    makeClassSectionCatalog(['teacher_id' => $teacher->id, 'section' => 'A']);
    $shsSubject = Subject::firstOrCreate(
        ['code' => 'STEM-001', 'academic_level' => 'shs'],
        [
            'title' => 'GENERAL SCIENCE',
            'units' => 3,
            'is_active' => true,
        ]
    );
    ClassSection::create([
        'school_term_id' => classSectionTerm()->id,
        'subject_id' => $shsSubject->id,
        'teacher_id' => $teacher->id,
        'section' => 'A',
        'schedule_day' => 'MWF',
        'schedule_time' => '8:00 AM - 9:00 AM',
        'room' => 'ROOM 101',
    ]);

    $this->actingAs($registrar)
        ->getJson('/api/class-sections?page=1&per_page=10&academic_level=college')
        ->assertOk()
        ->assertJsonCount(1, 'data');

    $this->actingAs($registrar)
        ->getJson('/api/class-sections?page=1&per_page=10&academic_level=shs')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.subject.code', 'STEM-001');
});

it('filters class sections by search and enrollment status', function () {
    $registrar = classSectionRegistrar();
    $teacher = classSectionTeacher();
    $withStudents = makeClassSectionCatalog(['teacher_id' => $teacher->id, 'section' => 'A']);
    makeClassSectionCatalog(['section' => 'B', 'teacher_id' => $teacher->id]);
    attachEnrollmentToSection($withStudents);

    $this->actingAs($registrar)
        ->getJson('/api/class-sections?page=1&per_page=10&enrollment_status=empty')
        ->assertOk()
        ->assertJsonCount(1, 'data');

    $this->actingAs($registrar)
        ->getJson('/api/class-sections?page=1&per_page=10&enrollment_status=with_students')
        ->assertOk()
        ->assertJsonCount(1, 'data');

    $this->actingAs($registrar)
        ->getJson('/api/class-sections?page=1&per_page=10&search=ITE')
        ->assertOk()
        ->assertJson(fn ($json) => $json->where('total', 2)->etc());
});

it('allows registrar to delete an empty class section', function () {
    $registrar = classSectionRegistrar();
    $teacher = classSectionTeacher();
    $section = makeClassSectionCatalog(['teacher_id' => $teacher->id, 'section' => 'Z']);

    $this->actingAs($registrar, 'sanctum')
        ->deleteJson("/api/class-sections/{$section->id}")
        ->assertOk()
        ->assertJsonPath('message', 'Class section deleted.');

    expect(ClassSection::find($section->id))->toBeNull();
});

it('blocks deleting a class section with enrolled students', function () {
    $registrar = classSectionRegistrar();
    $teacher = classSectionTeacher();
    $section = makeClassSectionCatalog(['teacher_id' => $teacher->id, 'section' => 'Y']);
    attachEnrollmentToSection($section);

    $this->actingAs($registrar, 'sanctum')
        ->deleteJson("/api/class-sections/{$section->id}")
        ->assertUnprocessable()
        ->assertJsonPath('usage.can_delete', false)
        ->assertJsonPath('usage.enrolled_students', 1);

    expect(ClassSection::find($section->id))->not->toBeNull();
});

it('forbids teachers from deleting class sections', function () {
    $teacher = classSectionTeacher();
    $section = makeClassSectionCatalog(['teacher_id' => $teacher->id, 'section' => 'X']);

    $this->actingAs($teacher, 'sanctum')
        ->deleteJson("/api/class-sections/{$section->id}")
        ->assertForbidden();
});
