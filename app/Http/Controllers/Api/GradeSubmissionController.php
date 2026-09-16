<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Grade;
use App\Models\GradeSubmission;
use App\Support\StaffNotifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class GradeSubmissionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $role = $user->role->value;

        $query = GradeSubmission::query()->with([
            'classSection.subject',
            'classSection.schoolTerm',
            'classSection.teacher',
            'submitter',
            'reviewer',
        ]);

        if ($role === 'teacher') {
            $query->whereHas('classSection', fn ($q) => $q->where('teacher_id', $user->id));
        }

        if ($termId = $request->query('school_term_id')) {
            $query->whereHas('classSection', fn ($q) => $q->where('school_term_id', $termId));
        }

        if ($teacherId = $request->query('teacher_id')) {
            $query->whereHas('classSection', fn ($q) => $q->where('teacher_id', $teacherId));
        }

        if ($search = trim((string) $request->query('search', ''))) {
            $like = '%'.addcslashes($search, '%_\\').'%';
            $query->whereHas('classSection', function ($q) use ($like) {
                $q->where('section', 'like', $like)
                    ->orWhereHas('subject', fn ($sq) => $sq->where('code', 'like', $like)->orWhere('title', 'like', $like));
            });
        }

        $pendingCount = (clone $query)->where('status', 'pending')->count();
        $returnedCount = (clone $query)->where('status', 'returned')->count();

        $status = (string) $request->query('status', 'pending');
        if (in_array($status, GradeSubmission::STATUSES, true)) {
            $query->where('status', $status);
        }

        $perPage = min(100, max(10, (int) $request->query('per_page', 20)));
        $paginator = $query->orderByDesc('id')->paginate($perPage)->appends($request->query());

        $sectionIds = collect($paginator->items())->pluck('class_section_id')->unique()->all();
        $studentCounts = DB::table('enrollment_subjects')
            ->whereIn('class_section_id', $sectionIds)
            ->select('class_section_id', DB::raw('count(*) as total'))
            ->groupBy('class_section_id')
            ->pluck('total', 'class_section_id');

        $data = $paginator->toArray();
        $data['data'] = collect($paginator->items())->map(function (GradeSubmission $submission) use ($studentCounts) {
            $arr = $submission->toArray();
            $arr['period_label'] = GradeSubmission::periodLabel($submission->period);
            $arr['students_count'] = (int) ($studentCounts[$submission->class_section_id] ?? 0);

            return $arr;
        })->all();

        $data['summary'] = [
            'pending' => $pendingCount,
            'returned' => $returnedCount,
        ];

        return response()->json($data);
    }

    public function show(Request $request, GradeSubmission $gradeSubmission): JsonResponse
    {
        $user = $request->user();
        if ($user->role->value === 'teacher'
            && $gradeSubmission->classSection?->teacher_id !== $user->id) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $gradeSubmission->load([
            'classSection.subject',
            'classSection.schoolTerm',
            'classSection.teacher',
            'submitter',
            'reviewer',
            'classSection.enrollmentSubjects.admission.studentProfile',
            'classSection.enrollmentSubjects.grade',
        ]);

        $period = $gradeSubmission->period;
        $rows = $gradeSubmission->classSection->enrollmentSubjects
            ->sortBy(fn ($es) => strtolower(trim(
                ($es->admission?->studentProfile?->last_name ?? '').', '.($es->admission?->studentProfile?->first_name ?? '')
            )))
            ->map(function ($es) use ($period) {
                $sp = $es->admission?->studentProfile;

                return [
                    'student_no' => $sp?->student_no,
                    'name' => $sp ? trim("{$sp->last_name}, {$sp->first_name}") : '—',
                    'value' => $es->grade?->{$period},
                ];
            })->values();

        return response()->json([
            'submission' => array_merge($gradeSubmission->toArray(), [
                'period_label' => GradeSubmission::periodLabel($period),
            ]),
            'rows' => $rows,
        ]);
    }

    public function review(Request $request, GradeSubmission $gradeSubmission): JsonResponse
    {
        if ($gradeSubmission->status !== 'pending') {
            return response()->json(['message' => 'This submission was already reviewed.'], 422);
        }

        $data = $request->validate([
            'action' => ['required', Rule::in(['released', 'returned'])],
            'review_remarks' => ['nullable', 'string', 'max:1000'],
        ]);

        if ($data['action'] === 'returned' && empty(trim((string) ($data['review_remarks'] ?? '')))) {
            return response()->json([
                'message' => 'Add a note so the teacher knows what to correct.',
                'errors' => ['review_remarks' => ['A note is required when returning grades to the teacher.']],
            ], 422);
        }

        DB::transaction(function () use ($data, $gradeSubmission, $request) {
            $gradeSubmission->update([
                'status' => $data['action'],
                'review_remarks' => $data['review_remarks'] ?? null,
                'reviewed_by' => $request->user()->id,
                'reviewed_at' => now(),
            ]);

            if ($data['action'] === 'released') {
                $this->refreshLockState($gradeSubmission->class_section_id);
            }

            AuditLog::create([
                'user_id' => $request->user()->id,
                'action' => $data['action'] === 'released'
                    ? 'grade_submission.released'
                    : 'grade_submission.returned',
                'auditable_type' => GradeSubmission::class,
                'auditable_id' => $gradeSubmission->id,
                'new_values' => [
                    'period' => $gradeSubmission->period,
                    'class_section_id' => $gradeSubmission->class_section_id,
                ],
                'ip_address' => $request->ip(),
            ]);
        });

        $fresh = $gradeSubmission->fresh()->load(['classSection.subject', 'submitter', 'reviewer']);

        StaffNotifier::submissionReviewed($fresh);

        return response()->json($fresh);
    }

    /**
     * A grade row is "locked" once every period for its class section is released.
     */
    private function refreshLockState(int $classSectionId): void
    {
        $releasedPeriods = GradeSubmission::query()
            ->where('class_section_id', $classSectionId)
            ->where('status', 'released')
            ->pluck('period')
            ->all();

        $allReleased = count(array_intersect(GradeSubmission::PERIODS, $releasedPeriods)) === count(GradeSubmission::PERIODS);

        Grade::query()
            ->whereHas('enrollmentSubject', fn ($q) => $q->where('class_section_id', $classSectionId))
            ->update(['is_locked' => $allReleased]);
    }
}
