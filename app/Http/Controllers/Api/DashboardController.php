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
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function summary(Request $request): JsonResponse
    {
        $role = $request->user()->role->value;

        if ($role === 'registrar') {
            return response()->json($this->registrarSummary());
        }

        if (in_array($role, ['admin', 'stakeholder', 'it'], true)) {
            return response()->json($this->adminSummary());
        }

        if ($role === 'teacher') {
            return response()->json($this->teacherSummary($request->user()->id));
        }

        if ($role === 'student') {
            return response()->json($this->studentSummary($request->user()));
        }

        return response()->json([]);
    }

    private function registrarSummary(): array
    {
        $students = StudentProfile::query();
        $college = (clone $students)->where('academic_level', 'college')->count();
        $shs = (clone $students)->where('academic_level', 'shs')->count();
        $byProgram = $this->studentsByProgram();

        return [
            'students' => StudentProfile::count(),
            'students_college' => $college,
            'students_shs' => $shs,
            'pending_grade_changes' => GradeChangeRequest::where('status', 'pending')->count(),
            'pending_grade_submissions' => GradeSubmission::where('status', 'pending')->count(),
            'admissions' => Admission::count(),
            'students_by_program' => $byProgram,
            'analytics' => [
                'students_by_level' => [
                    ['key' => 'college', 'label' => 'College', 'total' => $college],
                    ['key' => 'shs', 'label' => 'Senior High', 'total' => $shs],
                ],
                'students_by_program' => $byProgram,
                'admissions_by_status' => $this->admissionsByStatus(),
                'admissions_by_term' => $this->admissionsByTerm(),
            ],
        ];
    }

    private function studentsByProgram(): array
    {
        return StudentProfile::query()
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

    private function admissionsByStatus(): array
    {
        $counts = Admission::query()
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

    private function admissionsByTerm(): array
    {
        $termIds = SchoolTerm::query()
            ->orderByDesc('id')
            ->limit(6)
            ->pluck('id');

        if ($termIds->isEmpty()) {
            return [];
        }

        $counts = Admission::query()
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

    private function adminSummary(): array
    {
        $grades = Grade::query();
        $totalGrades = (clone $grades)->count();
        $completeGrades = (clone $grades)->whereNotNull('final_grade')->count();
        $college = StudentProfile::query()->where('academic_level', 'college')->count();
        $shs = StudentProfile::query()->where('academic_level', 'shs')->count();
        $byProgram = $this->studentsByProgram();
        $activeUsers = User::where('is_active', true)->count();
        $inactiveUsers = User::where('is_active', false)->count();

        return [
            'students' => StudentProfile::count(),
            'students_college' => $college,
            'students_shs' => $shs,
            'teachers' => User::where('role', 'teacher')->count(),
            'staff' => User::whereIn('role', ['registrar', 'admin', 'it', 'stakeholder', 'teacher'])->count(),
            'programs' => Program::count(),
            'subjects' => Subject::count(),
            'admissions' => Admission::count(),
            'class_sections' => ClassSection::count(),
            'users_total' => User::count(),
            'users_active' => $activeUsers,
            'users_inactive' => $inactiveUsers,
            'pending_grade_changes' => GradeChangeRequest::where('status', 'pending')->count(),
            'pending_grade_submissions' => GradeSubmission::where('status', 'pending')->count(),
            'grade_completion_percent' => $totalGrades > 0 ? round(($completeGrades / $totalGrades) * 100, 1) : 0,
            'students_by_program' => $byProgram,
            'analytics' => [
                'students_by_level' => [
                    ['key' => 'college', 'label' => 'College', 'total' => $college],
                    ['key' => 'shs', 'label' => 'Senior High', 'total' => $shs],
                ],
                'students_by_program' => $byProgram,
                'users_by_role' => $this->usersByRole(),
                'users_by_status' => [
                    ['key' => 'active', 'label' => 'Active', 'total' => $activeUsers],
                    ['key' => 'inactive', 'label' => 'Inactive', 'total' => $inactiveUsers],
                ],
                'admissions_by_status' => $this->admissionsByStatus(),
            ],
        ];
    }

    private function usersByRole(): array
    {
        $counts = User::query()
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

    private function teacherSummary(int $teacherId): array
    {
        $classes = ClassSection::where('teacher_id', $teacherId)->count();
        $pending = GradeChangeRequest::where('requested_by', $teacherId)->where('status', 'pending')->count();
        $returned = GradeSubmission::where('status', 'returned')
            ->whereHas('classSection', fn ($q) => $q->where('teacher_id', $teacherId))
            ->count();
        $awaitingReview = GradeSubmission::where('status', 'pending')
            ->whereHas('classSection', fn ($q) => $q->where('teacher_id', $teacherId))
            ->count();

        return [
            'my_classes' => $classes,
            'pending_change_requests' => $pending,
            'returned_submissions' => $returned,
            'submissions_awaiting_review' => $awaitingReview,
        ];
    }

    private function studentSummary(User $user): array
    {
        $profile = $user->studentProfile;
        if (! $profile) {
            return [];
        }

        $profile->load(['program', 'programMajor']);

        return [
            'admissions_count' => $profile->admissions()->count(),
            'program' => $profile->program,
            'program_major' => $profile->programMajor,
            'year_level' => $profile->year_level,
            'academic_level' => $profile->academic_level,
        ];
    }
}
