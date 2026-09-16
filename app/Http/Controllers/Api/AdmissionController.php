<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Admission;
use App\Models\ClassSection;
use App\Models\EnrollmentSubject;
use App\Models\Grade;
use App\Models\GradeSubmission;
use App\Models\Program;
use App\Models\SchoolTerm;
use App\Models\StudentProfile;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AdmissionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = $this->listQuery($request);

        $perPage = min(100, max(10, (int) $request->query('per_page', 50)));

        return response()->json($query->orderByDesc('id')->paginate($perPage));
    }

    public function export(Request $request): StreamedResponse
    {
        $admissions = $this->listQuery($request)
            ->orderByDesc('id')
            ->get();

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Admissions');

        $headers = [
            '#',
            'Admission #',
            'Student No.',
            'Last Name',
            'First Name',
            'Term',
            'School Year',
            'Program Code',
            'Program Name',
            'Major Code',
            'Major',
            'Year Level',
            'Section',
            'Status',
            'Record Created',
            'Record Updated',
        ];

        $lastCol = Coordinate::stringFromColumnIndex(count($headers));
        $row = 1;

        $institution = (string) config('app.curriculum_institution_name', 'West Prime Horizon Institute, Inc.');
        $filterLine = $this->exportFilterSummary($request);

        $sheet->setCellValue("A{$row}", $institution);
        $sheet->mergeCells("A{$row}:{$lastCol}{$row}");
        $row++;
        $sheet->setCellValue("A{$row}", 'ADMISSIONS REPORT');
        $sheet->mergeCells("A{$row}:{$lastCol}{$row}");
        $row++;
        $sheet->setCellValue("A{$row}", $filterLine);
        $sheet->mergeCells("A{$row}:{$lastCol}{$row}");
        $row++;
        $sheet->setCellValue("A{$row}", $admissions->count().' admission'.($admissions->count() === 1 ? '' : 's'));
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

        $num = 1;
        foreach ($admissions as $admission) {
            $profile = $admission->studentProfile;
            $major = $admission->programMajor;

            $sheet->fromArray([
                $num,
                $admission->admission_number,
                $profile?->student_no,
                $profile?->last_name,
                $profile?->first_name,
                $admission->schoolTerm?->name,
                $admission->schoolTerm?->school_year,
                $admission->program?->code,
                $admission->program?->name,
                $major?->code,
                $major?->name ?: $major?->label,
                $admission->year_level,
                $admission->section,
                ucfirst((string) $admission->status),
                optional($admission->created_at)?->format('Y-m-d H:i:s'),
                optional($admission->updated_at)?->format('Y-m-d H:i:s'),
            ], null, 'A'.$row);

            $num++;
            $row++;
        }

        foreach (range(1, count($headers)) as $col) {
            $sheet->getColumnDimension(
                Coordinate::stringFromColumnIndex($col)
            )->setAutoSize(true);
        }

        $filename = 'admissions-export-'.now()->format('Ymd-His').'.xlsx';

        return response()->streamDownload(function () use ($spreadsheet) {
            $writer = new Xlsx($spreadsheet);
            $writer->save('php://output');
            $spreadsheet->disconnectWorksheets();
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'student_profile_id' => ['required', 'exists:student_profiles,id'],
            'school_term_id' => ['required', 'exists:school_terms,id'],
            'program_id' => ['required', 'exists:programs,id'],
            'program_major_id' => ['nullable', 'integer', 'exists:program_majors,id'],
            'year_level' => ['required', 'integer', 'min:1', 'max:6'],
            'section' => ['required', 'string', 'max:50'],
            'status' => ['nullable', 'in:enrolled,withdrawn,completed'],
            'class_section_ids' => ['required', 'array', 'min:1'],
            'class_section_ids.*' => ['exists:class_sections,id'],
        ], [
            'student_profile_id.required' => 'Student is required.',
            'school_term_id.required' => 'School term is required.',
            'program_id.required' => 'Program is required.',
            'year_level.required' => 'Year level is required.',
            'section.required' => 'Section is required.',
            'class_section_ids.required' => 'Select at least one class section.',
            'class_section_ids.min' => 'Select at least one class section.',
        ]);

        $duplicate = Admission::query()
            ->where('student_profile_id', $data['student_profile_id'])
            ->where('school_term_id', $data['school_term_id'])
            ->exists();

        if ($duplicate) {
            return response()->json([
                'message' => 'This student already has an admission for the selected term.',
                'errors' => [
                    'school_term_id' => ['This student already has an admission for the selected term.'],
                ],
            ], 422);
        }

        $profile = StudentProfile::findOrFail($data['student_profile_id']);
        $program = Program::with('majors')->findOrFail($data['program_id']);
        $programLevel = $program->academic_level instanceof \BackedEnum
            ? $program->academic_level->value
            : (string) $program->academic_level;
        $profileLevel = $profile->academic_level instanceof \BackedEnum
            ? $profile->academic_level->value
            : (string) $profile->academic_level;

        if ($programLevel !== $profileLevel) {
            return response()->json([
                'message' => 'Selected program does not match the student’s academic level.',
                'errors' => [
                    'program_id' => ['Selected program does not match the student’s academic level.'],
                ],
            ], 422);
        }

        $majorId = ! empty($data['program_major_id']) ? (int) $data['program_major_id'] : null;
        $activeMajors = $program->majors->where('is_active', true)->values();

        if ($activeMajors->isNotEmpty()) {
            if (! $majorId) {
                return response()->json([
                    'message' => 'A major is required for this program.',
                    'errors' => [
                        'program_major_id' => ['Select a major for this program.'],
                    ],
                ], 422);
            }
            $major = $program->majors->firstWhere('id', $majorId);
            if (! $major || $major->is_active === false) {
                return response()->json([
                    'message' => 'Selected major does not belong to this program.',
                    'errors' => [
                        'program_major_id' => ['Selected major does not belong to this program.'],
                    ],
                ], 422);
            }
        } elseif ($majorId) {
            return response()->json([
                'message' => 'This program has no majors.',
                'errors' => [
                    'program_major_id' => ['This program has no majors.'],
                ],
            ], 422);
        }

        $classSectionIds = array_values(array_unique(array_map('intval', $data['class_section_ids'] ?? [])));
        if ($classSectionIds !== []) {
            $validCount = ClassSection::query()
                ->whereIn('id', $classSectionIds)
                ->where('school_term_id', $data['school_term_id'])
                ->count();

            if ($validCount !== count($classSectionIds)) {
                return response()->json([
                    'message' => 'One or more class sections are invalid for the selected term.',
                    'errors' => [
                        'class_section_ids' => ['One or more class sections are invalid for the selected term.'],
                    ],
                ], 422);
            }
        }

        $admission = DB::transaction(function () use ($data, $profile, $classSectionIds, $majorId) {
            $nextId = (int) (Admission::max('id') ?? 0) + 1;
            $admission = Admission::create([
                'admission_number' => 'ADM-'.now()->format('Y').str_pad((string) $nextId, 5, '0', STR_PAD_LEFT),
                'student_profile_id' => $data['student_profile_id'],
                'school_term_id' => $data['school_term_id'],
                'program_id' => $data['program_id'],
                'program_major_id' => $majorId,
                'year_level' => $data['year_level'],
                'section' => isset($data['section']) && trim((string) $data['section']) !== ''
                    ? trim($data['section'])
                    : null,
                'status' => $data['status'] ?? 'enrolled',
            ]);

            // Keep student profile current placement in sync with latest admission.
            $profile->update([
                'program_id' => $admission->program_id,
                'program_major_id' => $admission->program_major_id,
                'year_level' => $admission->year_level,
                'section' => $admission->section,
            ]);

            foreach ($classSectionIds as $classSectionId) {
                $enrollment = EnrollmentSubject::create([
                    'admission_id' => $admission->id,
                    'class_section_id' => $classSectionId,
                ]);
                Grade::create(['enrollment_subject_id' => $enrollment->id]);
            }

            return $admission;
        });

        return response()->json($admission->load([
            'studentProfile.user',
            'schoolTerm',
            'program',
            'programMajor',
            'enrollmentSubjects.classSection.subject',
        ]), 201);
    }

    public function show(Request $request, Admission $admission): JsonResponse
    {
        $user = $request->user();
        $isStudent = $user->role->value === 'student';

        if ($isStudent && $admission->student_profile_id !== $user->studentProfile?->id) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $admission->load([
            'studentProfile.user',
            'schoolTerm',
            'program',
            'programMajor',
            'enrollmentSubjects.classSection.subject',
            'enrollmentSubjects.classSection.teacher',
            'enrollmentSubjects.grade',
        ]);

        if ($isStudent) {
            $this->maskUnreleasedGrades($admission);
        }

        return response()->json($admission);
    }

    /**
     * Students only see a period grade after the registrar releases it.
     * Final grade and remarks appear only once all four periods are released.
     */
    private function maskUnreleasedGrades(Admission $admission): void
    {
        $sectionIds = $admission->enrollmentSubjects
            ->pluck('class_section_id')
            ->filter()
            ->unique()
            ->all();

        if ($sectionIds === []) {
            return;
        }

        $releasedBySection = GradeSubmission::query()
            ->whereIn('class_section_id', $sectionIds)
            ->where('status', 'released')
            ->get(['class_section_id', 'period'])
            ->groupBy('class_section_id')
            ->map(fn ($rows) => $rows->pluck('period')->all());

        $periods = GradeSubmission::PERIODS;

        foreach ($admission->enrollmentSubjects as $enrollment) {
            $grade = $enrollment->grade;
            if (! $grade) {
                continue;
            }

            $released = $releasedBySection[$enrollment->class_section_id] ?? [];

            foreach ($periods as $period) {
                if (! in_array($period, $released, true)) {
                    $grade->{$period} = null;
                }
            }

            if (count(array_intersect($periods, $released)) !== count($periods)) {
                $grade->final_grade = null;
                $grade->remarks = null;
            }
        }
    }

    public function updateStatus(Request $request, Admission $admission): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', 'in:enrolled,withdrawn,completed'],
        ], [
            'status.required' => 'Status is required.',
            'status.in' => 'Invalid admission status.',
        ]);

        if ($admission->status !== $data['status']) {
            $admission->update(['status' => $data['status']]);
        }

        return response()->json($admission->fresh()->load([
            'studentProfile.user',
            'schoolTerm',
            'program',
            'programMajor',
        ]));
    }

    public function destroy(Admission $admission): JsonResponse
    {
        $usage = $this->deleteUsagePayload($admission);

        if (! $usage['can_delete']) {
            return response()->json([
                'message' => 'This admission cannot be deleted because grades are already recorded. Mark it as Withdrawn instead.',
                'usage' => $usage,
            ], 422);
        }

        DB::transaction(function () use ($admission) {
            $admission->delete();
        });

        return response()->json(['message' => 'Admission deleted.']);
    }

    public function enrollSubjects(Request $request, Admission $admission): JsonResponse
    {
        $data = $request->validate([
            'class_section_ids' => ['required', 'array', 'min:1'],
            'class_section_ids.*' => ['exists:class_sections,id'],
        ]);

        DB::transaction(function () use ($data, $admission) {
            foreach ($data['class_section_ids'] as $classSectionId) {
                $enrollment = EnrollmentSubject::firstOrCreate([
                    'admission_id' => $admission->id,
                    'class_section_id' => $classSectionId,
                ]);
                Grade::firstOrCreate(['enrollment_subject_id' => $enrollment->id]);
            }
        });

        return response()->json($admission->fresh()->load([
            'enrollmentSubjects.classSection.subject',
            'enrollmentSubjects.grade',
        ]));
    }

    private function listQuery(Request $request): Builder
    {
        $user = $request->user();
        $query = Admission::with(['studentProfile.user', 'schoolTerm', 'program', 'programMajor']);

        if ($user->role->value === 'student') {
            $profileId = $user->studentProfile?->id;
            $query->where('student_profile_id', $profileId);
        } elseif ($studentId = $request->query('student_profile_id')) {
            $query->where('student_profile_id', $studentId);
        }

        $this->applyListFilters($query, $request);

        return $query;
    }

    private function applyListFilters(Builder $query, Request $request): void
    {
        if ($status = $request->query('status')) {
            if ($status !== 'all') {
                $query->where('status', $status);
            }
        }

        if ($termId = $request->query('school_term_id')) {
            $query->where('school_term_id', $termId);
        }

        if ($programId = $request->query('program_id')) {
            $query->where('program_id', $programId);
        }

        if ($search = trim((string) $request->query('search', ''))) {
            $query->where(function ($q) use ($search) {
                $q->where('admission_number', 'like', "%{$search}%")
                    ->orWhereHas('studentProfile', function ($sq) use ($search) {
                        $sq->where('student_no', 'like', "%{$search}%")
                            ->orWhere('last_name', 'like', "%{$search}%")
                            ->orWhere('first_name', 'like', "%{$search}%");
                    })
                    ->orWhereHas('program', function ($pq) use ($search) {
                        $pq->where('code', 'like', "%{$search}%")
                            ->orWhere('name', 'like', "%{$search}%");
                    });
            });
        }
    }

    private function exportFilterSummary(Request $request): string
    {
        $parts = ['Filters: All admissions'];

        if ($termId = $request->query('school_term_id')) {
            $term = SchoolTerm::find($termId);
            $parts[] = 'Term: '.($term?->name ?: 'Selected term');
        }

        if ($programId = $request->query('program_id')) {
            $program = Program::find($programId);
            $parts[] = 'Program: '.($program?->code ?: 'Selected program');
        }

        if ($status = $request->query('status')) {
            if ($status !== 'all') {
                $parts[] = 'Status: '.ucfirst($status);
            }
        }

        $search = trim((string) $request->query('search', ''));
        if ($search !== '') {
            $parts[] = 'Search: '.$search;
        }

        return implode(' · ', $parts);
    }

    private function deleteUsagePayload(Admission $admission): array
    {
        $subjectCount = $admission->enrollmentSubjects()->count();
        $hasGrades = $this->hasRecordedGrades($admission);

        return [
            'can_delete' => ! $hasGrades,
            'has_recorded_grades' => $hasGrades,
            'enrolled_subjects' => $subjectCount,
            'status' => $admission->status,
        ];
    }

    private function hasRecordedGrades(Admission $admission): bool
    {
        return Grade::query()
            ->whereHas('enrollmentSubject', function ($q) use ($admission) {
                $q->where('admission_id', $admission->id);
            })
            ->where(function ($q) {
                $q->whereNotNull('prelim')
                    ->orWhereNotNull('midterm')
                    ->orWhereNotNull('semi_final')
                    ->orWhereNotNull('final')
                    ->orWhereNotNull('final_grade')
                    ->orWhere(function ($inner) {
                        $inner->whereNotNull('remarks')
                            ->where('remarks', '!=', '');
                    })
                    ->orWhere('is_locked', true)
                    ->orWhereNotNull('submitted_at');
            })
            ->exists();
    }
}
