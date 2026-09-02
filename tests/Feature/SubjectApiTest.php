<?php

use App\Enums\UserRole;
use App\Models\Admission;
use App\Models\ClassSection;
use App\Models\CurriculumItem;
use App\Models\EnrollmentSubject;
use App\Models\Program;
use App\Models\SchoolTerm;
use App\Models\StudentProfile;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

use PhpOffice\PhpSpreadsheet\IOFactory;

uses(RefreshDatabase::class);

function subjectRegistrar(): User
{
    return User::factory()->create([
        'role' => UserRole::Registrar,
        'is_active' => true,
    ]);
}

function makeSubjectCatalog(array $overrides = []): Subject
{
    return Subject::create(array_merge([
        'code' => 'ITE 111',
        'title' => 'INTRODUCTION TO COMPUTING',
        'units' => 3,
        'academic_level' => 'college',
        'is_active' => true,
    ], $overrides));
}

it('returns subject usage with curriculum and enrollments', function () {
    $program = Program::create([
        'code' => 'BSIT',
        'name' => 'BSIT',
        'academic_level' => 'college',
        'track_type' => 'degree',
        'duration_years' => 4,
        'is_active' => true,
    ]);
    $subject = makeSubjectCatalog();
    CurriculumItem::create([
        'program_id' => $program->id,
        'subject_id' => $subject->id,
        'year_level' => 1,
        'semester' => 1,
    ]);

    $term = SchoolTerm::create([
        'name' => 'FIRST SEMESTER, 2025-2026',
        'term_type' => 'first_semester',
        'school_year' => '2025-2026',
        'is_active' => true,
    ]);
    $section = ClassSection::create([
        'school_term_id' => $term->id,
        'subject_id' => $subject->id,
        'section' => 'A',
    ]);
    $user = User::factory()->create(['role' => UserRole::Student]);
    $student = StudentProfile::create([
        'user_id' => $user->id,
        'student_no' => '2026-0200',
        'program_id' => $program->id,
        'academic_level' => 'college',
        'last_name' => 'Doe',
        'first_name' => 'Jane',
    ]);
    $admission = Admission::create([
        'admission_number' => 'ADM-001',
        'student_profile_id' => $student->id,
        'school_term_id' => $term->id,
        'program_id' => $program->id,
        'year_level' => 1,
        'status' => 'enrolled',
    ]);
    EnrollmentSubject::create([
        'admission_id' => $admission->id,
        'class_section_id' => $section->id,
    ]);

    $this->actingAs(subjectRegistrar(), 'sanctum')
        ->getJson('/api/subjects/'.$subject->id.'/usage')
        ->assertOk()
        ->assertJsonPath('code', 'ITE 111')
        ->assertJsonPath('in_use', true)
        ->assertJsonPath('can_delete', false)
        ->assertJsonPath('curriculum.total', 1)
        ->assertJsonPath('class_sections.total', 1)
        ->assertJsonPath('enrollments.total', 1);
});

it('blocks deleting a subject that is in use', function () {
    $program = Program::create([
        'code' => 'BSIT',
        'name' => 'BSIT',
        'academic_level' => 'college',
        'track_type' => 'degree',
        'duration_years' => 4,
        'is_active' => true,
    ]);
    $subject = makeSubjectCatalog();
    CurriculumItem::create([
        'program_id' => $program->id,
        'subject_id' => $subject->id,
        'year_level' => 1,
        'semester' => 1,
    ]);

    $this->actingAs(subjectRegistrar(), 'sanctum')
        ->deleteJson('/api/subjects/'.$subject->id)
        ->assertUnprocessable()
        ->assertJsonPath('usage.can_delete', false);

    $this->assertDatabaseHas('subjects', ['id' => $subject->id]);
});

it('allows deleting an unused subject', function () {
    $subject = makeSubjectCatalog(['code' => 'TEMP 1', 'title' => 'TEMP SUBJECT']);

    $this->actingAs(subjectRegistrar(), 'sanctum')
        ->deleteJson('/api/subjects/'.$subject->id)
        ->assertOk();

    $this->assertDatabaseMissing('subjects', ['id' => $subject->id]);
});

it('lets a registrar deactivate a subject', function () {
    $subject = makeSubjectCatalog();

    $this->actingAs(subjectRegistrar(), 'sanctum')
        ->putJson('/api/subjects/'.$subject->id, ['is_active' => false])
        ->assertOk()
        ->assertJsonPath('is_active', false);
});

it('returns curriculum removal usage with enrolled students', function () {
    $program = Program::create([
        'code' => 'BSIT',
        'name' => 'BSIT',
        'academic_level' => 'college',
        'track_type' => 'degree',
        'duration_years' => 4,
        'is_active' => true,
    ]);
    $subject = makeSubjectCatalog();
    $item = CurriculumItem::create([
        'program_id' => $program->id,
        'subject_id' => $subject->id,
        'year_level' => 1,
        'semester' => 1,
    ]);
    $term = SchoolTerm::create([
        'name' => 'FIRST SEMESTER, 2025-2026',
        'term_type' => 'first_semester',
        'school_year' => '2025-2026',
        'is_active' => true,
    ]);
    $section = ClassSection::create([
        'school_term_id' => $term->id,
        'subject_id' => $subject->id,
        'section' => 'A',
    ]);
    $user = User::factory()->create(['role' => UserRole::Student]);
    $student = StudentProfile::create([
        'user_id' => $user->id,
        'student_no' => '2026-0201',
        'program_id' => $program->id,
        'academic_level' => 'college',
        'last_name' => 'Doe',
        'first_name' => 'Jane',
    ]);
    $admission = Admission::create([
        'admission_number' => 'ADM-002',
        'student_profile_id' => $student->id,
        'school_term_id' => $term->id,
        'program_id' => $program->id,
        'year_level' => 1,
        'status' => 'enrolled',
    ]);
    EnrollmentSubject::create([
        'admission_id' => $admission->id,
        'class_section_id' => $section->id,
    ]);

    $this->actingAs(subjectRegistrar(), 'sanctum')
        ->getJson('/api/curriculum/'.$item->id.'/usage')
        ->assertOk()
        ->assertJsonPath('has_enrolled_students', true)
        ->assertJsonPath('enrollments.total', 1);
});

it('still allows curriculum removal even when students are enrolled', function () {
    $program = Program::create([
        'code' => 'BSIT',
        'name' => 'BSIT',
        'academic_level' => 'college',
        'track_type' => 'degree',
        'duration_years' => 4,
        'is_active' => true,
    ]);
    $subject = makeSubjectCatalog();
    $item = CurriculumItem::create([
        'program_id' => $program->id,
        'subject_id' => $subject->id,
        'year_level' => 1,
        'semester' => 1,
    ]);
    $term = SchoolTerm::create([
        'name' => 'FIRST SEMESTER, 2025-2026',
        'term_type' => 'first_semester',
        'school_year' => '2025-2026',
        'is_active' => true,
    ]);
    $section = ClassSection::create([
        'school_term_id' => $term->id,
        'subject_id' => $subject->id,
        'section' => 'A',
    ]);
    $user = User::factory()->create(['role' => UserRole::Student]);
    $student = StudentProfile::create([
        'user_id' => $user->id,
        'student_no' => '2026-0202',
        'program_id' => $program->id,
        'academic_level' => 'college',
        'last_name' => 'Doe',
        'first_name' => 'Jane',
    ]);
    $admission = Admission::create([
        'admission_number' => 'ADM-003',
        'student_profile_id' => $student->id,
        'school_term_id' => $term->id,
        'program_id' => $program->id,
        'year_level' => 1,
        'status' => 'enrolled',
    ]);
    EnrollmentSubject::create([
        'admission_id' => $admission->id,
        'class_section_id' => $section->id,
    ]);

    $this->actingAs(subjectRegistrar(), 'sanctum')
        ->deleteJson('/api/curriculum/'.$item->id)
        ->assertOk();

    $this->assertDatabaseMissing('curriculum_items', ['id' => $item->id]);
    $this->assertDatabaseHas('enrollment_subjects', ['admission_id' => $admission->id]);
});

it('paginates subjects with catalog summary', function () {
    makeSubjectCatalog(['code' => 'ITE 111', 'academic_level' => 'college']);
    makeSubjectCatalog(['code' => 'GEC 101', 'academic_level' => 'college']);
    makeSubjectCatalog(['code' => 'STEM 11', 'academic_level' => 'shs']);
    for ($i = 1; $i <= 9; $i++) {
        makeSubjectCatalog(['code' => "SUB {$i}", 'academic_level' => 'college']);
    }

    $this->actingAs(subjectRegistrar(), 'sanctum')
        ->getJson('/api/subjects?page=1&per_page=10')
        ->assertOk()
        ->assertJsonPath('per_page', 10)
        ->assertJsonPath('total', 12)
        ->assertJsonPath('last_page', 2)
        ->assertJsonCount(10, 'data')
        ->assertJsonPath('summary.total', 12)
        ->assertJsonPath('summary.college', 11)
        ->assertJsonPath('summary.shs', 1);
});

it('filters subjects by search when paginating', function () {
    makeSubjectCatalog(['code' => 'ITE 111', 'title' => 'INTRODUCTION TO COMPUTING']);
    makeSubjectCatalog(['code' => 'GEC 101', 'title' => 'UNDERSTANDING THE SELF']);

    $this->actingAs(subjectRegistrar(), 'sanctum')
        ->getJson('/api/subjects?page=1&search=GEC')
        ->assertOk()
        ->assertJsonPath('total', 1)
        ->assertJsonPath('data.0.code', 'GEC 101');
});

it('exports subjects to excel', function () {
    makeSubjectCatalog(['code' => 'ITE 111', 'title' => 'INTRODUCTION TO COMPUTING']);
    makeSubjectCatalog(['code' => 'STEM 11', 'title' => 'PRECALCULUS', 'academic_level' => 'shs']);

    $response = $this->actingAs(subjectRegistrar(), 'sanctum')
        ->get('/api/subjects/export')
        ->assertOk();

    expect($response->headers->get('content-type'))
        ->toContain('spreadsheetml');
    expect($response->headers->get('content-disposition'))
        ->toContain('subjects-export-');

    $path = tempnam(sys_get_temp_dir(), 'wpds-subj-').'.xlsx';
    file_put_contents($path, $response->streamedContent());
    $sheet = IOFactory::load($path)->getActiveSheet();
    unlink($path);

    expect($sheet->getCell('A1')->getValue())->toBe('Code');
    expect($sheet->getCell('B1')->getValue())->toBe('Title');
    expect($sheet->getCell('A2')->getValue())->toBe('ITE 111');
    expect($sheet->getCell('D2')->getValue())->toBe('College');
});

it('forbids a teacher from exporting subjects', function () {
    $teacher = User::factory()->create([
        'role' => UserRole::Teacher,
        'is_active' => true,
    ]);

    $this->actingAs($teacher, 'sanctum')
        ->getJson('/api/subjects/export')
        ->assertForbidden();
});
