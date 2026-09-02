<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Grade;
use App\Models\GradeChangeRequest;
use App\Services\GradeCalculator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class GradeChangeRequestController extends Controller
{
    public function __construct(private GradeCalculator $calculator) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $query = GradeChangeRequest::with([
            'grade.enrollmentSubject.admission.studentProfile',
            'grade.enrollmentSubject.classSection.subject',
            'requester',
            'reviewer',
        ]);

        if ($user->role->value === 'teacher') {
            $query->where('requested_by', $user->id);
        } elseif ($user->role->value === 'registrar') {
            if ($status = $request->query('status', 'pending')) {
                if ($status !== 'all') {
                    $query->where('status', $status);
                }
            }
        }

        return response()->json($query->orderByDesc('id')->paginate(20));
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->role->value !== 'teacher') {
            return response()->json(['message' => 'Only teachers can request grade changes.'], 403);
        }

        $data = $request->validate([
            'grade_id' => ['required', 'exists:grades,id'],
            'period_field' => ['required', Rule::in(['prelim', 'midterm', 'semi_final', 'final'])],
            'new_value' => ['required', 'numeric'],
            'reason' => ['required', 'string', 'min:5'],
        ]);

        $grade = Grade::with('enrollmentSubject.classSection.subject')->findOrFail($data['grade_id']);

        if ($grade->enrollmentSubject->classSection->teacher_id !== $user->id) {
            return response()->json(['message' => 'Not your class.'], 403);
        }

        $level = $grade->enrollmentSubject->classSection->subject->academic_level;
        if (! $this->calculator->validatePeriodGrade((float) $data['new_value'], $level)) {
            return response()->json(['message' => 'Invalid grade value for academic level.'], 422);
        }

        $change = GradeChangeRequest::create([
            'grade_id' => $grade->id,
            'period_field' => $data['period_field'],
            'old_value' => $grade->{$data['period_field']},
            'new_value' => $data['new_value'],
            'reason' => $data['reason'],
            'status' => 'pending',
            'requested_by' => $user->id,
        ]);

        return response()->json($change->load(['grade', 'requester']), 201);
    }

    public function review(Request $request, GradeChangeRequest $gradeChangeRequest): JsonResponse
    {
        if ($request->user()->role->value !== 'registrar') {
            return response()->json(['message' => 'Only registrar can approve or reject.'], 403);
        }

        if ($gradeChangeRequest->status !== 'pending') {
            return response()->json(['message' => 'Request already reviewed.'], 422);
        }

        $data = $request->validate([
            'status' => ['required', Rule::in(['approved', 'rejected'])],
            'review_remarks' => ['nullable', 'string'],
        ]);

        $result = DB::transaction(function () use ($data, $gradeChangeRequest, $request) {
            $gradeChangeRequest->update([
                'status' => $data['status'],
                'review_remarks' => $data['review_remarks'] ?? null,
                'reviewed_by' => $request->user()->id,
                'reviewed_at' => now(),
            ]);

            if ($data['status'] === 'approved') {
                $grade = $gradeChangeRequest->grade()->with('enrollmentSubject.classSection.subject')->first();
                $field = $gradeChangeRequest->period_field;
                $old = $grade->only(['prelim', 'midterm', 'semi_final', 'final', 'final_grade', 'remarks']);
                $grade->{$field} = $gradeChangeRequest->new_value;
                $grade->is_locked = true;
                $grade->recalculate($this->calculator, $grade->enrollmentSubject->classSection->subject->academic_level);
                $grade->save();

                AuditLog::create([
                    'user_id' => $request->user()->id,
                    'action' => 'grade_change.approved',
                    'auditable_type' => Grade::class,
                    'auditable_id' => $grade->id,
                    'old_values' => $old,
                    'new_values' => $grade->only(['prelim', 'midterm', 'semi_final', 'final', 'final_grade', 'remarks']),
                    'ip_address' => $request->ip(),
                ]);
            } else {
                AuditLog::create([
                    'user_id' => $request->user()->id,
                    'action' => 'grade_change.rejected',
                    'auditable_type' => GradeChangeRequest::class,
                    'auditable_id' => $gradeChangeRequest->id,
                    'ip_address' => $request->ip(),
                ]);
            }

            return $gradeChangeRequest->fresh()->load(['grade', 'requester', 'reviewer']);
        });

        return response()->json($result);
    }
}
