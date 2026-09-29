<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Admission;
use App\Models\ClassSection;
use App\Models\Grade;
use App\Models\GradeChangeRequest;
use App\Models\GradeSubmission;
use App\Models\Program;
use App\Models\SchoolTerm;
use App\Models\StudentProfile;
use App\Models\Subject;
use App\Models\User;
use App\Support\DateRangeFilter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function summary(Request $request): JsonResponse
    {
        $range = DateRangeFilter::fromRequest($request);
        $role = $request->user()->role->value;

        if ($role === 'registrar') {
            return response()->json($this->withFilter($this->registrarSummary($range), $range));
        }

        if (in_array($role, ['admin', 'stakeholder', 'it'], true)) {
            return response()->json($this->withFilter($this->adminSummary($range), $range));
        }

        if ($role === 'teacher') {
            return response()->json($this->withFilter($this->teacherSummary($request->user()->id, $range), $range));
        }

        if ($role === 'student') {
            return response()->json($this->withFilter($this->studentSummary($request->user(), $range), $range));
        }

        return response()->json($this->withFilter([], $range));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function withFilter(array $payload, DateRangeFilter $range): array
    {
        $payload['filter'] = $range->toArray();

        return $payload;
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    private function scoped(Builder $query, DateRangeFilter $range, string $column = 'created_at'): Builder
    {
        return $range->apply($query, $column);
    }

    /**
     * Prefer a timestamp column when present, otherwise fall back (e.g. submitted_at / created_at).
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    private function scopedCoalesce(Builder $query, DateRangeFilter $range, string $preferred, string $fallback = 'created_at'): Builder
    {
        if (! $range->isActive()) {
            return $query;
        }

        $allowed = ['created_at', 'submitted_at', 'updated_at', 'reviewed_at'];
        if (! in_array($preferred, $allowed, true) || ! in_array($fallback, $allowed, true)) {
            return $this->scoped($query, $range, 'created_at');
        }

        $expr = "DATE(COALESCE({$preferred}, {$fallback}))";

        if ($range->dateFrom !== null) {
            $query->whereRaw("{$expr} >= ?", [$range->dateFrom]);
        }
        if ($range->dateTo !== null) {
            $query->whereRaw("{$expr} <= ?", [$range->dateTo]);
        }

        return $query;
    }


    private function registrarSummary(DateRangeFilter $range): array
    {
        $students = $this->scoped(StudentProfile::query(), $range);
        $college = (clone $students)->where('academic_level', 'college')->count();
        $shs = (clone $students)->where('academic_level', 'shs')->count();
        $byProgram = $this->studentsByProgram($range);

        return [
            'students' => (clone $students)->count(),
            'students_college' => $college,
            'students_shs' => $shs,
            'pending_grade_changes' => $this->scoped(
                GradeChangeRequest::query()->where('status', 'pending'),
                $range
            )->count(),
            'pending_grade_submissions' => $this->scopedCoalesce(
                GradeSubmission::query()->where('status', 'pending'),
                $range,
                'submitted_at'
            )->count(),
            'admissions' => $this->scoped(Admission::query(), $range)->count(),
            'students_by_program' => $byProgram,
            'analytics' => [
                'students_by_level' => [
                    ['key' => 'college', 'label' => 'College', 'total' => $college],
                    ['key' => 'shs', 'label' => 'Senior High', 'total' => $shs],
                ],
                'students_by_program' => $byProgram,
                'admissions_by_status' => $this->admissionsByStatus($range),
                'admissions_by_term' => $this->admissionsByTerm($range),
            ],
        ];
    }

    private function studentsByProgram(DateRangeFilter $range): array
    {
        return $this->scoped(StudentProfile::query(), $range)
            ->select('program_id', DB::raw('count(*) as total'))
            ->groupBy('program_id')
            ->with('program')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($row) => [
                'program' => $row->program?->code,
                'name' => $row->program?->name,
                'total' => (int) $row->total,
            ])
            ->values()
            ->all();
    }

    private function admissionsByStatus(DateRangeFilter $range): array
    {
        $counts = $this->scoped(Admission::query(), $range)
            ->select('status', DB::raw('count(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status');

        $labels = [
            'enrolled' => 'Enrolled',
            'withdrawn' => 'Withdrawn',
            'completed' => 'Completed',
        ];

        return collect($labels)
            ->map(fn ($label, $key) => [
                'key' => $key,
                'label' => $label,
                'total' => (int) ($counts[$key] ?? 0),
            ])
            ->values()
            ->all();
    }

    private function admissionsByTerm(DateRangeFilter $range): array
    {
        $termIds = SchoolTerm::query()
            ->orderByDesc('id')
            ->limit(6)
            ->pluck('id');

        if ($termIds->isEmpty()) {
            return [];
        }

        $counts = $this->scoped(Admission::query(), $range)
            ->select('school_term_id', DB::raw('count(*) as total'))
            ->whereIn('school_term_id', $termIds)
            ->groupBy('school_term_id')
            ->pluck('total', 'school_term_id');

        return SchoolTerm::query()
            ->whereIn('id', $termIds)
            ->orderBy('id')
            ->get()
            ->map(fn (SchoolTerm $term) => [
                'term_id' => $term->id,
                'label' => $term->name ?: ($term->school_year ?: 'Term '.$term->id),
                'total' => (int) ($counts[$term->id] ?? 0),
            ])
            ->values()
            ->all();
    }

    private function adminSummary(DateRangeFilter $range): array
    {
        $grades = $this->scoped(Grade::query(), $range, 'updated_at');
        $totalGrades = (clone $grades)->count();
        $completeGrades = (clone $grades)->whereNotNull('final_grade')->count();

        $students = $this->scoped(StudentProfile::query(), $range);
        $college = (clone $students)->where('academic_level', 'college')->count();
        $shs = (clone $students)->where('academic_level', 'shs')->count();
        $byProgram = $this->studentsByProgram($range);

        $users = $this->scoped(User::query(), $range);
        $activeUsers = (clone $users)->where('is_active', true)->count();
        $inactiveUsers = (clone $users)->where('is_active', false)->count();

        return [
            'students' => (clone $students)->count(),
            'students_college' => $college,
            'students_shs' => $shs,
            'teachers' => (clone $users)->where('role', 'teacher')->count(),
            'staff' => (clone $users)->whereIn('role', ['registrar', 'admin', 'it', 'stakeholder', 'teacher'])->count(),
            'programs' => $this->scoped(Program::query(), $range)->count(),
            'subjects' => $this->scoped(Subject::query(), $range)->count(),
            'admissions' => $this->scoped(Admission::query(), $range)->count(),
            'class_sections' => $this->scoped(ClassSection::query(), $range)->count(),
            'users_total' => (clone $users)->count(),
            'users_active' => $activeUsers,
            'users_inactive' => $inactiveUsers,
            'pending_grade_changes' => $this->scoped(
                GradeChangeRequest::query()->where('status', 'pending'),
                $range
            )->count(),
            'pending_grade_submissions' => $this->scopedCoalesce(
                GradeSubmission::query()->where('status', 'pending'),
                $range,
                'submitted_at'
            )->count(),
            'grade_completion_percent' => $totalGrades > 0 ? round(($completeGrades / $totalGrades) * 100, 1) : 0,
            'students_by_program' => $byProgram,
            'analytics' => [
                'students_by_level' => [
                    ['key' => 'college', 'label' => 'College', 'total' => $college],
                    ['key' => 'shs', 'label' => 'Senior High', 'total' => $shs],
                ],
                'students_by_program' => $byProgram,
                'users_by_role' => $this->usersByRole($range),
                'users_by_status' => [
                    ['key' => 'active', 'label' => 'Active', 'total' => $activeUsers],
                    ['key' => 'inactive', 'label' => 'Inactive', 'total' => $inactiveUsers],
                ],
                'admissions_by_status' => $this->admissionsByStatus($range),
            ],
        ];
    }

    private function usersByRole(DateRangeFilter $range): array
    {
        $counts = $this->scoped(User::query(), $range)
            ->select('role', DB::raw('count(*) as total'))
            ->groupBy('role')
            ->pluck('total', 'role');

        $labels = [
            'student' => 'Students',
            'teacher' => 'Teachers',
            'registrar' => 'Registrar',
            'it' => 'IT',
            'admin' => 'Admin',
            'stakeholder' => 'Stakeholder',
        ];

        return collect($labels)
            ->map(fn ($label, $key) => [
                'key' => $key,
                'label' => $label,
                'total' => (int) ($counts[$key] ?? 0),
            ])
            ->filter(fn ($row) => $row['total'] > 0)
            ->values()
            ->all();
    }

    private function teacherSummary(int $teacherId, DateRangeFilter $range): array
    {
        // Current class load stays as a live snapshot; activity metrics respect the date range.
        $classes = ClassSection::where('teacher_id', $teacherId)->count();

        $pending = $this->scoped(
            GradeChangeRequest::query()
                ->where('requested_by', $teacherId)
                ->where('status', 'pending'),
            $range
        )->count();

        $returned = $this->scoped(
            GradeSubmission::query()
                ->where('status', 'returned')
                ->whereHas('classSection', fn ($q) => $q->where('teacher_id', $teacherId)),
            $range,
            'updated_at'
        )->count();

        $awaitingReview = $this->scopedCoalesce(
            GradeSubmission::query()
                ->where('status', 'pending')
                ->whereHas('classSection', fn ($q) => $q->where('teacher_id', $teacherId)),
            $range,
            'submitted_at'
        )->count();

        return [
            'my_classes' => $classes,
            'pending_change_requests' => $pending,
            'returned_submissions' => $returned,
            'submissions_awaiting_review' => $awaitingReview,
        ];
    }

    private function studentSummary(User $user, DateRangeFilter $range): array
    {
        $profile = $user->studentProfile;
        if (! $profile) {
            return [];
        }

        $profile->load(['program', 'programMajor']);

        return [
            'admissions_count' => $this->scoped(
                Admission::query()->where('student_profile_id', $profile->id),
                $range
            )->count(),
            'program' => $profile->program,
            'program_major' => $profile->programMajor,
            'year_level' => $profile->year_level,
            'academic_level' => $profile->academic_level,
        ];
    }
}
