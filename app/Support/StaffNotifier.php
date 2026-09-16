<?php

namespace App\Support;

use App\Models\GradeChangeRequest;
use App\Models\GradeSubmission;
use App\Models\User;
use App\Notifications\SystemNotification;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Notification;

/**
 * Builds and dispatches the teacher <-> registrar workflow notifications.
 */
class StaffNotifier
{
    /**
     * @return Collection<int, User>
     */
    private static function activeRegistrars(): Collection
    {
        return User::query()
            ->where('role', 'registrar')
            ->where('is_active', true)
            ->get();
    }

    public static function gradeSubmitted(GradeSubmission $submission): void
    {
        $submission->loadMissing(['classSection.subject', 'classSection.schoolTerm', 'submitter']);

        $section = $submission->classSection;
        $periodLabel = GradeSubmission::periodLabel($submission->period);
        $subject = $section?->subject?->code ?? 'a subject';
        $sectionName = $section?->section ? " ({$section->section})" : '';
        $term = $section?->schoolTerm?->name;
        $teacher = $submission->submitter?->name ?? 'A teacher';

        Notification::send(self::activeRegistrars(), new SystemNotification(
            'grade_submission.pending',
            'Grades submitted for review',
            "{$teacher} submitted {$periodLabel} grades for {$subject}{$sectionName}"
                .($term ? " — {$term}" : '').'. Review and release or return them.',
            '/grade-submissions-review',
            ['grade_submission_id' => $submission->id, 'period' => $submission->period],
        ));
    }

    public static function submissionReviewed(GradeSubmission $submission): void
    {
        $submission->loadMissing(['classSection.subject', 'submitter']);

        $teacher = $submission->submitter;
        if (! $teacher) {
            return;
        }

        $periodLabel = GradeSubmission::periodLabel($submission->period);
        $subject = $submission->classSection?->subject?->code ?? 'a subject';
        $released = $submission->status === 'released';
        $remarks = trim((string) $submission->review_remarks);

        $teacher->notify(new SystemNotification(
            $released ? 'grade_submission.released' : 'grade_submission.returned',
            $released ? 'Grades released' : 'Grades returned for correction',
            ($released
                ? "The registrar released your {$periodLabel} grades for {$subject}. Students can now see them."
                : "The registrar returned your {$periodLabel} grades for {$subject}. Correct them and submit again.")
                .($remarks !== '' ? " Remarks: \"{$remarks}\"" : ''),
            $released ? '/grade-submissions' : '/classes',
            ['grade_submission_id' => $submission->id, 'period' => $submission->period, 'status' => $submission->status],
        ));
    }

    public static function gradeChangeRequested(GradeChangeRequest $change): void
    {
        $change->loadMissing([
            'grade.enrollmentSubject.classSection.subject',
            'grade.enrollmentSubject.admission.studentProfile',
            'requester',
        ]);

        $profile = $change->grade?->enrollmentSubject?->admission?->studentProfile;
        $student = $profile ? trim("{$profile->last_name}, {$profile->first_name}") : 'a student';
        $subject = $change->grade?->enrollmentSubject?->classSection?->subject?->code ?? 'a subject';
        $periodLabel = GradeSubmission::periodLabel($change->period_field);
        $teacher = $change->requester?->name ?? 'A teacher';

        Notification::send(self::activeRegistrars(), new SystemNotification(
            'grade_change.pending',
            'Grade change request',
            "{$teacher} requested a {$periodLabel} grade change for {$student} in {$subject} "
                ."({$change->old_value} to {$change->new_value}). Approve or reject it.",
            '/grade-approvals',
            ['grade_change_request_id' => $change->id],
        ));
    }

    public static function gradeChangeReviewed(GradeChangeRequest $change): void
    {
        $change->loadMissing(['grade.enrollmentSubject.classSection.subject', 'requester']);

        $teacher = $change->requester;
        if (! $teacher) {
            return;
        }

        $subject = $change->grade?->enrollmentSubject?->classSection?->subject?->code ?? 'a subject';
        $periodLabel = GradeSubmission::periodLabel($change->period_field);
        $approved = $change->status === 'approved';
        $remarks = trim((string) $change->review_remarks);

        $teacher->notify(new SystemNotification(
            $approved ? 'grade_change.approved' : 'grade_change.rejected',
            $approved ? 'Grade change approved' : 'Grade change rejected',
            ($approved
                ? "Your {$periodLabel} grade change for {$subject} was approved and applied."
                : "Your {$periodLabel} grade change for {$subject} was rejected.")
                .($remarks !== '' ? " Remarks: \"{$remarks}\"" : ''),
            '/grade-changes',
            ['grade_change_request_id' => $change->id, 'status' => $change->status],
        ));
    }
}
