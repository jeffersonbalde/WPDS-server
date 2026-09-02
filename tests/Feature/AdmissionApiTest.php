<?php

use App\Enums\UserRole;
use App\Models\Admission;
use App\Models\ClassSection;
use App\Models\EnrollmentSubject;
use App\Models\Grade;
use App\Models\Program;
use App\Models\SchoolTerm;
use App\Models\StudentProfile;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function admissionRegistrar(): User
{
    return User::factory()->create([
        'role' => UserRole::Registrar,
        'is_active' => true,
    ]);
}

function admissionTeacher(): User
{
    return User::factory()->create([
        'role' => UserRole::Teacher,
        'is_active' => true,
    ]);
}

function makeAdmissionForStatusTest(string $status = 'enrolled'): Admission
{
    $term = SchoolTerm::create([
        'name' => 'FIRST SEMESTER, 2025-2026',
        'term_type' => 'first_semester',
        'school_year' => '2025-2026',
        'is_active' => true,
    ]);
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

    return Admission::create([
        'admission_number' => 'ADM-'.uniqid(),
        'student_profile_id' => $student->id,
        'school_term_id' => $term->id,
        'program_id' => $program->id,
        'year_level' => 1,
        'section' => 'A',
        'status' => $status,
    ]);
}

it('allows registrar to update admission status', function () {
    $admission = makeAdmissionForStatusTest('enrolled');
    $registrar = admissionRegistrar();

    $this->actingAs($registrar, 'sanctum')
        ->patchJson("/api/admissions/{$admission->id}/status", ['status' => 'withdrawn'])
        ->assertOk()
        ->assertJsonPath('status', 'withdrawn');

    expect($admission->fresh()->status)->toBe('withdrawn');
});

it('allows registrar to mark admission as completed', function () {
    $admission = makeAdmissionForStatusTest('enrolled');
    $registrar = admissionRegistrar();

    $this->actingAs($registrar, 'sanctum')
        ->patchJson("/api/admissions/{$admission->id}/status", ['status' => 'completed'])
        ->assertOk()
        ->assertJsonPath('status', 'completed');
});

it('allows registrar to re-enroll a withdrawn admission', function () {
    $admission = makeAdmissionForStatusTest('withdrawn');
    $registrar = admissionRegistrar();

    $this->actingAs($registrar, 'sanctum')
        ->patchJson("/api/admissions/{$admission->id}/status", ['status' => 'enrolled'])
        ->assertOk()
        ->assertJsonPath('status', 'enrolled');
});

it('rejects invalid admission status', function () {
    $admission = makeAdmissionForStatusTest();
    $registrar = admissionRegistrar();

    $this->actingAs($registrar, 'sanctum')
        ->patchJson("/api/admissions/{$admission->id}/status", ['status' => 'invalid'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['status']);
});

it('forbids teachers from updating admission status', function () {
    $admission = makeAdmissionForStatusTest();
    $teacher = admissionTeacher();

    $this->actingAs($teacher, 'sanctum')
        ->patchJson("/api/admissions/{$admission->id}/status", ['status' => 'withdrawn'])
        ->assertForbidden();
});

it('returns admission details with enrolled subjects, grades, and instructor', function () {
    $admission = makeAdmissionForStatusTest('enrolled');
    $teacher = User::factory()->create([
        'role' => UserRole::Teacher,
        'name' => 'Prof. Smith',
        'is_active' => true,
    ]);
    $subject = Subject::create([
        'code' => 'CS 101',
        'title' => 'Intro to Computing',
        'academic_level' => 'college',
        'units' => 3,
        'is_active' => true,
    ]);
    $classSection = ClassSection::create([
        'school_term_id' => $admission->school_term_id,
        'subject_id' => $subject->id,
        'teacher_id' => $teacher->id,
        'section' => 'A',
        'schedule_day' => 'MWF',
        'schedule_time' => '8:00 AM',
        'room' => 'LAB 1',
    ]);
    $enrollment = EnrollmentSubject::create([
        'admission_id' => $admission->id,
        'class_section_id' => $classSection->id,
    ]);
    Grade::create([
        'enrollment_subject_id' => $enrollment->id,
        'prelim' => 85,
        'midterm' => 88,
        'semi_final' => 90,
        'final' => 92,
        'final_grade' => 89,
        'remarks' => 'PASSED',
    ]);

    $registrar = admissionRegistrar();

    $this->actingAs($registrar, 'sanctum')
        ->getJson("/api/admissions/{$admission->id}")
        ->assertOk()
        ->assertJsonPath('admission_number', $admission->admission_number)
        ->assertJsonPath('enrollment_subjects.0.class_section.subject.code', 'CS 101')
        ->assertJsonPath('enrollment_subjects.0.class_section.teacher.name', 'Prof. Smith')
        ->assertJsonPath('enrollment_subjects.0.grade.final_grade', '89.00')
        ->assertJsonPath('enrollment_subjects.0.grade.remarks', 'PASSED');
});

it('allows registrar to export admissions with filters', function () {
    $admission = makeAdmissionForStatusTest('enrolled');
    $registrar = admissionRegistrar();

    $this->actingAs($registrar, 'sanctum')
        ->get('/api/admissions/export?status=enrolled')
        ->assertOk()
        ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
});

it('forbids students from exporting admissions', function () {
    makeAdmissionForStatusTest();
    $student = User::factory()->create(['role' => UserRole::Student]);

    $this->actingAs($student, 'sanctum')
        ->get('/api/admissions/export')
        ->assertForbidden();
});
