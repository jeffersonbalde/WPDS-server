<?php

use App\Enums\UserRole;
use App\Models\Admission;
use App\Models\ClassSection;
use App\Models\CurriculumItem;
use App\Models\EnrollmentSubject;
use App\Models\Grade;
use App\Models\GradeSubmission;
use App\Models\Program;
use App\Models\SchoolTerm;
use App\Models\StudentProfile;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function curriculumProgram(string $code): Program
{
    return Program::create([
        'code' => $code,
        'name' => "Program {$code}",
        'academic_level' => 'college',
        'track_type' => 'degree',
        'duration_years' => 4,
        'is_active' => true,
    ]);
}

function curriculumSubjectRow(array $overrides = []): Subject
{
    return Subject::create(array_merge([
        'code' => 'ITE 111',
        'title' => 'INTRODUCTION TO COMPUTING',
        'units' => 3,
        'academic_level' => 'college',
        'is_active' => true,
    ], $overrides));
}

function curriculumTerm(string $name, string $year): SchoolTerm
{
    return SchoolTerm::create([
        'name' => $name,
        'term_type' => 'first_semester',
        'school_year' => $year,
        'is_active' => false,
    ]);
}

/**
 * Enrol the student in one subject under a program/term and (optionally) grade it.
 */
function enrolStudentInSubject(StudentProfile $profile, Program $program, SchoolTerm $term, Subject $subject, ?array $grade = null, bool $release = false): EnrollmentSubject
{
    $admission = Admission::create([
        'admission_number' => 'ADM-'.uniqid(),
        'student_profile_id' => $profile->id,
        'school_term_id' => $term->id,
        'program_id' => $program->id,
        'year_level' => 1,
        'section' => 'A',
        'status' => 'completed',
    ]);

    $section = ClassSection::create([
        'school_term_id' => $term->id,
        'subject_id' => $subject->id,
        'section' => 'A',
        'schedule_day' => 'MWF',
        'schedule_time' => '8:00 AM',
        'room' => 'R1',
    ]);

    $enrollment = EnrollmentSubject::create([
        'admission_id' => $admission->id,
        'class_section_id' => $section->id,
    ]);

    Grade::create(array_merge([
        'enrollment_subject_id' => $enrollment->id,
    ], $grade ?? []));

    if ($release) {
        foreach (GradeSubmission::PERIODS as $period) {
            GradeSubmission::create([
                'class_section_id' => $section->id,
                'period' => $period,
                'status' => 'released',
            ]);
        }
    }

    return $enrollment;
}

function makeStudentWithProfile(Program $program): array
{
    $user = User::factory()->create(['role' => UserRole::Student, 'is_active' => true]);
    $profile = StudentProfile::create([
        'user_id' => $user->id,
        'student_no' => '2026-'.substr(uniqid(), -4),
        'academic_level' => 'college',
        'program_id' => $program->id,
        'year_level' => 2,
        'section' => 'A',
        'last_name' => 'Doe',
        'first_name' => 'Jane',
    ]);

    return [$user, $profile];
}

it('lists every program a shiftee student has been admitted under', function () {
    $accountancy = curriculumProgram('BSA');
    $compsci = curriculumProgram('BSCS');
    [$user, $profile] = makeStudentWithProfile($compsci);

    $termA = curriculumTerm('FIRST SEMESTER, 2022-2023', '2022-2023');
    $termB = curriculumTerm('FIRST SEMESTER, 2023-2024', '2023-2024');
    $subject = curriculumSubjectRow(['code' => 'GE 1', 'title' => 'UNDERSTANDING THE SELF']);

    enrolStudentInSubject($profile, $accountancy, $termA, $subject);
    enrolStudentInSubject($profile, $compsci, $termB, curriculumSubjectRow(['code' => 'CS 1', 'title' => 'PROGRAMMING 1']));

    $response = $this->actingAs($user, 'sanctum')
        ->getJson('/api/my/curriculum')
        ->assertOk()
        ->assertJsonPath('selected_program_id', $compsci->id)
        ->assertJsonCount(2, 'programs');

    $codes = collect($response->json('programs'))->pluck('code')->sort()->values()->all();
    expect($codes)->toBe(['BSA', 'BSCS']);
    expect(collect($response->json('programs'))->firstWhere('code', 'BSCS')['is_current'])->toBeTrue();
});

it('lets the student switch which program curriculum is shown', function () {
    $accountancy = curriculumProgram('BSA');
    $compsci = curriculumProgram('BSCS');
    [$user, $profile] = makeStudentWithProfile($compsci);

    $term = curriculumTerm('FIRST SEMESTER, 2022-2023', '2022-2023');
    enrolStudentInSubject($profile, $accountancy, $term, curriculumSubjectRow(['code' => 'ACC 1', 'title' => 'FIN ACCOUNTING']));

    $acctSubject = curriculumSubjectRow(['code' => 'ACC 101', 'title' => 'ACCOUNTING PRINCIPLES']);
    CurriculumItem::create([
        'program_id' => $accountancy->id,
        'subject_id' => $acctSubject->id,
        'year_level' => 1,
        'semester' => 1,
    ]);

    $this->actingAs($user, 'sanctum')
        ->getJson('/api/my/curriculum?program_id='.$accountancy->id)
        ->assertOk()
        ->assertJsonPath('selected_program_id', $accountancy->id)
        ->assertJsonPath('items.0.subject.code', 'ACC 101');
});

it('maps the student\'s real enrollment record onto matching curriculum subjects', function () {
    $compsci = curriculumProgram('BSCS');
    [$user, $profile] = makeStudentWithProfile($compsci);

    $subject = curriculumSubjectRow(['code' => 'ITE 111', 'title' => 'INTRODUCTION TO COMPUTING']);
    CurriculumItem::create([
        'program_id' => $compsci->id,
        'subject_id' => $subject->id,
        'year_level' => 1,
        'semester' => 1,
    ]);

    $term = curriculumTerm('FIRST SEMESTER, 2022-2023', '2022-2023');
    enrolStudentInSubject(
        $profile,
        $compsci,
        $term,
        $subject,
        ['prelim' => 1.0, 'midterm' => 1.0, 'semi_final' => 1.0, 'final' => 1.0, 'final_grade' => 1.0, 'remarks' => 'PASSED'],
        release: true,
    );

    $this->actingAs($user, 'sanctum')
        ->getJson('/api/my/curriculum')
        ->assertOk()
        ->assertJsonPath('items.0.taken.term_name', 'FIRST SEMESTER, 2022-2023')
        ->assertJsonPath('items.0.taken.program_code', 'BSCS')
        ->assertJsonPath('items.0.taken.final_grade', '1.00')
        ->assertJsonPath('items.0.taken.remarks', 'PASSED');
});

it('hides the final grade until every grading period is released', function () {
    $compsci = curriculumProgram('BSCS');
    [$user, $profile] = makeStudentWithProfile($compsci);

    $subject = curriculumSubjectRow(['code' => 'ITE 112', 'title' => 'COMPUTER PROGRAMMING 1']);
    CurriculumItem::create([
        'program_id' => $compsci->id,
        'subject_id' => $subject->id,
        'year_level' => 1,
        'semester' => 1,
    ]);

    $term = curriculumTerm('FIRST SEMESTER, 2023-2024', '2023-2024');
    enrolStudentInSubject(
        $profile,
        $compsci,
        $term,
        $subject,
        ['prelim' => 1.0, 'midterm' => 1.0, 'semi_final' => 1.0, 'final' => 1.0, 'final_grade' => 1.0, 'remarks' => 'PASSED'],
        release: false,
    );

    $this->actingAs($user, 'sanctum')
        ->getJson('/api/my/curriculum')
        ->assertOk()
        ->assertJsonPath('items.0.taken.term_name', 'FIRST SEMESTER, 2023-2024')
        ->assertJsonPath('items.0.taken.final_grade', null)
        ->assertJsonPath('items.0.taken.remarks', null);
});

it('returns an empty payload for a user without a student profile', function () {
    $registrar = User::factory()->create(['role' => UserRole::Registrar, 'is_active' => true]);

    $this->actingAs($registrar, 'sanctum')
        ->getJson('/api/my/curriculum')
        ->assertOk()
        ->assertJsonPath('items', [])
        ->assertJsonPath('programs', []);
});
