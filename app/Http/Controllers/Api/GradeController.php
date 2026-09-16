<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\ClassSection;
use App\Models\Grade;
use App\Models\GradeSubmission;
use App\Services\GradeCalculator;
use App\Support\StaffNotifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class GradeController extends Controller
{
    public function __construct(private GradeCalculator $calculator) {}

    /**
     * Save (draft) period grades for a class. Periods that are already submitted
     * (pending) or released are locked and skipped.
     */
    public function updateForClass(Request $request, ClassSection $classSection): JsonResponse
    {
        $user = $request->user();

        if ($user->role->value !== 'teacher' || $classSection->teacher_id !== $user->id) {
            return response()->json(['message' => 'Only the assigned teacher can encode grades.'], 403);
        }

        $classSection->load('subject');

        $data = $request->validate([
            'grades' => ['required', 'array'],
            'grades.*.enrollment_subject_id' => ['required', 'exists:enrollment_subjects,id'],
            'grades.*.prelim' => ['nullable', 'numeric', 'regex:/^\d+(\.\d{1,2})?$/'],
            'grades.*.midterm' => ['nullable', 'numeric', 'regex:/^\d+(\.\d{1,2})?$/'],
            'grades.*.semi_final' => ['nullable', 'numeric', 'regex:/^\d+(\.\d{1,2})?$/'],
            'grades.*.final' => ['nullable', 'numeric', 'regex:/^\d+(\.\d{1,2})?$/'],
        ], [
            'grades.*.prelim.numeric' => 'Prelim must be a number.',
            'grades.*.midterm.numeric' => 'Midterm must be a number.',
            'grades.*.semi_final.numeric' => 'Semi-Final must be a number.',
            'grades.*.final.numeric' => 'Final must be a number.',
            'grades.*.prelim.regex' => 'Prelim must be a valid grade number.',
            'grades.*.midterm.regex' => 'Midterm must be a valid grade number.',
            'grades.*.semi_final.regex' => 'Semi-Final must be a valid grade number.',
            'grades.*.final.regex' => 'Final must be a valid grade number.',
        ]);

        $level = $classSection->subject->academic_level;

        $lockedPeriods = GradeSubmission::query()
            ->where('class_section_id', $classSection->id)
            ->whereIn('status', ['pending', 'released'])
            ->pluck('period')
            ->all();

        $result = DB::transaction(function () use ($data, $user, $level, $classSection, $request, $lockedPeriods) {
            $updated = [];

            foreach ($data['grades'] as $row) {
                $enrollment = $classSection->enrollmentSubjects()
                    ->where('id', $row['enrollment_subject_id'])
                    ->first();

                if (! $enrollment) {
                    continue;
                }

                $grade = Grade::firstOrCreate(['enrollment_subject_id' => $enrollment->id]);
                $touched = false;

                foreach (GradeSubmission::PERIODS as $field) {
                    if (in_array($field, $lockedPeriods, true)) {
                        continue;
                    }

                    if (! array_key_exists($field, $row)) {
                        continue;
                    }

                    if ($row[$field] === null || $row[$field] === '') {
                        if ($grade->{$field} !== null) {
                            $grade->{$field} = null;
                            $touched = true;
                        }

                        continue;
                    }

                    $value = (float) $row[$field];
                    if (! $this->calculator->validatePeriodGrade($value, $level)) {
                        abort(422, 'Invalid '.GradeSubmission::periodLabel($field).' grade for this academic level.');
                    }

                    $grade->{$field} = $value;
                    $touched = true;
                }

                if (! $touched) {
                    continue;
                }

                $grade->encoded_by = $user->id;
                $grade->recalculate($this->calculator, $level);
                $grade->save();

                AuditLog::create([
                    'user_id' => $user->id,
                    'action' => 'grade.updated',
                    'auditable_type' => Grade::class,
                    'auditable_id' => $grade->id,
                    'new_values' => $grade->only(['prelim', 'midterm', 'semi_final', 'final', 'final_grade', 'remarks']),
                    'ip_address' => $request->ip(),
                ]);

                $updated[] = $grade;
            }

            return $updated;
        });

        return response()->json([
            'grades' => $result,
            'locked_periods' => $lockedPeriods,
        ]);
    }

    /**
     * Submit one period for registrar review. Requires every enrolled student to
     * have a value for that period.
     */
    public function submitForClass(Request $request, ClassSection $classSection): JsonResponse
    {
        $user = $request->user();

        if ($user->role->value !== 'teacher' || $classSection->teacher_id !== $user->id) {
            return response()->json(['message' => 'Only the assigned teacher can submit grades.'], 403);
        }

        $data = $request->validate([
            'period' => ['required', Rule::in(GradeSubmission::PERIODS)],
        ]);

        $period = $data['period'];
        $periodLabel = GradeSubmission::periodLabel($period);

        $existing = GradeSubmission::query()
            ->where('class_section_id', $classSection->id)
            ->where('period', $period)
            ->first();

        if ($existing && in_array($existing->status, ['pending', 'released'], true)) {
            $message = $existing->status === 'pending'
                ? "{$periodLabel} grades are already submitted and waiting for registrar review."
                : "{$periodLabel} grades are already released. Use a Grade Change Request to correct a released grade.";

            return response()->json(['message' => $message], 422);
        }

        $classSection->load([
            'enrollmentSubjects.grade',
            'enrollmentSubjects.admission.studentProfile',
        ]);

        if ($classSection->enrollmentSubjects->isEmpty()) {
            return response()->json(['message' => 'There are no enrolled students in this class.'], 422);
        }

        $missing = [];
        foreach ($classSection->enrollmentSubjects as $enrollment) {
            $value = $enrollment->grade?->{$period};
            if ($value === null || $value === '') {
                $sp = $enrollment->admission?->studentProfile;
                $missing[] = $sp ? trim("{$sp->last_name}, {$sp->first_name}") : "Enrollment #{$enrollment->id}";
            }
        }

        if ($missing !== []) {
            $names = implode(', ', array_slice($missing, 0, 10)).(count($missing) > 10 ? ', …' : '');

            return response()->json([
                'message' => "Encode {$periodLabel} grades for all students before submitting.",
                'errors' => ['period' => ["Missing {$periodLabel} grade for: {$names}"]],
            ], 422);
        }

        $submission = DB::transaction(function () use ($classSection, $period, $user, $request) {
            $submission = GradeSubmission::updateOrCreate(
                ['class_section_id' => $classSection->id, 'period' => $period],
                [
                    'status' => 'pending',
                    'submitted_by' => $user->id,
                    'submitted_at' => now(),
                    'reviewed_by' => null,
                    'reviewed_at' => null,
                    'review_remarks' => null,
                ]
            );

            AuditLog::create([
                'user_id' => $user->id,
                'action' => 'grade_submission.submitted',
                'auditable_type' => GradeSubmission::class,
                'auditable_id' => $submission->id,
                'new_values' => ['period' => $period, 'class_section_id' => $classSection->id],
                'ip_address' => $request->ip(),
            ]);

            return $submission;
        });

        StaffNotifier::gradeSubmitted($submission);

        return response()->json($submission->load('submitter'), 201);
    }

    public function show(Grade $grade): JsonResponse
    {
        return response()->json($grade->load([
            'enrollmentSubject.admission.studentProfile',
            'enrollmentSubject.classSection.subject',
            'changeRequests',
        ]));
    }
}
