<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Admission;
use App\Models\ClassSection;
use App\Models\Grade;
use App\Models\GradeChangeRequest;
use App\Models\Program;
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

        if (in_array($role, ['student', 'alumni'], true)) {
            return response()->json($this->studentSummary($request->user()));
        }

        return response()->json([]);
    }

    private function registrarSummary(): array
    {
        $students = StudentProfile::query();
        $college = (clone $students)->where('academic_level', 'college')->count();
        $shs = (clone $students)->where('academic_level', 'shs')->count();

        return [
            'students' => StudentProfile::count(),
            'students_college' => $college,
            'students_shs' => $shs,
            'pending_grade_changes' => GradeChangeRequest::where('status', 'pending')->count(),
            'admissions' => Admission::count(),
            'students_by_program' => $this->studentsByProgram(),
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
                'total' => $row->total,
            ])
            ->values()
            ->all();
    }

    private function adminSummary(): array
    {
        $grades = Grade::query();
        $totalGrades = (clone $grades)->count();
        $completeGrades = (clone $grades)->whereNotNull('final_grade')->count();

        return [
            'students' => StudentProfile::count(),
            'teachers' => User::where('role', 'teacher')->count(),
            'staff' => User::whereIn('role', ['registrar', 'admin', 'it', 'stakeholder', 'teacher'])->count(),
            'programs' => Program::count(),
            'subjects' => Subject::count(),
            'admissions' => Admission::count(),
            'class_sections' => ClassSection::count(),
            'pending_grade_changes' => GradeChangeRequest::where('status', 'pending')->count(),
            'grade_completion_percent' => $totalGrades > 0 ? round(($completeGrades / $totalGrades) * 100, 1) : 0,
            'students_by_program' => $this->studentsByProgram(),
        ];
    }

    private function teacherSummary(int $teacherId): array
    {
        $classes = ClassSection::where('teacher_id', $teacherId)->count();
        $pending = GradeChangeRequest::where('requested_by', $teacherId)->where('status', 'pending')->count();

        return [
            'my_classes' => $classes,
            'pending_change_requests' => $pending,
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

    public function systemStatus(): JsonResponse
    {
        return response()->json([
            'app' => config('app.name'),
            'environment' => config('app.env'),
            'database' => config('database.default'),
            'backup_note' => 'Use `php artisan backup` / DB dump for maintenance. Export available via IT tools.',
            'users_active' => User::where('is_active', true)->count(),
            'users_inactive' => User::where('is_active', false)->count(),
            'timestamp' => now()->toIso8601String(),
        ]);
    }
}
