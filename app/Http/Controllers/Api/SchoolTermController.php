<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SchoolTerm;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SchoolTermController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = SchoolTerm::query();
        $this->applyListFilters($query, $request);

        $wantsPagination = $request->has('page') || $request->has('per_page');
        if (! $wantsPagination) {
            return response()->json(
                $query->orderByDesc('school_year')->orderBy('term_type')->get()
            );
        }

        $perPage = min(100, max(10, (int) $request->query('per_page', 10)));
        $paginator = $query
            ->orderByDesc('school_year')
            ->orderBy('term_type')
            ->paginate($perPage)
            ->appends($request->query());

        return response()->json(array_merge($paginator->toArray(), [
            'summary' => $this->listSummary(),
        ]));
    }

    public function export(Request $request): StreamedResponse
    {
        $query = SchoolTerm::query();
        $this->applyListFilters($query, $request);

        $terms = $query
            ->orderByDesc('school_year')
            ->orderBy('term_type')
            ->get();

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('School Terms');

        $headers = [
            '#',
            'Name',
            'Type',
            'School Year',
            'Active',
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
        $sheet->setCellValue("A{$row}", 'SCHOOL TERMS REPORT');
        $sheet->mergeCells("A{$row}:{$lastCol}{$row}");
        $row++;
        $sheet->setCellValue("A{$row}", $filterLine);
        $sheet->mergeCells("A{$row}:{$lastCol}{$row}");
        $row++;
        $sheet->setCellValue("A{$row}", $terms->count().' term'.($terms->count() === 1 ? '' : 's'));
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
        foreach ($terms as $term) {
            $sheet->fromArray([
                $num,
                $term->name,
                $this->termTypeLabel($term->term_type),
                $term->school_year,
                $term->is_active ? 'Yes' : 'No',
                optional($term->created_at)?->format('Y-m-d H:i:s'),
                optional($term->updated_at)?->format('Y-m-d H:i:s'),
            ], null, 'A'.$row);

            $num++;
            $row++;
        }

        foreach (range(1, count($headers)) as $col) {
            $sheet->getColumnDimension(
                Coordinate::stringFromColumnIndex($col)
            )->setAutoSize(true);
        }

        $filename = 'school-terms-export-'.now()->format('Ymd-His').'.xlsx';

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
        $data = $this->validatedTermData($request);

        $term = SchoolTerm::create($data);

        return response()->json($term, 201);
    }

    public function update(Request $request, SchoolTerm $schoolTerm): JsonResponse
    {
        $data = $this->validatedTermData($request, $schoolTerm);

        $schoolTerm->update($data);

        return response()->json($schoolTerm->fresh());
    }

    public function usage(SchoolTerm $schoolTerm): JsonResponse
    {
        return response()->json($this->usagePayload($schoolTerm));
    }

    public function destroy(SchoolTerm $schoolTerm): JsonResponse
    {
        $usage = $this->usagePayload($schoolTerm);
        if ($usage['in_use']) {
            return response()->json([
                'message' => 'This school term cannot be deleted because admissions or class sections already use it. Set it to inactive instead.',
                'usage' => $usage,
            ], 422);
        }

        $schoolTerm->delete();

        return response()->json(['message' => 'School term deleted.']);
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedTermData(Request $request, ?SchoolTerm $ignore = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'term_type' => ['required', 'in:first_semester,second_semester,summer'],
            'school_year' => ['required', 'string', 'max:32'],
            'is_active' => ['boolean'],
        ]);

        $data['name'] = trim($data['name']);
        $data['school_year'] = trim($data['school_year']);
        $data['is_active'] = $data['is_active'] ?? true;

        $errors = [];

        $typeYearQuery = SchoolTerm::query()
            ->where('term_type', $data['term_type'])
            ->where('school_year', $data['school_year']);
        if ($ignore) {
            $typeYearQuery->where('id', '!=', $ignore->id);
        }
        if ($typeYearQuery->exists()) {
            $errors['term_type'] = ['A school term for this type and school year already exists.'];
        }

        $normalizedName = mb_strtolower($data['name']);
        $nameQuery = SchoolTerm::query()
            ->whereRaw('LOWER(TRIM(name)) = ?', [$normalizedName]);
        if ($ignore) {
            $nameQuery->where('id', '!=', $ignore->id);
        }
        if ($nameQuery->exists()) {
            $errors['name'] = ['A school term with this name already exists.'];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $data;
    }

    private function applyListFilters(Builder $query, Request $request): void
    {
        if ($type = $request->query('term_type')) {
            if (in_array($type, ['first_semester', 'second_semester', 'summer'], true)) {
                $query->where('term_type', $type);
            }
        }

        if ($request->query('status') === 'active') {
            $query->where('is_active', true);
        } elseif ($request->query('status') === 'inactive') {
            $query->where('is_active', false);
        }

        $search = trim((string) $request->query('search', ''));
        if ($search === '') {
            return;
        }

        $like = '%'.$search.'%';
        $query->where(function (Builder $q) use ($like) {
            $q->where('name', 'like', $like)
                ->orWhere('school_year', 'like', $like)
                ->orWhere('term_type', 'like', $like);
        });
    }

    private function listSummary(): array
    {
        $total = SchoolTerm::count();
        $active = SchoolTerm::where('is_active', true)->count();

        return [
            'total' => $total,
            'active' => $active,
            'inactive' => max(0, $total - $active),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function usagePayload(SchoolTerm $schoolTerm): array
    {
        $admissionsCount = $schoolTerm->admissions()->count();
        $classSectionsCount = $schoolTerm->classSections()->count();
        $inUse = $admissionsCount > 0 || $classSectionsCount > 0;

        return [
            'in_use' => $inUse,
            'can_delete' => ! $inUse,
            'admissions_count' => $admissionsCount,
            'class_sections_count' => $classSectionsCount,
        ];
    }

    private function exportFilterSummary(Request $request): string
    {
        $parts = ['Filters: All terms'];

        if ($type = $request->query('term_type')) {
            if (in_array($type, ['first_semester', 'second_semester', 'summer'], true)) {
                $parts[] = 'Type: '.$this->termTypeLabel($type);
            }
        }

        if ($request->query('status') === 'active') {
            $parts[] = 'Status: Active';
        } elseif ($request->query('status') === 'inactive') {
            $parts[] = 'Status: Inactive';
        }

        $search = trim((string) $request->query('search', ''));
        if ($search !== '') {
            $parts[] = 'Search: '.$search;
        }

        return implode(' · ', $parts);
    }

    private function termTypeLabel(?string $value): string
    {
        return match ($value) {
            'first_semester' => 'First Semester',
            'second_semester' => 'Second Semester',
            'summer' => 'Summer',
            default => $value ? str_replace('_', ' ', ucfirst($value)) : '—',
        };
    }
}
