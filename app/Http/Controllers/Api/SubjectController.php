<?php

namespace App\Http\Controllers\Api;

use App\Enums\AcademicLevel;
use App\Http\Controllers\Controller;
use App\Models\CurriculumItem;
use App\Models\EnrollmentSubject;
use App\Models\Subject;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SubjectController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Subject::query();
        $this->applyListFilters($query, $request);
        $query->withCount(['curriculumItems', 'classSections'])
            ->orderBy('code');

        $wantsPagination = $request->has('page') || $request->has('per_page');
        if (! $wantsPagination) {
            return response()->json(
                $query->get()->map(fn (Subject $subject) => $this->markUsage($subject))->values()
            );
        }

        $perPage = min(100, max(10, (int) $request->query('per_page', 10)));

        $paginator = $query
            ->paginate($perPage)
            ->appends($request->query());
        $paginator->getCollection()->transform(fn (Subject $subject) => $this->markUsage($subject));

        return response()->json(array_merge($paginator->toArray(), [
            'summary' => $this->catalogSummary(),
        ]));
    }

    public function export(Request $request): StreamedResponse
    {
        $query = Subject::query();
        $this->applyListFilters($query, $request);
        $subjects = $query->orderBy('academic_level')->orderBy('code')->get();

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Subjects');

        $headers = [
            'Code',
            'Title',
            'Units',
            'Level',
            'Status',
            'Record Created',
            'Record Updated',
        ];

        $sheet->fromArray($headers, null, 'A1');

        $lastCol = Coordinate::stringFromColumnIndex(count($headers));
        $headerRange = "A1:{$lastCol}1";
        $sheet->getStyle($headerRange)->getFont()->setBold(true);
        $sheet->getStyle($headerRange)->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setRGB('E9ECEF');
        $sheet->getStyle($headerRange)->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);

        $rowNum = 2;
        foreach ($subjects as $subject) {
            $sheet->fromArray([
                $subject->code,
                $subject->title,
                $subject->units !== null ? (float) $subject->units : '—',
                $this->levelLabel($subject),
                $subject->is_active ? 'Active' : 'Inactive',
                optional($subject->created_at)?->format('Y-m-d H:i:s'),
                optional($subject->updated_at)?->format('Y-m-d H:i:s'),
            ], null, 'A'.$rowNum);

            $rowNum++;
        }

        foreach (range(1, count($headers)) as $col) {
            $sheet->getColumnDimension(
                Coordinate::stringFromColumnIndex($col)
            )->setAutoSize(true);
        }

        $filename = 'subjects-export-'.now()->format('Ymd-His').'.xlsx';

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
            'code' => ['required', 'string', 'max:32'],
            'title' => ['required', 'string'],
            'units' => ['nullable', 'numeric', 'min:0'],
            'academic_level' => ['required', 'in:shs,college'],
            'is_active' => ['boolean'],
        ]);

        $subject = Subject::create($data);

        return response()->json($this->markUsage($subject), 201);
    }

    public function update(Request $request, Subject $subject): JsonResponse
    {
        $data = $request->validate([
            'code' => ['sometimes', 'string', 'max:32'],
            'title' => ['sometimes', 'string'],
            'units' => ['nullable', 'numeric', 'min:0'],
            'academic_level' => ['sometimes', 'in:shs,college'],
            'is_active' => ['boolean'],
        ]);

        $subject->update($data);

        return response()->json($this->markUsage($subject->fresh()));
    }

    public function usage(Subject $subject): JsonResponse
    {
        return response()->json($this->usagePayload($subject));
    }

    public function destroy(Subject $subject): JsonResponse
    {
        $usage = $this->usagePayload($subject);
        if ($usage['in_use']) {
            return response()->json([
                'message' => 'This subject cannot be deleted because curriculum, class sections, or student enrollments already use it. Deactivate it instead.',
                'usage' => $usage,
            ], 422);
        }

        $subject->delete();

        return response()->json(['message' => 'Subject deleted.']);
    }

    private function applyListFilters(Builder $query, Request $request): void
    {
        $level = $request->query('academic_level');
        if (is_string($level) && in_array($level, ['shs', 'college'], true)) {
            $query->where('academic_level', $level);
        }

        $status = $request->query('status');
        if ($status === 'active') {
            $query->where('is_active', true);
        } elseif ($status === 'inactive') {
            $query->where('is_active', false);
        }

        $search = trim((string) $request->query('search', ''));
        if ($search !== '') {
            $like = '%'.addcslashes($search, '%_\\').'%';
            $query->where(function ($inner) use ($like) {
                $inner->where('code', 'like', $like)
                    ->orWhere('title', 'like', $like);
            });
        }
    }

    /**
     * @return array<string, int>
     */
    private function catalogSummary(): array
    {
        return [
            'total' => Subject::query()->count(),
            'college' => Subject::query()->where('academic_level', AcademicLevel::College)->count(),
            'shs' => Subject::query()->where('academic_level', AcademicLevel::Shs)->count(),
            'active' => Subject::query()->where('is_active', true)->count(),
        ];
    }

    private function levelLabel(Subject $subject): string
    {
        $value = $subject->academic_level instanceof \BackedEnum
            ? $subject->academic_level->value
            : (string) ($subject->academic_level ?? '');

        return $value === 'shs' ? 'Senior High' : 'College';
    }

    /**
     * @return array<string, mixed>
     */
    private function usagePayload(Subject $subject): array
    {
        $previewLimit = 12;

        $curriculum = $subject->curriculumItems()
            ->with('program:id,code,name')
            ->orderBy('program_id')
            ->orderBy('year_level')
            ->orderBy('semester')
            ->get();

        $classSections = $subject->classSections()
            ->with('schoolTerm:id,name')
            ->withCount('enrollmentSubjects')
            ->orderByDesc('id')
            ->get();

        $enrollments = EnrollmentSubject::query()
            ->whereHas('classSection', fn ($q) => $q->where('subject_id', $subject->id))
            ->with([
                'admission.studentProfile:id,student_no,last_name,first_name,middle_name',
                'admission.schoolTerm:id,name',
                'classSection:id,section',
            ])
            ->orderByDesc('id')
            ->get();

        $curriculumPreview = $curriculum->take($previewLimit)->map(fn ($item) => [
            'code' => $subject->code,
            'title' => $subject->title,
            'program' => $item->program?->code,
            'year_level' => $item->year_level,
            'semester' => $item->semester,
        ])->values()->all();

        $sectionPreview = $classSections->take($previewLimit)->map(fn ($section) => [
            'term' => $section->schoolTerm?->name,
            'section' => $section->section,
            'enrolled' => (int) ($section->enrollment_subjects_count ?? 0),
        ])->values()->all();

        $enrollmentPreview = $enrollments->take($previewLimit)->map(fn ($row) => [
            'student_no' => $row->admission?->studentProfile?->student_no,
            'name' => $row->admission?->studentProfile?->fullName(),
            'term' => $row->admission?->schoolTerm?->name,
            'section' => $row->classSection?->section,
        ])->values()->all();

        $hasCurriculum = $curriculum->isNotEmpty();
        $hasSections = $classSections->isNotEmpty();
        $hasEnrollments = $enrollments->isNotEmpty();

        return [
            'code' => $subject->code,
            'title' => $subject->title,
            'in_use' => $hasCurriculum || $hasSections || $hasEnrollments,
            'can_delete' => ! $hasCurriculum && ! $hasSections && ! $hasEnrollments,
            'curriculum' => [
                'total' => $curriculum->count(),
                'preview' => $curriculumPreview,
            ],
            'class_sections' => [
                'total' => $classSections->count(),
                'preview' => $sectionPreview,
            ],
            'enrollments' => [
                'total' => $enrollments->count(),
                'preview' => $enrollmentPreview,
            ],
        ];
    }

    private function markUsage(Subject $subject): Subject
    {
        $curriculumCount = (int) ($subject->curriculum_items_count ?? $subject->curriculumItems()->count());
        $sectionCount = (int) ($subject->class_sections_count ?? $subject->classSections()->count());
        $subject->setAttribute('in_use', $curriculumCount > 0 || $sectionCount > 0);
        $subject->makeHidden(['curriculum_items_count', 'class_sections_count']);

        return $subject;
    }
}
