<?php

use App\Models\ClassSection;
use App\Models\Grade;
use App\Models\GradeSubmission;
use App\Models\User;
use App\Services\GradeCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function activeTermSection(string $subjectCode): ClassSection
{
    return ClassSection::query()
        ->whereHas('schoolTerm', fn ($q) => $q->where('is_active', true))
        ->whereHas('subject', fn ($q) => $q->where('code', $subjectCode))
        ->firstOrFail();
}

function teacherUser(): User
{
    return User::where('email', 'teacher@westprime.edu')->firstOrFail();
}

function registrarUser(): User
{
    return User::where('email', 'registrar@westprime.edu')->firstOrFail();
}

it('lets the assigned teacher submit a period once every student has a grade', function () {
    $this->seed();
    $teacher = teacherUser();
    $section = activeTermSection('CS 232');
    $enrollmentIds = $section->enrollmentSubjects()->pluck('id');

    $this->actingAs($teacher, 'sanctum')->postJson("/api/class-sections/{$section->id}/grades", [
        'grades' => $enrollmentIds->map(fn ($id) => [
            'enrollment_subject_id' => $id,
            'semi_final' => 1.75,
        ])->all(),
    ])->assertOk();

    $this->actingAs($teacher, 'sanctum')
        ->postJson("/api/class-sections/{$section->id}/grades/submit", ['period' => 'semi_final'])
        ->assertCreated();

    $this->assertDatabaseHas('grade_submissions', [
        'class_section_id' => $section->id,
        'period' => 'semi_final',
        'status' => 'pending',
    ]);
});

it('rejects a submission when a student is missing that period grade', function () {
    $this->seed();
    $section = activeTermSection('CS 232');

    $this->actingAs(teacherUser(), 'sanctum')
        ->postJson("/api/class-sections/{$section->id}/grades/submit", ['period' => 'final'])
        ->assertStatus(422);

    $this->assertDatabaseMissing('grade_submissions', [
        'class_section_id' => $section->id,
        'period' => 'final',
    ]);
});

it('does not allow re-submitting a period that is already pending', function () {
    $this->seed();
    $section = activeTermSection('CS 232'); // prelim is pending from the seeder

    $this->actingAs(teacherUser(), 'sanctum')
        ->postJson("/api/class-sections/{$section->id}/grades/submit", ['period' => 'prelim'])
        ->assertStatus(422);
});

it('forbids a non-teacher from submitting grades', function () {
    $this->seed();
    $section = activeTermSection('CS 232');

    $this->actingAs(registrarUser(), 'sanctum')
        ->postJson("/api/class-sections/{$section->id}/grades/submit", ['period' => 'semi_final'])
        ->assertForbidden();
});

it('only the registrar can review a submission', function () {
    $this->seed();
    $submission = GradeSubmission::where('status', 'pending')->firstOrFail();

    $this->actingAs(teacherUser(), 'sanctum')
        ->postJson("/api/grade-submissions/{$submission->id}/review", ['action' => 'released'])
        ->assertForbidden();
});

it('releases one period at a time to the student', function () {
    $this->seed();
    $student = User::where('email', 'jefferson.balde@westprime.edu')->firstOrFail();
    $section = activeTermSection('CS 232');
    $prelim = GradeSubmission::where('class_section_id', $section->id)->where('period', 'prelim')->firstOrFail();

    $admission = $student->studentProfile->admissions()
        ->whereHas('schoolTerm', fn ($q) => $q->where('is_active', true))
        ->firstOrFail();

    $before = $this->actingAs($student, 'sanctum')->getJson("/api/admissions/{$admission->id}")->assertOk();
    $cs232Before = collect($before->json('enrollment_subjects'))->firstWhere('class_section.subject.code', 'CS 232');
    expect($cs232Before['grade']['prelim'])->toBeNull();

    $this->actingAs(registrarUser(), 'sanctum')
        ->postJson("/api/grade-submissions/{$prelim->id}/review", ['action' => 'released'])
        ->assertOk();

    $after = $this->actingAs($student, 'sanctum')->getJson("/api/admissions/{$admission->id}")->assertOk();
    $cs232After = collect($after->json('enrollment_subjects'))->firstWhere('class_section.subject.code', 'CS 232');

    expect($cs232After['grade']['prelim'])->not->toBeNull()
        ->and($cs232After['grade']['midterm'])->toBeNull()
        ->and($cs232After['grade']['final_grade'])->toBeNull();
});

it('returns a submission so the teacher can edit and resubmit that period', function () {
    $this->seed();
    $section = activeTermSection('CS 232');
    $prelim = GradeSubmission::where('class_section_id', $section->id)->where('period', 'prelim')->firstOrFail();
    $enrollmentId = $section->enrollmentSubjects()->value('id');

    $this->actingAs(registrarUser(), 'sanctum')->postJson("/api/grade-submissions/{$prelim->id}/review", [
        'action' => 'returned',
        'review_remarks' => 'Please recheck the prelim entries.',
    ])->assertOk();

    $this->actingAs(teacherUser(), 'sanctum')->postJson("/api/class-sections/{$section->id}/grades", [
        'grades' => [['enrollment_subject_id' => $enrollmentId, 'prelim' => 1.50]],
    ])->assertOk();

    expect((float) Grade::where('enrollment_subject_id', $enrollmentId)->value('prelim'))->toBe(1.50);

    $this->actingAs(teacherUser(), 'sanctum')
        ->postJson("/api/class-sections/{$section->id}/grades/submit", ['period' => 'prelim'])
        ->assertCreated();

    expect(GradeSubmission::where('class_section_id', $section->id)->where('period', 'prelim')->value('status'))
        ->toBe('pending');
});

it('ignores draft edits to a period that is pending or released', function () {
    $this->seed();
    $section = activeTermSection('CS 232');
    $enrollmentId = $section->enrollmentSubjects()->value('id');
    $original = (float) Grade::where('enrollment_subject_id', $enrollmentId)->value('prelim');

    $res = $this->actingAs(teacherUser(), 'sanctum')->postJson("/api/class-sections/{$section->id}/grades", [
        'grades' => [['enrollment_subject_id' => $enrollmentId, 'prelim' => 5.00]],
    ])->assertOk();

    expect($res->json('locked_periods'))->toContain('prelim');
    expect((float) Grade::where('enrollment_subject_id', $enrollmentId)->value('prelim'))->toBe($original);
});

it('blocks a grade change request for a period that is not released', function () {
    $this->seed();
    $section = activeTermSection('CS 232'); // prelim pending, not released
    $gradeId = Grade::whereHas('enrollmentSubject', fn ($q) => $q->where('class_section_id', $section->id))->value('id');

    $this->actingAs(teacherUser(), 'sanctum')->postJson('/api/grade-change-requests', [
        'grade_id' => $gradeId,
        'period_field' => 'prelim',
        'new_value' => 1.25,
        'reason' => 'correction needed',
    ])->assertStatus(422);
});

it('allows a grade change request for a released period', function () {
    $this->seed();
    $section = activeTermSection('CS 116'); // fully released
    $gradeId = Grade::whereHas('enrollmentSubject', fn ($q) => $q->where('class_section_id', $section->id))->value('id');

    $this->actingAs(teacherUser(), 'sanctum')->postJson('/api/grade-change-requests', [
        'grade_id' => $gradeId,
        'period_field' => 'final',
        'new_value' => 1.25,
        'reason' => 'encoding correction',
    ])->assertCreated();
});

it('lets admin and stakeholder list grade change requests filtered by status and term', function () {
    $this->seed();
    $section = activeTermSection('CS 116'); // fully released
    $gradeId = Grade::whereHas('enrollmentSubject', fn ($q) => $q->where('class_section_id', $section->id))->value('id');

    $this->actingAs(teacherUser(), 'sanctum')->postJson('/api/grade-change-requests', [
        'grade_id' => $gradeId,
        'period_field' => 'final',
        'new_value' => 1.25,
        'reason' => 'encoding correction',
    ])->assertCreated();

    foreach (['admin@westprime.edu', 'stakeholder@westprime.edu'] as $email) {
        $user = User::where('email', $email)->firstOrFail();

        $res = $this->actingAs($user, 'sanctum')
            ->getJson("/api/grade-change-requests?status=pending&school_term_id={$section->school_term_id}")
            ->assertOk();
        expect($res->json('data'))->not->toBeEmpty();

        $noneRes = $this->actingAs($user, 'sanctum')
            ->getJson('/api/grade-change-requests?status=approved&school_term_id=999999')
            ->assertOk();
        expect($noneRes->json('data'))->toBeEmpty();
    }
});

it('hides grade change requests and the teacher directory from students', function () {
    $this->seed();
    $student = User::where('email', 'jefferson.balde@westprime.edu')->firstOrFail();

    $this->actingAs($student, 'sanctum')->getJson('/api/grade-change-requests')->assertForbidden();
    $this->actingAs($student, 'sanctum')->getJson('/api/teachers')->assertForbidden();
});

it('shows the pending submission queue to the registrar', function () {
    $this->seed();

    $res = $this->actingAs(registrarUser(), 'sanctum')->getJson('/api/grade-submissions')->assertOk();

    expect($res->json('summary.pending'))->toBeGreaterThan(0)
        ->and($res->json('data'))->not->toBeEmpty();
});

it('paginates the registrar submission queue and filters it by term', function () {
    $this->seed();
    $section = activeTermSection('CS 232');
    $termId = $section->school_term_id;

    $res = $this->actingAs(registrarUser(), 'sanctum')
        ->getJson("/api/grade-submissions?status=all&school_term_id={$termId}&page=1&per_page=10")
        ->assertOk();

    expect($res->json('current_page'))->toBe(1)
        ->and($res->json('per_page'))->toBe(10)
        ->and($res->json('data'))->not->toBeEmpty();

    foreach ($res->json('data') as $row) {
        expect($row['class_section']['school_term_id'])->toBe($termId);
    }

    $otherTermRes = $this->actingAs(registrarUser(), 'sanctum')
        ->getJson('/api/grade-submissions?status=all&school_term_id=999999')
        ->assertOk();

    expect($otherTermRes->json('data'))->toBeEmpty();
});

it('lets admin and stakeholder filter grade submissions by teacher', function () {
    $this->seed();
    $section = activeTermSection('CS 232');

    foreach (['admin@westprime.edu', 'stakeholder@westprime.edu'] as $email) {
        $user = User::where('email', $email)->firstOrFail();

        $res = $this->actingAs($user, 'sanctum')
            ->getJson("/api/grade-submissions?status=all&teacher_id={$section->teacher_id}")
            ->assertOk();

        expect($res->json('data'))->not->toBeEmpty();
        foreach ($res->json('data') as $row) {
            expect($row['class_section']['teacher_id'])->toBe($section->teacher_id);
        }

        $noneRes = $this->actingAs($user, 'sanctum')
            ->getJson('/api/grade-submissions?status=all&teacher_id=999999')
            ->assertOk();
        expect($noneRes->json('data'))->toBeEmpty();
    }
});

it('reports a returned count in the grade submissions summary', function () {
    $this->seed();
    $section = activeTermSection('CS 232');
    $prelim = GradeSubmission::where('class_section_id', $section->id)->where('period', 'prelim')->firstOrFail();

    $this->actingAs(registrarUser(), 'sanctum')->postJson("/api/grade-submissions/{$prelim->id}/review", [
        'action' => 'returned',
        'review_remarks' => 'Please recheck.',
    ])->assertOk();

    $res = $this->actingAs(teacherUser(), 'sanctum')
        ->getJson('/api/grade-submissions?status=all')
        ->assertOk();

    expect($res->json('summary.returned'))->toBeGreaterThan(0);
});

it('records NG as a period grade and fails the final grade with NG', function () {
    $this->seed();
    $section = activeTermSection('CS 232');
    $enrollmentId = $section->enrollmentSubjects()->value('id');

    $this->actingAs(teacherUser(), 'sanctum')->postJson("/api/class-sections/{$section->id}/grades", [
        'grades' => [['enrollment_subject_id' => $enrollmentId, 'semi_final' => 'ng']],
    ])->assertOk();

    $grade = Grade::where('enrollment_subject_id', $enrollmentId)->firstOrFail();

    expect($grade->semi_final)->toBeNull()
        ->and($grade->semi_final_ng)->toBeTrue()
        ->and($grade->final_grade)->toBeNull()
        ->and($grade->final_grade_ng)->toBeTrue()
        ->and($grade->remarks)->toBe('FAILED');
});

it('clears NG when a numeric grade replaces it', function () {
    $this->seed();
    $section = activeTermSection('CS 232');
    $enrollmentId = $section->enrollmentSubjects()->value('id');

    $this->actingAs(teacherUser(), 'sanctum')->postJson("/api/class-sections/{$section->id}/grades", [
        'grades' => [['enrollment_subject_id' => $enrollmentId, 'semi_final' => 'NG']],
    ])->assertOk();

    $this->actingAs(teacherUser(), 'sanctum')->postJson("/api/class-sections/{$section->id}/grades", [
        'grades' => [['enrollment_subject_id' => $enrollmentId, 'semi_final' => 1.75]],
    ])->assertOk();

    $grade = Grade::where('enrollment_subject_id', $enrollmentId)->firstOrFail();

    expect((float) $grade->semi_final)->toBe(1.75)
        ->and($grade->semi_final_ng)->toBeFalse()
        ->and($grade->final_grade_ng)->toBeFalse();
});

it('rejects a grade that is neither a number nor NG', function () {
    $this->seed();
    $section = activeTermSection('CS 232');
    $enrollmentId = $section->enrollmentSubjects()->value('id');

    $this->actingAs(teacherUser(), 'sanctum')->postJson("/api/class-sections/{$section->id}/grades", [
        'grades' => [['enrollment_subject_id' => $enrollmentId, 'semi_final' => 'abc']],
    ])->assertUnprocessable()->assertJsonValidationErrors(['grades.0.semi_final']);
});

it('counts NG as a recorded grade when submitting a period', function () {
    $this->seed();
    $section = activeTermSection('CS 232');
    $enrollmentIds = $section->enrollmentSubjects()->pluck('id');

    $this->actingAs(teacherUser(), 'sanctum')->postJson("/api/class-sections/{$section->id}/grades", [
        'grades' => $enrollmentIds->map(fn ($id) => [
            'enrollment_subject_id' => $id,
            'semi_final' => 'NG',
        ])->all(),
    ])->assertOk();

    $this->actingAs(teacherUser(), 'sanctum')
        ->postJson("/api/class-sections/{$section->id}/grades/submit", ['period' => 'semi_final'])
        ->assertCreated();
});

it('hides an unreleased NG period from the student', function () {
    $this->seed();
    $student = User::where('email', 'jefferson.balde@westprime.edu')->firstOrFail();
    $section = activeTermSection('CS 232');

    $admission = $student->studentProfile->admissions()
        ->whereHas('schoolTerm', fn ($q) => $q->where('is_active', true))
        ->firstOrFail();

    $grade = Grade::whereHas('enrollmentSubject', fn ($q) => $q
        ->where('class_section_id', $section->id)
        ->where('admission_id', $admission->id))->firstOrFail();
    $grade->setPeriodNoGrade('semi_final');
    $grade->recalculate(app(GradeCalculator::class), $section->subject->academic_level);
    $grade->save();

    $res = $this->actingAs($student, 'sanctum')->getJson("/api/admissions/{$admission->id}")->assertOk();
    $row = collect($res->json('enrollment_subjects'))->firstWhere('class_section.subject.code', 'CS 232');

    expect($row['grade']['semi_final_ng'])->toBeFalse()
        ->and($row['grade']['final_grade_ng'])->toBeFalse()
        ->and($row['grade']['remarks'])->toBeNull();
});

it('lets a teacher request a change from NG to a number and applies it on approval', function () {
    $this->seed();
    $section = activeTermSection('CS 116'); // fully released
    $grade = Grade::whereHas('enrollmentSubject', fn ($q) => $q->where('class_section_id', $section->id))->firstOrFail();
    $grade->setPeriodNoGrade('final');
    $grade->recalculate(app(GradeCalculator::class), $section->subject->academic_level);
    $grade->save();

    expect($grade->fresh()->remarks)->toBe('FAILED');

    $created = $this->actingAs(teacherUser(), 'sanctum')->postJson('/api/grade-change-requests', [
        'grade_id' => $grade->id,
        'period_field' => 'final',
        'new_value' => 1.75,
        'reason' => 'Student completed the make-up exam',
    ])->assertCreated()
        ->assertJsonPath('old_is_ng', true)
        ->assertJsonPath('new_is_ng', false);

    $this->actingAs(registrarUser(), 'sanctum')
        ->postJson("/api/grade-change-requests/{$created->json('id')}/review", ['status' => 'approved'])
        ->assertOk();

    $fresh = $grade->fresh();
    expect((float) $fresh->final)->toBe(1.75)
        ->and($fresh->final_ng)->toBeFalse()
        ->and($fresh->final_grade_ng)->toBeFalse()
        ->and($fresh->final_grade)->not->toBeNull();
});

it('lets a teacher request NG for a released numeric grade', function () {
    $this->seed();
    $section = activeTermSection('CS 116');
    $gradeId = Grade::whereHas('enrollmentSubject', fn ($q) => $q->where('class_section_id', $section->id))->value('id');

    $created = $this->actingAs(teacherUser(), 'sanctum')->postJson('/api/grade-change-requests', [
        'grade_id' => $gradeId,
        'period_field' => 'final',
        'new_value' => 'NG',
        'reason' => 'Student did not take the final exam',
    ])->assertCreated()->assertJsonPath('new_is_ng', true);

    $this->actingAs(registrarUser(), 'sanctum')
        ->postJson("/api/grade-change-requests/{$created->json('id')}/review", ['status' => 'approved'])
        ->assertOk();

    $fresh = Grade::findOrFail($gradeId);
    expect($fresh->final)->toBeNull()
        ->and($fresh->final_ng)->toBeTrue()
        ->and($fresh->final_grade_ng)->toBeTrue()
        ->and($fresh->remarks)->toBe('FAILED');
});

it('counts NG grades as failed in the academic performance report', function () {
    $this->seed();
    $section = activeTermSection('CS 116');
    $grade = Grade::whereHas('enrollmentSubject', fn ($q) => $q->where('class_section_id', $section->id))->firstOrFail();
    $grade->setPeriodNoGrade('final');
    $grade->recalculate(app(GradeCalculator::class), $section->subject->academic_level);
    $grade->save();

    $admin = User::where('email', 'admin@westprime.edu')->firstOrFail();
    $res = $this->actingAs($admin, 'sanctum')
        ->getJson("/api/reports/performance?school_term_id={$section->school_term_id}")
        ->assertOk();

    expect(collect($res->json('at_risk'))->contains(fn ($row) => $row['final_grade_ng'] === true))->toBeTrue();
});

it('paginates the teacher grade submissions list and filters by term', function () {
    $this->seed();
    $teacher = teacherUser();
    $section = activeTermSection('CS 232');
    $termId = $section->school_term_id;

    $res = $this->actingAs($teacher, 'sanctum')
        ->getJson("/api/grade-submissions?status=all&school_term_id={$termId}&page=1&per_page=10")
        ->assertOk();

    expect($res->json('current_page'))->toBe(1)
        ->and($res->json('per_page'))->toBe(10);

    foreach ($res->json('data') as $row) {
        expect($row['class_section']['school_term_id'] ?? $termId)->toBe($termId);
    }
});
