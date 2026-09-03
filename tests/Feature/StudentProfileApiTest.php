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

function studentApiRegistrar(): User
{
    return User::factory()->create([
        'role' => UserRole::Registrar,
        'is_active' => true,
    ]);
}

function studentApiTeacher(): User
{
    return User::factory()->create([
        'role' => UserRole::Teacher,
        'is_active' => true,
    ]);
}

function makeStudentProfileRecord(array $overrides = []): StudentProfile
{
    $user = User::factory()->create([
        'role' => UserRole::Student,
        'is_active' => true,
    ]);

    return StudentProfile::create(array_merge([
        'user_id' => $user->id,
        'student_no' => '2026-'.str_pad((string) random_int(1, 9999), 4, '0', STR_PAD_LEFT),
        'academic_level' => 'college',
        'last_name' => 'REYES',
        'first_name' => 'ANA',
        'middle_name' => 'G',
        'contact_email' => fake()->unique()->safeEmail(),
    ], $overrides));
}

it('includes can_delete on the students list', function () {
    $empty = makeStudentProfileRecord(['student_no' => '2026-0101', 'last_name' => 'ALVAREZ']);
    $withAdmission = makeStudentProfileRecord(['student_no' => '2026-0102', 'last_name' => 'BAUTISTA']);

    $program = Program::create([
        'code' => 'BSIT',
        'name' => 'BSIT',
        'academic_level' => 'college',
        'track_type' => 'degree',
        'duration_years' => 4,
        'is_active' => true,
    ]);
    $term = SchoolTerm::create([
        'name' => 'FIRST SEMESTER, 2025-2026',
        'term_type' => 'first_semester',
        'school_year' => '2025-2026',
        'is_active' => true,
    ]);
    Admission::create([
        'admission_number' => 'ADM-LIST-1',
        'student_profile_id' => $withAdmission->id,
        'school_term_id' => $term->id,
        'program_id' => $program->id,
        'year_level' => 1,
        'section' => 'A',
        'status' => 'enrolled',
    ]);

    $rows = $this->actingAs(studentApiRegistrar(), 'sanctum')
        ->getJson('/api/students?per_page=25')
        ->assertOk()
        ->json('data');

    $byId = collect($rows)->keyBy('id');

    expect($byId[$empty->id]['can_delete'])->toBeTrue()
        ->and($byId[$empty->id]['admissions_count'])->toBe(0)
        ->and($byId[$withAdmission->id]['can_delete'])->toBeFalse()
        ->and($byId[$withAdmission->id]['admissions_count'])->toBe(1);
});

it('lets a registrar delete a student with no admissions or grades', function () {
    $student = makeStudentProfileRecord(['student_no' => '2026-0201']);
    $userId = $student->user_id;

    $this->actingAs(studentApiRegistrar(), 'sanctum')
        ->deleteJson('/api/students/'.$student->id)
        ->assertOk()
        ->assertJsonPath('message', 'Student deleted.');

    $this->assertDatabaseMissing('student_profiles', ['id' => $student->id]);
    $this->assertDatabaseMissing('users', ['id' => $userId]);
});

it('blocks deleting a student who already has an admission', function () {
    $student = makeStudentProfileRecord(['student_no' => '2026-0202']);
    $program = Program::create([
        'code' => 'AIT',
        'name' => 'AIT',
        'academic_level' => 'college',
        'track_type' => 'associate',
        'duration_years' => 2,
        'is_active' => true,
    ]);
    $term = SchoolTerm::create([
        'name' => 'FIRST SEMESTER, 2025-2026',
        'term_type' => 'first_semester',
        'school_year' => '2025-2026',
        'is_active' => true,
    ]);
    Admission::create([
        'admission_number' => 'ADM-BLOCK-1',
        'student_profile_id' => $student->id,
        'school_term_id' => $term->id,
        'program_id' => $program->id,
        'year_level' => 1,
        'section' => 'A',
        'status' => 'enrolled',
    ]);

    $this->actingAs(studentApiRegistrar(), 'sanctum')
        ->deleteJson('/api/students/'.$student->id)
        ->assertUnprocessable()
        ->assertJsonPath('usage.can_delete', false)
        ->assertJsonPath('usage.admissions', 1);

    $this->assertDatabaseHas('student_profiles', ['id' => $student->id]);
    $this->assertDatabaseHas('users', ['id' => $student->user_id]);
});

it('blocks deleting a student who already has grades', function () {
    $student = makeStudentProfileRecord(['student_no' => '2026-0203']);
    $program = Program::create([
        'code' => 'BSIT',
        'name' => 'BSIT',
        'academic_level' => 'college',
        'track_type' => 'degree',
        'duration_years' => 4,
        'is_active' => true,
    ]);
    $term = SchoolTerm::create([
        'name' => 'FIRST SEMESTER, 2025-2026',
        'term_type' => 'first_semester',
        'school_year' => '2025-2026',
        'is_active' => true,
    ]);
    $subject = Subject::create([
        'code' => 'ITE 111',
        'title' => 'INTRODUCTION TO COMPUTING',
        'units' => 3,
        'academic_level' => 'college',
        'is_active' => true,
    ]);
    $section = ClassSection::create([
        'school_term_id' => $term->id,
        'subject_id' => $subject->id,
        'section' => 'A',
    ]);
    $admission = Admission::create([
        'admission_number' => 'ADM-GRADE-1',
        'student_profile_id' => $student->id,
        'school_term_id' => $term->id,
        'program_id' => $program->id,
        'year_level' => 1,
        'section' => 'A',
        'status' => 'enrolled',
    ]);
    $enrollment = EnrollmentSubject::create([
        'admission_id' => $admission->id,
        'class_section_id' => $section->id,
    ]);
    Grade::create([
        'enrollment_subject_id' => $enrollment->id,
        'prelim' => 1.25,
        'is_locked' => false,
    ]);

    $this->actingAs(studentApiRegistrar(), 'sanctum')
        ->getJson('/api/students/'.$student->id.'/usage')
        ->assertOk()
        ->assertJsonPath('can_delete', false)
        ->assertJsonPath('grades', 1);

    $this->actingAs(studentApiRegistrar(), 'sanctum')
        ->deleteJson('/api/students/'.$student->id)
        ->assertUnprocessable()
        ->assertJsonPath('usage.grades', 1);

    $this->assertDatabaseHas('student_profiles', ['id' => $student->id]);
});

it('forbids teachers from deleting students', function () {
    $student = makeStudentProfileRecord(['student_no' => '2026-0204']);

    $this->actingAs(studentApiTeacher(), 'sanctum')
        ->deleteJson('/api/students/'.$student->id)
        ->assertForbidden();

    $this->assertDatabaseHas('student_profiles', ['id' => $student->id]);
});
