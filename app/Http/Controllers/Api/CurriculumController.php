<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CurriculumItem;
use App\Models\EnrollmentSubject;
use App\Models\Program;
use App\Models\Subject;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CurriculumController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'program_id' => ['required', 'integer', 'exists:programs,id'],
            'year_level' => ['nullable', 'integer', 'min:1', 'max:6'],
            'semester' => ['nullable', 'integer', 'min:1', 'max:3'],
        ]);

        $program = Program::query()->findOrFail($data['program_id']);

        $query = CurriculumItem::query()
            ->with('subject')
            ->where('program_id', $program->id);

        if (! empty($data['year_level'])) {
            $query->where('year_level', (int) $data['year_level']);
        }

        if (! empty($data['semester'])) {
            $query->where('semester', (int) $data['semester']);
        }

        $items = $query
            ->orderBy('year_level')
            ->orderBy('semester')
            ->orderBy('id')
            ->get();

        $allForProgram = CurriculumItem::query()
            ->where('program_id', $program->id)
            ->get(['year_level', 'semester']);

        $summary = [
            'total' => $allForProgram->count(),
            'years' => $allForProgram->pluck('year_level')->unique()->sort()->values()->count(),
            'units' => round((float) CurriculumItem::query()
                ->where('program_id', $program->id)
                ->join('subjects', 'subjects.id', '=', 'curriculum_items.subject_id')
                ->sum('subjects.units'), 2),
        ];

        return response()->json([
            'program' => $program->load([
                'majors' => fn ($q) => $q->orderBy('sort_order')->orderBy('name'),
            ]),
            'summary' => $summary,
            'items' => $items,
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $data = $request->validate([
            'program_id' => ['nullable', 'integer', 'exists:programs,id'],
            'scope' => ['nullable', 'in:all'],
        ]);

        $programs = $this->exportPrograms($data);
        if ($programs->isEmpty()) {
            throw ValidationException::withMessages([
                'program_id' => 'No programs available to export.',
            ]);
        }

        $spreadsheet = new Spreadsheet;
        $spreadsheet->removeSheetByIndex(0);

        if (($data['scope'] ?? null) === 'all') {
            $master = new Worksheet($spreadsheet, 'All Curriculum');
            $spreadsheet->addSheet($master, 0);
            $this->fillMasterCurriculumSheet($master, $programs);
        }

        foreach ($programs->values() as $index => $program) {
            $sheet = new Worksheet($spreadsheet, $this->exportSheetTitle($program->code));
            $spreadsheet->addSheet($sheet, $index + (($data['scope'] ?? null) === 'all' ? 1 : 0));
            $this->fillProgramCurriculumSheet($sheet, $program);
        }

        $spreadsheet->setActiveSheetIndex(0);

        $filename = ($data['scope'] ?? null) === 'all'
            ? 'curriculum-all-programs-'.now()->format('Ymd-His').'.xlsx'
            : 'curriculum-'.strtolower($programs->first()->code).'-'.now()->format('Ymd-His').'.xlsx';

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
            'program_id' => ['required', 'integer', 'exists:programs,id'],
            'subject_id' => ['required', 'integer', 'exists:subjects,id'],
            'year_level' => ['required', 'integer', 'min:1', 'max:6'],
            'semester' => ['required', 'integer', 'min:1', 'max:3'],
        ], [
            'program_id.required' => 'Select a program.',
            'subject_id.required' => 'Select a subject.',
            'year_level.required' => 'Year level is required.',
            'semester.required' => 'Semester is required.',
        ]);

        $program = Program::query()->findOrFail($data['program_id']);
        $subject = Subject::query()->findOrFail($data['subject_id']);

        $programLevel = $program->academicLevelValue();
        $subjectLevel = $subject->academic_level instanceof \BackedEnum
            ? $subject->academic_level->value
            : (string) $subject->academic_level;

        if ($programLevel !== $subjectLevel) {
            throw ValidationException::withMessages([
                'subject_id' => 'This subject belongs to a different academic level than the selected program.',
            ]);
        }

        $exists = CurriculumItem::query()
            ->where('program_id', $data['program_id'])
            ->where('subject_id', $data['subject_id'])
            ->where('year_level', $data['year_level'])
            ->where('semester', $data['semester'])
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'subject_id' => 'This subject is already in the curriculum for that year and semester.',
            ]);
        }

        $item = CurriculumItem::create($data);

        return response()->json($item->load('subject'), 201);
    }

    public function update(Request $request, CurriculumItem $curriculum): JsonResponse
    {
        $data = $request->validate([
            'year_level' => ['sometimes', 'integer', 'min:1', 'max:6'],
            'semester' => ['sometimes', 'integer', 'min:1', 'max:3'],
            'subject_id' => ['sometimes', 'integer', 'exists:subjects,id'],
        ]);

        $year = (int) ($data['year_level'] ?? $curriculum->year_level);
        $semester = (int) ($data['semester'] ?? $curriculum->semester);
        $subjectId = (int) ($data['subject_id'] ?? $curriculum->subject_id);

        if (isset($data['subject_id'])) {
            $program = $curriculum->program()->firstOrFail();
            $subject = Subject::query()->findOrFail($subjectId);
            $programLevel = $program->academicLevelValue();
            $subjectLevel = $subject->academic_level instanceof \BackedEnum
                ? $subject->academic_level->value
                : (string) $subject->academic_level;

            if ($programLevel !== $subjectLevel) {
                throw ValidationException::withMessages([
                    'subject_id' => 'This subject belongs to a different academic level than the selected program.',
                ]);
            }
        }

        $duplicate = CurriculumItem::query()
            ->where('program_id', $curriculum->program_id)
            ->where('subject_id', $subjectId)
            ->where('year_level', $year)
            ->where('semester', $semester)
            ->where('id', '!=', $curriculum->id)
            ->exists();

        if ($duplicate) {
            throw ValidationException::withMessages([
                'subject_id' => 'This subject is already in the curriculum for that year and semester.',
            ]);
        }

        $curriculum->update([
            'year_level' => $year,
            'semester' => $semester,
            'subject_id' => $subjectId,
        ]);

        return response()->json($curriculum->fresh()->load('subject'));
    }

    public function usage(CurriculumItem $curriculum): JsonResponse
    {
        return response()->json($this->usagePayload($curriculum));
    }

    public function destroy(CurriculumItem $curriculum): JsonResponse
    {
        $curriculum->delete();

        return response()->json(['message' => 'Curriculum item removed.']);
    }

    /**
     * @return array<string, mixed>
     */
    private function usagePayload(CurriculumItem $curriculum): array
    {
        $previewLimit = 12;
        $curriculum->load(['subject:id,code,title', 'program:id,code,name']);

        $enrollmentQuery = EnrollmentSubject::query()
            ->whereHas('classSection', fn ($q) => $q->where('subject_id', $curriculum->subject_id))
            ->whereHas('admission', fn ($q) => $q->where('program_id', $curriculum->program_id));

        $enrollmentsTotal = (clone $enrollmentQuery)->count();

        $preview = (clone $enrollmentQuery)
            ->with([
                'admission.studentProfile:id,student_no,last_name,first_name,middle_name',
                'admission.schoolTerm:id,name',
                'classSection:id,section',
            ])
            ->orderByDesc('id')
            ->limit($previewLimit)
            ->get()
            ->map(fn ($row) => [
                'student_no' => $row->admission?->studentProfile?->student_no,
                'name' => $row->admission?->studentProfile?->fullName(),
                'term' => $row->admission?->schoolTerm?->name,
                'section' => $row->classSection?->section,
            ])->values()->all();

        return [
            'program_code' => $curriculum->program?->code,
            'program_name' => $curriculum->program?->name,
            'subject_code' => $curriculum->subject?->code,
            'subject_title' => $curriculum->subject?->title,
            'year_level' => $curriculum->year_level,
            'semester' => $curriculum->semester,
            'has_enrolled_students' => $enrollmentsTotal > 0,
            'enrollments' => [
                'total' => $enrollmentsTotal,
                'preview' => $preview,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return Collection<int, Program>
     */
    private function exportPrograms(array $data): Collection
    {
        if (($data['scope'] ?? null) === 'all') {
            return Program::query()
                ->where('is_active', true)
                ->orderBy('academic_level')
                ->orderBy('code')
                ->get();
        }

        if (! empty($data['program_id'])) {
            return Program::query()->whereKey($data['program_id'])->get();
        }

        return collect();
    }

    private function exportSheetTitle(string $code): string
    {
        $title = preg_replace('/[\[\]\:\*\?\/\\\\]/', '', trim($code)) ?: 'Program';

        return mb_substr($title, 0, 31);
    }

    private function exportInstitutionName(): string
    {
        return (string) config('app.curriculum_institution_name', 'West Prime Horizon Institute, Inc.');
    }

    private function exportSemesterLabel(int $semester): string
    {
        return match ($semester) {
            1 => '1st Semester',
            2 => '2nd Semester',
            3 => 'Summer',
            default => 'Semester '.$semester,
        };
    }

    private function exportLevelLabel(Program $program): string
    {
        return $program->academicLevelValue() === 'shs' ? 'Senior High' : 'College';
    }

    private function exportTrackLabel(mixed $track): string
    {
        $value = is_object($track) && isset($track->value) ? (string) $track->value : (string) ($track ?? '');

        return match ($value) {
            'academic' => 'Academic',
            'tvl' => 'TVL',
            'degree' => 'Degree',
            'associate' => 'Associate',
            default => $value !== '' ? $value : '—',
        };
    }

    /**
     * @return Collection<int, CurriculumItem>
     */
    private function curriculumItemsForProgram(Program $program): Collection
    {
        return CurriculumItem::query()
            ->with('subject')
            ->where('program_id', $program->id)
            ->orderBy('year_level')
            ->orderBy('semester')
            ->orderBy('id')
            ->get();
    }

    private function fillProgramCurriculumSheet(Worksheet $sheet, Program $program): void
    {
        $items = $this->curriculumItemsForProgram($program);
        $lastCol = 'D';
        $row = 1;

        $sheet->setCellValue("A{$row}", $this->exportInstitutionName());
        $sheet->mergeCells("A{$row}:{$lastCol}{$row}");
        $this->styleExportTitle($sheet, "A{$row}:{$lastCol}{$row}", 14);
        $row++;

        $sheet->setCellValue("A{$row}", 'PROGRAM CURRICULUM');
        $sheet->mergeCells("A{$row}:{$lastCol}{$row}");
        $this->styleExportTitle($sheet, "A{$row}:{$lastCol}{$row}", 12);
        $row++;

        $sheet->setCellValue("A{$row}", trim($program->code.' — '.$program->name));
        $sheet->mergeCells("A{$row}:{$lastCol}{$row}");
        $this->styleExportSubtitle($sheet, "A{$row}:{$lastCol}{$row}");
        $row++;

        $totalUnits = round((float) $items->sum(fn ($item) => (float) ($item->subject?->units ?? 0)), 2);
        $yearsUsed = $items->pluck('year_level')->unique()->count();
        $meta = implode('   |   ', array_filter([
            $this->exportLevelLabel($program),
            $this->exportTrackLabel($program->track_type),
            ($program->duration_years ? $program->duration_years.' year'.($program->duration_years === 1 ? '' : 's') : null),
            $items->count().' subject'.($items->count() === 1 ? '' : 's'),
            $yearsUsed.' year level'.($yearsUsed === 1 ? '' : 's').' used',
            $totalUnits.' total units',
        ]));
        $sheet->setCellValue("A{$row}", $meta);
        $sheet->mergeCells("A{$row}:{$lastCol}{$row}");
        $this->styleExportMeta($sheet, "A{$row}:{$lastCol}{$row}");
        $row += 2;

        if ($items->isEmpty()) {
            $sheet->setCellValue("A{$row}", 'No subjects assigned to this program yet.');
            $sheet->mergeCells("A{$row}:{$lastCol}{$row}");
            $this->styleExportMeta($sheet, "A{$row}:{$lastCol}{$row}");

            $this->sizeExportColumns($sheet, 4);
            $sheet->freezePane('A6');

            return;
        }

        $groups = $items->groupBy(fn ($item) => $item->year_level.'-'.$item->semester)->sortKeys();
        $tableStart = $row;

        foreach ($groups as $groupItems) {
            /** @var CurriculumItem $first */
            $first = $groupItems->first();
            $groupUnits = round((float) $groupItems->sum(fn ($item) => (float) ($item->subject?->units ?? 0)), 2);
            $groupLabel = 'Year '.$first->year_level.' · '.$this->exportSemesterLabel((int) $first->semester);
            $groupMeta = $groupItems->count().' subject'.($groupItems->count() === 1 ? '' : 's').' · '.$groupUnits.' units';

            $sheet->setCellValue("A{$row}", $groupLabel.'   ('.$groupMeta.')');
            $sheet->mergeCells("A{$row}:{$lastCol}{$row}");
            $this->styleExportGroupHeader($sheet, "A{$row}:{$lastCol}{$row}");
            $row++;

            $headers = ['#', 'Code', 'Title', 'Units'];
            $sheet->fromArray($headers, null, 'A'.$row);
            $this->styleExportTableHeader($sheet, "A{$row}:{$lastCol}{$row}");
            $row++;

            $num = 1;
            foreach ($groupItems as $item) {
                $sheet->fromArray([
                    $num,
                    $item->subject?->code ?? '—',
                    $item->subject?->title ?? '—',
                    $item->subject?->units !== null ? (float) $item->subject->units : '—',
                ], null, 'A'.$row);
                $this->styleExportTableRow($sheet, "A{$row}:{$lastCol}{$row}", $num % 2 === 0);
                $row++;
                $num++;
            }

            $row++;
        }

        $sheet->setCellValue('A'.$row, 'Summary: '.$items->count().' subjects · '.$totalUnits.' total units');
        $sheet->mergeCells("A{$row}:{$lastCol}{$row}");
        $this->styleExportMeta($sheet, "A{$row}:{$lastCol}{$row}");
        $row++;
        $sheet->setCellValue('A'.$row, 'Generated on '.now()->format('F j, Y g:i A'));
        $sheet->mergeCells("A{$row}:{$lastCol}{$row}");
        $this->styleExportMeta($sheet, "A{$row}:{$lastCol}{$row}");

        $this->styleExportTableBorder($sheet, "A{$tableStart}:{$lastCol}".($row - 2));
        $this->sizeExportColumns($sheet, 4);
        $sheet->freezePane('A6');
    }

    /**
     * @param  Collection<int, Program>  $programs
     */
    private function fillMasterCurriculumSheet(Worksheet $sheet, Collection $programs): void
    {
        $headers = [
            'Program Code',
            'Program Name',
            'Level',
            'Track',
            'Years',
            'Year',
            'Semester',
            '#',
            'Subject Code',
            'Subject Title',
            'Units',
        ];
        $lastCol = Coordinate::stringFromColumnIndex(count($headers));
        $row = 1;

        $sheet->setCellValue('A1', $this->exportInstitutionName().' — Curriculum Master List');
        $sheet->mergeCells("A1:{$lastCol}1");
        $this->styleExportTitle($sheet, "A1:{$lastCol}1", 13);
        $row = 3;

        $sheet->fromArray($headers, null, 'A'.$row);
        $this->styleExportTableHeader($sheet, "A{$row}:{$lastCol}{$row}");
        $row++;

        $tableStart = $row;
        $rowNum = 0;

        foreach ($programs as $program) {
            $items = $this->curriculumItemsForProgram($program);
            if ($items->isEmpty()) {
                $sheet->fromArray([
                    $program->code,
                    $program->name,
                    $this->exportLevelLabel($program),
                    $this->exportTrackLabel($program->track_type),
                    $program->duration_years,
                    '—',
                    '—',
                    '—',
                    '—',
                    'No subjects assigned',
                    '—',
                ], null, 'A'.$row);
                $this->styleExportTableRow($sheet, "A{$row}:{$lastCol}{$row}", $rowNum % 2 === 0);
                $row++;
                $rowNum++;
                continue;
            }

            $idx = 1;
            foreach ($items as $item) {
                $sheet->fromArray([
                    $program->code,
                    $program->name,
                    $this->exportLevelLabel($program),
                    $this->exportTrackLabel($program->track_type),
                    $program->duration_years,
                    $item->year_level,
                    $this->exportSemesterLabel((int) $item->semester),
                    $idx,
                    $item->subject?->code ?? '—',
                    $item->subject?->title ?? '—',
                    $item->subject?->units !== null ? (float) $item->subject->units : '—',
                ], null, 'A'.$row);
                $this->styleExportTableRow($sheet, "A{$row}:{$lastCol}{$row}", $rowNum % 2 === 0);
                $row++;
                $idx++;
                $rowNum++;
            }
        }

        $this->styleExportTableBorder($sheet, "A3:{$lastCol}".($row - 1));
        foreach (range(1, count($headers)) as $col) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($col))->setAutoSize(true);
        }
        $sheet->freezePane('A4');
    }

    private function styleExportTitle(Worksheet $sheet, string $range, int $size): void
    {
        $sheet->getStyle($range)->applyFromArray([
            'font' => ['bold' => true, 'size' => $size, 'color' => ['rgb' => '1E293B']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getRowDimension((int) preg_replace('/\D+/', '', explode(':', $range)[0]))->setRowHeight(22);
    }

    private function styleExportSubtitle(Worksheet $sheet, string $range): void
    {
        $sheet->getStyle($range)->applyFromArray([
            'font' => ['bold' => true, 'size' => 11, 'color' => ['rgb' => '334155']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
    }

    private function styleExportMeta(Worksheet $sheet, string $range): void
    {
        $sheet->getStyle($range)->applyFromArray([
            'font' => ['size' => 10, 'color' => ['rgb' => '64748B']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
    }

    private function styleExportGroupHeader(Worksheet $sheet, string $range): void
    {
        $sheet->getStyle($range)->applyFromArray([
            'font' => ['bold' => true, 'size' => 10, 'color' => ['rgb' => '1E293B']],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => 'E9ECEF'],
            ],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
    }

    private function styleExportTableHeader(Worksheet $sheet, string $range): void
    {
        $sheet->getStyle($range)->applyFromArray([
            'font' => ['bold' => true, 'size' => 10, 'color' => ['rgb' => '212529']],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => 'F8F9FA'],
            ],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
    }

    private function styleExportTableRow(Worksheet $sheet, string $range, bool $alt): void
    {
        $style = [
            'font' => ['size' => 10, 'color' => ['rgb' => '212529']],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
        ];
        if ($alt) {
            $style['fill'] = [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => 'F8F9FA'],
            ];
        }
        $sheet->getStyle($range)->applyFromArray($style);
    }

    private function styleExportTableBorder(Worksheet $sheet, string $range): void
    {
        $sheet->getStyle($range)->applyFromArray([
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                    'color' => ['rgb' => 'CED4DA'],
                ],
            ],
        ]);
    }

    private function sizeExportColumns(Worksheet $sheet, int $count): void
    {
        $widths = [6, 14, 42, 10];
        foreach (range(1, min($count, count($widths))) as $col) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($col))
                ->setWidth($widths[$col - 1]);
        }
    }

    public function forStudent(Request $request): JsonResponse
    {
        $user = $request->user();
        $profile = $user->studentProfile;

        if (! $profile?->program_id) {
            return response()->json([]);
        }

        $items = CurriculumItem::with('subject')
            ->where('program_id', $profile->program_id)
            ->orderBy('year_level')
            ->orderBy('semester')
            ->get();

        $program = Program::with('majors')->find($profile->program_id);
        $profile->load('programMajor');

        return response()->json([
            'program' => $program,
            'program_major' => $profile->programMajor,
            'items' => $items,
        ]);
    }
}
