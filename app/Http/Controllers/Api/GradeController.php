<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\ClassSection;
use App\Models\Grade;
use App\Services\GradeCalculator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class GradeController extends Controller
{
    public function __construct(private GradeCalculator $calculator) {}

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
            'grades.*.prelim' => ['nullable', 'numeric'],
            'grades.*.midterm' => ['nullable', 'numeric'],
            'grades.*.semi_final' => ['nullable', 'numeric'],
            'grades.*.final' => ['nullable', 'numeric'],
            'lock' => ['boolean'],
        ]);

        $level = $classSection->subject->academic_level;

        $updated = DB::transaction(function () use ($data, $user, $level, $classSection, $request) {
            $results = [];

            foreach ($data['grades'] as $row) {
                $enrollment = $classSection->enrollmentSubjects()
                    ->where('id', $row['enrollment_subject_id'])
                    ->first();

                if (! $enrollment) {
                    continue;
                }

                $grade = Grade::firstOrCreate(['enrollment_subject_id' => $enrollment->id]);

                if ($grade->is_locked) {
                    continue;
                }

                foreach (['prelim', 'midterm', 'semi_final', 'final'] as $field) {
                    if (! array_key_exists($field, $row) || $row[$field] === null || $row[$field] === '') {
                        continue;
                    }

                    $value = (float) $row[$field];
                    if (! $this->calculator->validatePeriodGrade($value, $level)) {
                        abort(422, "Invalid {$field} grade for academic level.");
                    }
                    $grade->{$field} = $value;
                }

                $grade->encoded_by = $user->id;
                $grade->recalculate($this->calculator, $level);

                if (! empty($data['lock'])) {
                    $grade->is_locked = true;
                    $grade->submitted_at = now();
                }

                $grade->save();

                AuditLog::create([
                    'user_id' => $user->id,
                    'action' => 'grade.updated',
                    'auditable_type' => Grade::class,
                    'auditable_id' => $grade->id,
                    'new_values' => $grade->only(['prelim', 'midterm', 'semi_final', 'final', 'final_grade', 'remarks']),
                    'ip_address' => $request->ip(),
                ]);

                $results[] = $grade;
            }

            return $results;
        });

        return response()->json(['grades' => $updated]);
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
