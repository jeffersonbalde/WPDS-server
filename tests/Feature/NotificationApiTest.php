<?php

use App\Enums\UserRole;
use App\Models\Grade;
use App\Models\GradeSubmission;
use App\Models\User;
use App\Notifications\SystemNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// teacherUser(), registrarUser() and activeTermSection() come from GradeSubmissionFlowTest.

function studentUser(): User
{
    return User::where('email', 'jefferson.balde@westprime.edu')->firstOrFail();
}

function encodeAndSubmit(string $subjectCode, string $period, float $value = 1.75): GradeSubmission
{
    $section = activeTermSection($subjectCode);
    $enrollmentIds = $section->enrollmentSubjects()->pluck('id');

    test()->actingAs(teacherUser(), 'sanctum')->postJson("/api/class-sections/{$section->id}/grades", [
        'grades' => $enrollmentIds->map(fn ($id) => [
            'enrollment_subject_id' => $id,
            $period => $value,
        ])->all(),
    ])->assertOk();

    test()->actingAs(teacherUser(), 'sanctum')
        ->postJson("/api/class-sections/{$section->id}/grades/submit", ['period' => $period])
        ->assertCreated();

    return GradeSubmission::where('class_section_id', $section->id)->where('period', $period)->firstOrFail();
}

it('notifies the registrar when a teacher submits a grading period', function () {
    $this->seed();
    $registrar = registrarUser();
    $before = $registrar->unreadNotifications()->count();

    encodeAndSubmit('CS 232', 'semi_final');

    $registrar->refresh();
    expect($registrar->unreadNotifications()->count())->toBe($before + 1);

    $latest = $registrar->notifications()->latest()->first();
    expect($latest->data['type'])->toBe('grade_submission.pending')
        ->and($latest->data['action_url'])->toBe('/grade-submissions-review')
        ->and($latest->data['body'])->toContain('CS 232');
});

it('notifies the teacher when the registrar releases a submission', function () {
    $this->seed();
    $teacher = teacherUser();
    $submission = encodeAndSubmit('CS 232', 'semi_final');
    $before = $teacher->unreadNotifications()->count();

    $this->actingAs(registrarUser(), 'sanctum')
        ->postJson("/api/grade-submissions/{$submission->id}/review", ['action' => 'released'])
        ->assertOk();

    $teacher->refresh();
    expect($teacher->unreadNotifications()->count())->toBe($before + 1);

    $latest = $teacher->notifications()->latest()->first();
    expect($latest->data['type'])->toBe('grade_submission.released')
        ->and($latest->data['action_url'])->toBe('/grade-submissions');
});

it('includes the registrar remarks when a submission is returned', function () {
    $this->seed();
    $teacher = teacherUser();
    $submission = encodeAndSubmit('CS 232', 'semi_final');

    $this->actingAs(registrarUser(), 'sanctum')->postJson("/api/grade-submissions/{$submission->id}/review", [
        'action' => 'returned',
        'review_remarks' => 'Recheck row 4.',
    ])->assertOk();

    $latest = $teacher->fresh()->notifications()->latest()->first();
    expect($latest->data['type'])->toBe('grade_submission.returned')
        ->and($latest->data['body'])->toContain('Recheck row 4.');
});

it('notifies the registrar of a grade change request and the teacher of the decision', function () {
    $this->seed();
    $teacher = teacherUser();
    $registrar = registrarUser();

    $section = activeTermSection('CS 116'); // fully released in the seeder
    $gradeId = Grade::whereHas('enrollmentSubject', fn ($q) => $q->where('class_section_id', $section->id))->value('id');

    $regBefore = $registrar->unreadNotifications()->count();

    $created = $this->actingAs($teacher, 'sanctum')->postJson('/api/grade-change-requests', [
        'grade_id' => $gradeId,
        'period_field' => 'final',
        'new_value' => 1.25,
        'reason' => 'encoding correction',
    ])->assertCreated();

    $registrar->refresh();
    expect($registrar->unreadNotifications()->count())->toBe($regBefore + 1);
    expect($registrar->notifications()->latest()->first()->data['type'])->toBe('grade_change.pending');

    $changeId = $created->json('id');
    $teacherBefore = $teacher->fresh()->unreadNotifications()->count();

    $this->actingAs($registrar, 'sanctum')->postJson("/api/grade-change-requests/{$changeId}/review", [
        'status' => 'approved',
        'review_remarks' => 'OK, applied.',
    ])->assertOk();

    $teacher->refresh();
    expect($teacher->unreadNotifications()->count())->toBe($teacherBefore + 1);
    $latest = $teacher->notifications()->latest()->first();
    expect($latest->data['type'])->toBe('grade_change.approved')
        ->and($latest->data['body'])->toContain('OK, applied.');
});

it('lists only the current user\'s notifications with an unread count', function () {
    $this->seed();
    $teacher = teacherUser();
    $other = User::factory()->create(['role' => UserRole::Teacher, 'is_active' => true]);

    $teacher->notify(new SystemNotification('demo', 'For teacher', 'body'));
    $other->notify(new SystemNotification('demo', 'For other', 'body'));

    $res = $this->actingAs($teacher, 'sanctum')->getJson('/api/notifications')->assertOk();

    expect($res->json('data'))->toHaveCount(1)
        ->and($res->json('data.0.title'))->toBe('For teacher')
        ->and($res->json('unread_count'))->toBe(1);
});

it('filters to unread notifications only', function () {
    $this->seed();
    $teacher = teacherUser();
    $teacher->notify(new SystemNotification('demo', 'Unread one', 'body'));
    $teacher->notify(new SystemNotification('demo', 'Will be read', 'body'));
    $teacher->fresh()->notifications
        ->first(fn ($n) => ($n->data['title'] ?? null) === 'Will be read')
        ->markAsRead();

    $res = $this->actingAs($teacher, 'sanctum')->getJson('/api/notifications?filter=unread')->assertOk();

    expect($res->json('data'))->toHaveCount(1)
        ->and($res->json('data.0.title'))->toBe('Unread one');
});

it('marks a single notification as read', function () {
    $this->seed();
    $teacher = teacherUser();
    $teacher->notify(new SystemNotification('demo', 'Mark me', 'body'));
    $id = $teacher->notifications()->latest()->first()->id;

    $this->actingAs($teacher, 'sanctum')
        ->postJson("/api/notifications/{$id}/read")
        ->assertOk()
        ->assertJsonPath('unread_count', 0);

    expect($teacher->fresh()->unreadNotifications()->count())->toBe(0);
});

it('marks every notification as read', function () {
    $this->seed();
    $teacher = teacherUser();
    $teacher->notify(new SystemNotification('demo', 'A', 'body'));
    $teacher->notify(new SystemNotification('demo', 'B', 'body'));

    $this->actingAs($teacher, 'sanctum')
        ->postJson('/api/notifications/read-all')
        ->assertOk()
        ->assertJsonPath('unread_count', 0);

    expect($teacher->fresh()->unreadNotifications()->count())->toBe(0);
});

it('does not let a user mark another user\'s notification as read', function () {
    $this->seed();
    $teacher = teacherUser();
    $other = User::factory()->create(['role' => UserRole::Teacher, 'is_active' => true]);
    $other->notify(new SystemNotification('demo', 'Not yours', 'body'));
    $id = $other->notifications()->latest()->first()->id;

    $this->actingAs($teacher, 'sanctum')
        ->postJson("/api/notifications/{$id}/read")
        ->assertNotFound();
});
