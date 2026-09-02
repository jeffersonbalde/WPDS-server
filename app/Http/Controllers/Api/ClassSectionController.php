<?php

namespace App\Http\Controllers\Api;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\ClassSection;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ClassSectionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $query = ClassSection::with(['subject', 'schoolTerm', 'teacher'])
            ->withCount('enrollmentSubjects');

        if ($user->role->value === 'teacher') {
            $query->where('teacher_id', $user->id);
        }

        $this->applyListFilters($query, $request);

        $wantsPagination = $request->has('page') || $request->has('per_page');
        if (! $wantsPagination) {
            return response()->json(
                $query->orderByDesc('id')->get()
            );
        }

        $perPage = min(100, max(10, (int) $request->query('per_page', 10)));
        $paginator = $query
            ->orderByDesc('id')
            ->paginate($perPage)
            ->appends($request->query());

        return response()->json(array_merge($paginator->toArray(), [
            'summary' => $this->listSummary($request, $user),
        ]));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'school_term_id' => ['required', 'exists:school_terms,id'],
            'subject_id' => ['required', 'exists:subjects,id'],
            'teacher_id' => ['required', 'exists:users,id'],
            'section' => [
                'required',
                'string',
                'max:32',
                Rule::unique('class_sections')
                    ->where(fn ($q) => $q
                        ->where('school_term_id', $request->input('school_term_id'))
                        ->where('subject_id', $request->input('subject_id'))),
            ],
            'schedule_time' => ['required', 'string', 'max:120'],
            'schedule_day' => ['required', 'string', 'max:120'],
            'room' => ['required', 'string', 'max:64'],
        ]);

        $this->assertTeacherRole($data['teacher_id']);

        $section = ClassSection::create($data);

        return response()->json(
            $section->load(['subject', 'schoolTerm', 'teacher'])->loadCount('enrollmentSubjects'),
            201
        );
    }

    public function update(Request $request, ClassSection $classSection): JsonResponse
    {
        $data = $request->validate([
            'school_term_id' => ['required', 'exists:school_terms,id'],
            'subject_id' => ['required', 'exists:subjects,id'],
            'teacher_id' => ['required', 'exists:users,id'],
            'section' => [
                'required',
                'string',
                'max:32',
                Rule::unique('class_sections')
                    ->where(fn ($q) => $q
                        ->where('school_term_id', $request->input('school_term_id'))
                        ->where('subject_id', $request->input('subject_id')))
                    ->ignore($classSection->id),
            ],
            'schedule_time' => ['required', 'string', 'max:120'],
            'schedule_day' => ['required', 'string', 'max:120'],
            'room' => ['required', 'string', 'max:64'],
        ]);

        $this->assertTeacherRole($data['teacher_id']);

        $classSection->update($data);

        return response()->json(
            $classSection->fresh()->load(['subject', 'schoolTerm', 'teacher'])->loadCount('enrollmentSubjects')
        );
    }

    public function show(ClassSection $classSection): JsonResponse
    {
        $classSection->load([
            'subject',
            'schoolTerm',
            'teacher',
            'enrollmentSubjects.admission.studentProfile.user',
            'enrollmentSubjects.admission.program',
            'enrollmentSubjects.grade',
        ])->loadCount('enrollmentSubjects');

        $sorted = $classSection->enrollmentSubjects
            ->sortBy(function ($enrollment) {
                $profile = $enrollment->admission?->studentProfile;

                return strtolower(trim(
                    ($profile->last_name ?? '').', '.($profile->first_name ?? '')
                ));
            })
            ->values();

        $classSection->setRelation('enrollmentSubjects', $sorted);

        return response()->json($classSection);
    }

    public function exportStudents(ClassSection $classSection): StreamedResponse
    {
        $classSection->load([
            'subject',
            'schoolTerm',
            'teacher',
            'enrollmentSubjects.admission.studentProfile',
            'enrollmentSubjects.admission.program',
        ]);

        $enrollments = $classSection->enrollmentSubjects
            ->sortBy(function ($enrollment) {
                $profile = $enrollment->admission?->studentProfile;

                return strtolower(trim(
                    ($profile->last_name ?? '').', '.($profile->first_name ?? '')
                ));
            })
            ->values();

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Enrolled Students');

        $headers = ['#', 'Student No.', 'Last Name', 'First Name', 'Middle Name', 'Program', 'Year Level'];
        $lastCol = Coordinate::stringFromColumnIndex(count($headers));
        $row = 1;

        $institution = (string) config('app.curriculum_institution_name', 'West Prime Horizon Institute, Inc.');
        $subjectLine = trim(($classSection->subject?->code ?? '').' — '.($classSection->subject?->title ?? ''));
        $termLine = implode(' · ', array_filter([
            $this->subjectLevelLabel($classSection->subject),
            $classSection->schoolTerm?->name,
            'Section '.($classSection->section ?: '—'),
        ]));
        $detailLine = implode(' · ', array_filter([
            $classSection->teacher?->name ? 'Teacher: '.$classSection->teacher->name : null,
            $this->scheduleLabel($classSection),
            $enrollments->count().' student'.($enrollments->count() === 1 ? '' : 's'),
        ]));

        $sheet->setCellValue("A{$row}", $institution);
        $sheet->mergeCells("A{$row}:{$lastCol}{$row}");
        $row++;
        $sheet->setCellValue("A{$row}", 'ENROLLED STUDENTS REPORT');
        $sheet->mergeCells("A{$row}:{$lastCol}{$row}");
        $row++;
        $sheet->setCellValue("A{$row}", $subjectLine);
        $sheet->mergeCells("A{$row}:{$lastCol}{$row}");
        $row++;
        $sheet->setCellValue("A{$row}", $termLine);
        $sheet->mergeCells("A{$row}:{$lastCol}{$row}");
        $row++;
        $sheet->setCellValue("A{$row}", $detailLine);
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
        foreach ($enrollments as $enrollment) {
            $profile = $enrollment->admission?->studentProfile;
            $program = $enrollment->admission?->program;

            $sheet->fromArray([
                $num,
                $profile?->student_no,
                $profile?->last_name,
                $profile?->first_name,
                $profile?->middle_name,
                $program?->code ?: $program?->name,
                $this->formatAdmissionYearLevel($enrollment->admission),
            ], null, 'A'.$row);

            $num++;
            $row++;
        }

        foreach (range(1, count($headers)) as $col) {
            $sheet->getColumnDimension(
                Coordinate::stringFromColumnIndex($col)
            )->setAutoSize(true);
        }

        $code = preg_replace('/[^A-Za-z0-9_-]+/', '-', (string) ($classSection->subject?->code ?? 'section'));
        $filename = 'enrolled-students-'.trim($code, '-').'-'.now()->format('Ymd-His').'.xlsx';

        return response()->streamDownload(function () use ($spreadsheet) {
            $writer = new Xlsx($spreadsheet);
            $writer->save('php://output');
            $spreadsheet->disconnectWorksheets();
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $user = $request->user();
        $query = ClassSection::with(['subject', 'schoolTerm', 'teacher'])
            ->withCount('enrollmentSubjects');

        if ($user->role->value === 'teacher') {
            $query->where('teacher_id', $user->id);
        }

        $this->applyListFilters($query, $request);

        $sections = $query
            ->orderBy('school_term_id')
            ->orderBy('subject_id')
            ->orderBy('section')
            ->get();

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Class Sections');

        $headers = [
            '#',
            'Subject Code',
            'Subject Title',
            'Subject Level',
            'Subject Units',
            'School Term',
            'School Year',
            'Section',
            'Schedule Day',
            'Schedule Time',
            'Room',
            'Teacher',
            'Teacher Email',
            'Students Enrolled',
            'Enrollment Status',
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
        $sheet->setCellValue("A{$row}", 'CLASS SECTIONS REPORT');
        $sheet->mergeCells("A{$row}:{$lastCol}{$row}");
        $row++;
        $sheet->setCellValue("A{$row}", $filterLine);
        $sheet->mergeCells("A{$row}:{$lastCol}{$row}");
        $row++;
        $sheet->setCellValue("A{$row}", $sections->count().' section'.($sections->count() === 1 ? '' : 's'));
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
        foreach ($sections as $section) {
            $count = (int) ($section->enrollment_subjects_count ?? 0);
            $sheet->fromArray([
                $num,
                $section->subject?->code,
                $section->subject?->title,
                $this->subjectLevelLabel($section->subject),
                $section->subject?->units !== null ? (float) $section->subject->units : null,
                $section->schoolTerm?->name,
                $section->schoolTerm?->school_year,
                $section->section,
                $section->schedule_day,
                $section->schedule_time,
                $section->room,
                $section->teacher?->name,
                $section->teacher?->email,
                $count,
                $count > 0 ? 'With enrollment' : 'No enrollment yet',
                optional($section->created_at)?->format('Y-m-d H:i:s'),
                optional($section->updated_at)?->format('Y-m-d H:i:s'),
            ], null, 'A'.$row);

            $num++;
            $row++;
        }

        foreach (range(1, count($headers)) as $col) {
            $sheet->getColumnDimension(
                Coordinate::stringFromColumnIndex($col)
            )->setAutoSize(true);
        }

        $filename = 'class-sections-export-'.now()->format('Ymd-His').'.xlsx';

        return response()->streamDownload(function () use ($spreadsheet) {
            $writer = new Xlsx($spreadsheet);
            $writer->save('php://output');
            $spreadsheet->disconnectWorksheets();
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    private function exportFilterSummary(Request $request): string
    {
        $parts = ['Filters: All sections'];

        if ($termId = $request->query('school_term_id')) {
            $term = \App\Models\SchoolTerm::find($termId);
            $parts[] = 'Term: '.($term?->name ?: 'Selected term');
        }

        if ($request->query('enrollment_status') === 'with_students') {
            $parts[] = 'Enrollment: With enrollment';
        } else        if ($request->query('enrollment_status') === 'empty') {
            $parts[] = 'Enrollment: No enrollment yet';
        }

        if ($level = $request->query('academic_level')) {
            if ($level === 'college') {
                $parts[] = 'Level: College';
            } elseif ($level === 'shs') {
                $parts[] = 'Level: Senior High';
            }
        }

        $search = trim((string) $request->query('search', ''));
        if ($search !== '') {
            $parts[] = 'Search: '.$search;
        }

        return implode(' · ', $parts);
    }

    private function subjectLevelLabel(?\App\Models\Subject $subject): string
    {
        if (! $subject) {
            return '—';
        }

        $level = $subject->academic_level;
        $value = $level instanceof \BackedEnum ? $level->value : (string) $level;

        return match ($value) {
            'college' => 'College',
            'shs' => 'Senior High',
            default => strtoupper($value) ?: '—',
        };
    }

    private function formatAdmissionYearLevel(?\App\Models\Admission $admission): string
    {
        if (! $admission || $admission->year_level === null) {
            return '—';
        }

        $year = (int) $admission->year_level;
        if ($year < 1) {
            return '—';
        }

        $programLevel = $admission->program?->academicLevelValue();
        $profileLevel = $admission->studentProfile?->academic_level;
        $profileValue = $profileLevel instanceof \BackedEnum
            ? $profileLevel->value
            : (string) ($profileLevel ?? '');
        $level = $programLevel ?: $profileValue;

        if ($level === 'shs') {
            return 'Grade '.(10 + $year);
        }

        return 'Year '.$year;
    }

    private function scheduleLabel(ClassSection $classSection): ?string
    {
        $parts = array_filter([
            $classSection->schedule_day,
            $classSection->schedule_time,
            $classSection->room,
        ]);

        return $parts ? implode(' · ', $parts) : null;
    }

    private function applyListFilters(Builder $query, Request $request): void
    {
        if ($termId = $request->query('school_term_id')) {
            $query->where('school_term_id', $termId);
        }

        if ($level = $request->query('academic_level')) {
            if (in_array($level, ['college', 'shs'], true)) {
                $query->whereHas('subject', function (Builder $subject) use ($level) {
                    $subject->where('academic_level', $level);
                });
            }
        }

        if ($request->query('enrollment_status') === 'with_students') {
            $query->whereHas('enrollmentSubjects');
        } elseif ($request->query('enrollment_status') === 'empty') {
            $query->whereDoesntHave('enrollmentSubjects');
        }

        $search = trim((string) $request->query('search', ''));
        if ($search === '') {
            return;
        }

        $like = '%'.$search.'%';
        $query->where(function (Builder $q) use ($like) {
            $q->where('section', 'like', $like)
                ->orWhere('room', 'like', $like)
                ->orWhere('schedule_day', 'like', $like)
                ->orWhere('schedule_time', 'like', $like)
                ->orWhereHas('subject', function (Builder $subject) use ($like) {
                    $subject->where('code', 'like', $like)
                        ->orWhere('title', 'like', $like);
                })
                ->orWhereHas('teacher', function (Builder $teacher) use ($like) {
                    $teacher->where('name', 'like', $like);
                })
                ->orWhereHas('schoolTerm', function (Builder $term) use ($like) {
                    $term->where('name', 'like', $like);
                });
        });
    }

    private function listSummary(Request $request, User $user): array
    {
        $query = ClassSection::query();

        if ($user->role->value === 'teacher') {
            $query->where('teacher_id', $user->id);
        }

        if ($termId = $request->query('school_term_id')) {
            $query->where('school_term_id', $termId);
        }

        $total = (clone $query)->count();
        $college = (clone $query)->whereHas('subject', function (Builder $subject) {
            $subject->where('academic_level', 'college');
        })->count();
        $shs = (clone $query)->whereHas('subject', function (Builder $subject) {
            $subject->where('academic_level', 'shs');
        })->count();

        $enrollmentQuery = clone $query;
        if ($level = $request->query('academic_level')) {
            if (in_array($level, ['college', 'shs'], true)) {
                $enrollmentQuery->whereHas('subject', function (Builder $subject) use ($level) {
                    $subject->where('academic_level', $level);
                });
            }
        }

        $scopedTotal = (clone $enrollmentQuery)->count();
        $withStudents = (clone $enrollmentQuery)->whereHas('enrollmentSubjects')->count();

        return [
            'total' => $total,
            'college' => $college,
            'shs' => $shs,
            'with_students' => $withStudents,
            'empty' => max(0, $scopedTotal - $withStudents),
        ];
    }

    private function assertTeacherRole(?int $teacherId): void
    {
        if (! $teacherId) {
            return;
        }

        $teacher = User::find($teacherId);
        if (! $teacher || $teacher->role !== UserRole::Teacher) {
            throw ValidationException::withMessages([
                'teacher_id' => ['The selected teacher is invalid.'],
            ]);
        }
    }
}
