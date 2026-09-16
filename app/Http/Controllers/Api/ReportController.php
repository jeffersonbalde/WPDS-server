<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Admission;
use App\Models\Grade;
use App\Models\GradeChangeRequest;
use App\Models\GradeSubmission;
use App\Models\SchoolTerm;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Read-only monitoring reports for Admin / Stakeholder oversight.
 */
class ReportController extends Controller
{
    public function population(Request $request): JsonResponse
    {
        return response()->json($this->populationData($request));
    }

    /**
     * Students enrolled in a given program (optionally narrowed to a year level)
     * for the resolved term — backs the "View Students" drill-down on the
     * Student Population Report.
     */
    public function populationStudents(Request $request): JsonResponse
    {
        $termId = $this->resolveTermId($request);

        $query = Admission::query()
            ->when($termId, fn (Builder $q) => $q->where('admissions.school_term_id', $termId))
            ->with(['studentProfile.user', 'program']);

        if ($programId = $request->query('program_id')) {
            $query->where('admissions.program_id', (int) $programId);
        }

        if ($yearLevel = $request->query('year_level')) {
            $query->where('admissions.year_level', $yearLevel);
        }

        if ($search = trim((string) $request->query('search', ''))) {
            $like = '%'.addcslashes($search, '%_\\').'%';
            $query->whereHas('studentProfile', function (Builder $q) use ($like) {
                $q->where('student_no', 'like', $like)
                    ->orWhere('last_name', 'like', $like)
                    ->orWhere('first_name', 'like', $like)
                    ->orWhere('contact_email', 'like', $like);
            });
        }

        $query->join('student_profiles', 'student_profiles.id', '=', 'admissions.student_profile_id')
            ->orderBy('student_profiles.last_name')
            ->orderBy('student_profiles.first_name')
            ->select('admissions.*');

        $perPage = min(100, max(10, (int) $request->query('per_page', 10)));
        $paginator = $query->paginate($perPage)->appends($request->query());

        $paginator->getCollection()->transform(fn (Admission $admission) => [
            'student_id' => $admission->studentProfile?->id,
            'student_no' => $admission->studentProfile?->student_no,
            'name' => $admission->studentProfile
                ? trim($admission->studentProfile->last_name.', '.$admission->studentProfile->first_name)
                : '—',
            'program_code' => $admission->program?->code,
            'year_level' => $admission->year_level,
            'status' => $admission->status,
            'email' => $admission->studentProfile?->contact_email ?? $admission->studentProfile?->user?->email,
        ]);

        return response()->json($paginator->toArray());
    }

    public function exportPopulation(Request $request): StreamedResponse
    {
        $data = $this->populationData($request);

        $headers = ['Level', 'Program Code', 'Program Name', 'Year Level', 'Students'];
        $rows = collect($data['breakdown'])->map(fn ($row) => [
            $row['level'],
            $row['program_code'],
            $row['program_name'],
            $row['year_level'],
            $row['total'],
        ]);

        return $this->streamReport(
            'STUDENT POPULATION REPORT',
            $this->termFilterLine($data['term']),
            $headers,
            $rows,
            'population-report'
        );
    }

    public function performance(Request $request): JsonResponse
    {
        return response()->json($this->performanceData($request));
    }

    public function exportPerformance(Request $request): StreamedResponse
    {
        $data = $this->performanceData($request);

        $headers = ['Program Code', 'Program Name', 'Graded', 'Passed', 'Failed', 'Average'];
        $rows = collect($data['by_program'])->map(fn ($row) => [
            $row['program_code'],
            $row['program_name'],
            $row['total'],
            $row['passed'],
            $row['failed'],
            $row['average'],
        ]);

        return $this->streamReport(
            'ACADEMIC PERFORMANCE REPORT — BY PROGRAM',
            $this->termFilterLine($data['term']),
            $headers,
            $rows,
            'performance-report'
        );
    }

    /**
     * Students behind a Program / Subject / Teacher breakdown row (or the
     * overall at-risk list) on the Academic Performance Report.
     */
    public function performanceStudents(Request $request): JsonResponse
    {
        $termId = $this->resolveTermId($request);

        $query = Grade::query()
            ->whereHas('enrollmentSubject.classSection', function (Builder $q) use ($termId, $request) {
                $q->where('school_term_id', $termId);
                if ($teacherId = $request->query('teacher_id')) {
                    $q->where('teacher_id', (int) $teacherId);
                }
                if ($subjectId = $request->query('subject_id')) {
                    $q->where('subject_id', (int) $subjectId);
                }
            })
            ->whereNotNull('final_grade');

        if ($programId = $request->query('program_id')) {
            $query->whereHas('enrollmentSubject.admission', fn (Builder $q) => $q->where('program_id', (int) $programId));
        }

        $status = (string) $request->query('status', 'all');
        if ($status === 'passed') {
            $query->where('remarks', 'PASSED');
        } elseif ($status === 'failed') {
            $query->where('remarks', 'FAILED');
        }

        if ($search = trim((string) $request->query('search', ''))) {
            $like = '%'.addcslashes($search, '%_\\').'%';
            $query->whereHas('enrollmentSubject.admission.studentProfile', function (Builder $q) use ($like) {
                $q->where('student_no', 'like', $like)
                    ->orWhere('last_name', 'like', $like)
                    ->orWhere('first_name', 'like', $like);
            });
        }

        $query->with([
            'enrollmentSubject.admission.studentProfile:id,student_no,last_name,first_name',
            'enrollmentSubject.admission.program:id,code',
            'enrollmentSubject.classSection.subject:id,code',
            'enrollmentSubject.classSection.teacher:id,name',
        ])->orderByDesc('id');

        $perPage = min(100, max(10, (int) $request->query('per_page', 10)));
        $paginator = $query->paginate($perPage)->appends($request->query());

        $paginator->getCollection()->transform(function (Grade $grade) {
            $sp = $grade->enrollmentSubject?->admission?->studentProfile;

            return [
                'student_id' => $sp?->id,
                'student_no' => $sp?->student_no,
                'name' => $sp ? trim($sp->last_name.', '.$sp->first_name) : '—',
                'program_code' => $grade->enrollmentSubject?->admission?->program?->code,
                'subject_code' => $grade->enrollmentSubject?->classSection?->subject?->code,
                'teacher_name' => $grade->enrollmentSubject?->classSection?->teacher?->name,
                'final_grade' => $grade->final_grade,
                'remarks' => $grade->remarks,
            ];
        });

        return response()->json($paginator->toArray());
    }

    public function gradeOperations(Request $request): JsonResponse
    {
        return response()->json($this->gradeOperationsData($request));
    }

    public function exportGradeOperations(Request $request): StreamedResponse
    {
        $data = $this->gradeOperationsData($request);

        $headers = ['Teacher', 'Pending', 'Returned', 'Released'];
        $rows = collect($data['submissions_by_teacher'])->map(fn ($row) => [
            $row['name'],
            $row['pending'],
            $row['returned'],
            $row['released'],
        ]);

        return $this->streamReport(
            'GRADE OPERATIONS REPORT — SUBMISSIONS BY TEACHER',
            $this->termFilterLine($data['term']),
            $headers,
            $rows,
            'grade-operations-report'
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function populationData(Request $request): array
    {
        $termId = $this->resolveTermId($request);

        $base = Admission::query()->where('school_term_id', $termId);

        $total = (clone $base)->count();
        $byStatus = (clone $base)
            ->select('status', DB::raw('count(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status');

        $accounts = (clone $base)
            ->join('student_profiles', 'student_profiles.id', '=', 'admissions.student_profile_id')
            ->join('users', 'users.id', '=', 'student_profiles.user_id')
            ->select(
                DB::raw('sum(case when users.is_active = 1 then 1 else 0 end) as active'),
                DB::raw('sum(case when users.is_active = 0 then 1 else 0 end) as inactive'),
            )
            ->first();

        $breakdown = (clone $base)
            ->join('programs', 'programs.id', '=', 'admissions.program_id')
            ->select(
                'programs.id as program_id',
                'programs.academic_level',
                'programs.code as program_code',
                'programs.name as program_name',
                'admissions.year_level',
                DB::raw('count(*) as total'),
            )
            ->groupBy('programs.id', 'programs.academic_level', 'programs.code', 'programs.name', 'admissions.year_level')
            ->orderBy('programs.academic_level')
            ->orderBy('programs.code')
            ->orderBy('admissions.year_level')
            ->get()
            ->map(fn ($row) => [
                'program_id' => (int) $row->program_id,
                'level' => $this->levelLabel($row->academic_level),
                'program_code' => $row->program_code,
                'program_name' => $row->program_name,
                'year_level' => $row->year_level,
                'total' => (int) $row->total,
            ]);

        return [
            'term' => SchoolTerm::find($termId),
            'total' => $total,
            'by_status' => $byStatus,
            'active_accounts' => (int) ($accounts->active ?? 0),
            'inactive_accounts' => (int) ($accounts->inactive ?? 0),
            'breakdown' => $breakdown,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function performanceData(Request $request): array
    {
        $termId = $this->resolveTermId($request);

        $gradesQuery = Grade::query()
            ->whereHas('enrollmentSubject.classSection', fn ($q) => $q->where('school_term_id', $termId))
            ->whereNotNull('final_grade');

        $total = (clone $gradesQuery)->count();
        $passed = (clone $gradesQuery)->where('remarks', 'PASSED')->count();
        $failed = (clone $gradesQuery)->where('remarks', 'FAILED')->count();
        $average = (clone $gradesQuery)->avg('final_grade');

        $joined = fn () => DB::table('grades')
            ->join('enrollment_subjects', 'enrollment_subjects.id', '=', 'grades.enrollment_subject_id')
            ->join('class_sections', 'class_sections.id', '=', 'enrollment_subjects.class_section_id')
            ->join('admissions', 'admissions.id', '=', 'enrollment_subjects.admission_id')
            ->where('class_sections.school_term_id', $termId)
            ->whereNotNull('grades.final_grade');

        $summaryColumns = [
            DB::raw('count(*) as total'),
            DB::raw("sum(case when grades.remarks = 'PASSED' then 1 else 0 end) as passed"),
            DB::raw("sum(case when grades.remarks = 'FAILED' then 1 else 0 end) as failed"),
            DB::raw('round(avg(grades.final_grade), 2) as average'),
        ];

        $byProgram = $joined()
            ->join('programs', 'programs.id', '=', 'admissions.program_id')
            ->select('programs.id as program_id', 'programs.code as program_code', 'programs.name as program_name', ...$summaryColumns)
            ->groupBy('programs.id', 'programs.code', 'programs.name')
            ->orderBy('programs.code')
            ->get();

        $bySubject = $joined()
            ->join('subjects', 'subjects.id', '=', 'class_sections.subject_id')
            ->select('subjects.id as subject_id', 'subjects.code as subject_code', 'subjects.title as subject_title', ...$summaryColumns)
            ->groupBy('subjects.id', 'subjects.code', 'subjects.title')
            ->orderBy('subjects.code')
            ->get();

        $byTeacher = $joined()
            ->join('users', 'users.id', '=', 'class_sections.teacher_id')
            ->select('users.id as teacher_id', 'users.name as teacher_name', ...$summaryColumns)
            ->groupBy('users.id', 'users.name')
            ->orderBy('users.name')
            ->get();

        $atRiskTotal = (clone $gradesQuery)->where('remarks', 'FAILED')->count();
        $atRisk = Grade::query()
            ->where('remarks', 'FAILED')
            ->whereHas('enrollmentSubject.classSection', fn ($q) => $q->where('school_term_id', $termId))
            ->with([
                'enrollmentSubject.admission.studentProfile:id,student_no,last_name,first_name',
                'enrollmentSubject.classSection.subject:id,code,title',
            ])
            ->orderByDesc('id')
            ->limit(100)
            ->get()
            ->map(fn (Grade $grade) => [
                'student_no' => $grade->enrollmentSubject?->admission?->studentProfile?->student_no,
                'name' => $grade->enrollmentSubject?->admission?->studentProfile
                    ? trim($grade->enrollmentSubject->admission->studentProfile->last_name.', '.$grade->enrollmentSubject->admission->studentProfile->first_name)
                    : null,
                'subject_code' => $grade->enrollmentSubject?->classSection?->subject?->code,
                'final_grade' => $grade->final_grade,
            ]);

        return [
            'term' => SchoolTerm::find($termId),
            'total_graded' => $total,
            'passed' => $passed,
            'failed' => $failed,
            'average' => $average !== null ? round((float) $average, 2) : null,
            'by_program' => $byProgram,
            'by_subject' => $bySubject,
            'by_teacher' => $byTeacher,
            'at_risk_total' => $atRiskTotal,
            'at_risk' => $atRisk,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function gradeOperationsData(Request $request): array
    {
        $termId = $this->resolveTermId($request);

        $submissions = GradeSubmission::query()
            ->whereHas('classSection', fn ($q) => $q->where('school_term_id', $termId));

        $byStatus = (clone $submissions)
            ->select('status', DB::raw('count(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status');

        $byTeacher = DB::table('grade_submissions')
            ->join('class_sections', 'class_sections.id', '=', 'grade_submissions.class_section_id')
            ->join('users', 'users.id', '=', 'class_sections.teacher_id')
            ->where('class_sections.school_term_id', $termId)
            ->select(
                'users.id',
                'users.name',
                DB::raw("sum(case when grade_submissions.status = 'pending' then 1 else 0 end) as pending"),
                DB::raw("sum(case when grade_submissions.status = 'returned' then 1 else 0 end) as returned"),
                DB::raw("sum(case when grade_submissions.status = 'released' then 1 else 0 end) as released"),
            )
            ->groupBy('users.id', 'users.name')
            ->orderBy('users.name')
            ->get();

        $changeRequests = GradeChangeRequest::query()
            ->whereHas('grade.enrollmentSubject.classSection', fn ($q) => $q->where('school_term_id', $termId));

        $changeByStatus = (clone $changeRequests)
            ->select('status', DB::raw('count(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status');

        return [
            'term' => SchoolTerm::find($termId),
            'submissions_by_status' => $byStatus,
            'submissions_by_teacher' => $byTeacher,
            'change_requests_by_status' => $changeByStatus,
        ];
    }

    private function resolveTermId(Request $request): ?int
    {
        if ($termId = $request->query('school_term_id')) {
            return (int) $termId;
        }

        return SchoolTerm::query()->where('is_active', true)->value('id');
    }

    private function levelLabel(mixed $level): string
    {
        $value = $level instanceof \BackedEnum ? $level->value : (string) $level;

        return match ($value) {
            'college' => 'College',
            'shs' => 'Senior High',
            default => strtoupper($value) ?: '—',
        };
    }

    private function termFilterLine(?SchoolTerm $term): string
    {
        return $term ? 'Term: '.$term->name : 'Term: All / none active';
    }

    /**
     * @param  array<int, string>  $headers
     * @param  Collection<int, array<int, mixed>>  $rows
     */
    private function streamReport(string $title, string $filterLine, array $headers, $rows, string $slug): StreamedResponse
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Report');

        $lastCol = Coordinate::stringFromColumnIndex(count($headers));
        $row = 1;

        $institution = (string) config('app.curriculum_institution_name', 'West Prime Horizon Institute, Inc.');

        $sheet->setCellValue("A{$row}", $institution);
        $sheet->mergeCells("A{$row}:{$lastCol}{$row}");
        $row++;
        $sheet->setCellValue("A{$row}", $title);
        $sheet->mergeCells("A{$row}:{$lastCol}{$row}");
        $row++;
        $sheet->setCellValue("A{$row}", $filterLine);
        $sheet->mergeCells("A{$row}:{$lastCol}{$row}");
        $row += 2;

        $sheet->fromArray($headers, null, 'A'.$row);
        $headerRange = "A{$row}:{$lastCol}{$row}";
        $sheet->getStyle($headerRange)->getFont()->setBold(true);
        $sheet->getStyle($headerRange)->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setRGB('E9ECEF');
        $sheet->getStyle($headerRange)->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
        $row++;

        foreach ($rows as $dataRow) {
            $sheet->fromArray($dataRow, null, 'A'.$row);
            $row++;
        }

        foreach (range(1, count($headers)) as $col) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($col))->setAutoSize(true);
        }

        $filename = $slug.'-'.now()->format('Ymd-His').'.xlsx';

        return response()->streamDownload(function () use ($spreadsheet) {
            $writer = new Xlsx($spreadsheet);
            $writer->save('php://output');
            $spreadsheet->disconnectWorksheets();
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }
}
