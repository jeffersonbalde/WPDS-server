<?php

namespace App\Http\Controllers\Api;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\ClassSection;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Read-only teacher directory (card view + detail) for Registrar / Admin / Stakeholder / IT oversight.
 */
class TeacherDirectoryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = User::query()->where('role', UserRole::Teacher);
        $this->applyListFilters($query, $request);
        $query->with('staffProfile')->withCount(['classSectionsTaught as class_sections_count']);

        $perPage = min(100, max(10, (int) $request->query('per_page', 10)));
        $paginator = $query->orderBy('name')->paginate($perPage)->appends($request->query());

        $payload = $paginator->toArray();
        $payload['summary'] = [
            'total' => User::query()->where('role', UserRole::Teacher)->count(),
            'active' => User::query()->where('role', UserRole::Teacher)->where('is_active', true)->count(),
            'inactive' => User::query()->where('role', UserRole::Teacher)->where('is_active', false)->count(),
        ];

        return response()->json($payload);
    }

    public function show(User $teacher): JsonResponse
    {
        if ($teacher->role !== UserRole::Teacher) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        $teacher->load('staffProfile');
        $payload = $teacher->toArray();

        $payload['class_sections'] = ClassSection::query()
            ->where('teacher_id', $teacher->id)
            ->with([
                'subject:id,code,title,units,academic_level',
                'schoolTerm:id,name,school_year,term_type',
            ])
            ->withCount('enrollmentSubjects')
            ->orderByDesc('id')
            ->get();

        return response()->json($payload);
    }

    public function export(Request $request): StreamedResponse
    {
        $query = User::query()->where('role', UserRole::Teacher);
        $this->applyListFilters($query, $request);
        $teachers = $query->with('staffProfile')
            ->withCount(['classSectionsTaught as class_sections_count'])
            ->orderBy('name')
            ->get();

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Teachers');

        $headers = [
            'Name',
            'Email',
            'Employee No.',
            'Department',
            'Position',
            'Mobile',
            'Class Sections',
            'Status',
        ];

        $sheet->fromArray($headers, null, 'A1');
        $lastCol = Coordinate::stringFromColumnIndex(count($headers));
        $headerRange = "A1:{$lastCol}1";
        $sheet->getStyle($headerRange)->getFont()->setBold(true);
        $sheet->getStyle($headerRange)->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setRGB('E9ECEF');
        $sheet->getStyle($headerRange)->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);

        $row = 2;
        foreach ($teachers as $teacher) {
            $sheet->fromArray([
                $teacher->name,
                $teacher->email,
                $teacher->staffProfile?->employee_no,
                $teacher->staffProfile?->department,
                $teacher->staffProfile?->position,
                $teacher->staffProfile?->mobile,
                $teacher->class_sections_count,
                $teacher->is_active ? 'Active' : 'Inactive',
            ], null, 'A'.$row);
            $row++;
        }

        foreach (range(1, count($headers)) as $col) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($col))->setAutoSize(true);
        }

        $filename = 'teachers-export-'.now()->format('Ymd-His').'.xlsx';

        return response()->streamDownload(function () use ($spreadsheet) {
            $writer = new Xlsx($spreadsheet);
            $writer->save('php://output');
            $spreadsheet->disconnectWorksheets();
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    private function applyListFilters(Builder $query, Request $request): void
    {
        $status = $request->query('status');
        if ($status === 'active') {
            $query->where('is_active', true);
        } elseif ($status === 'inactive') {
            $query->where('is_active', false);
        }

        if ($search = trim((string) $request->query('search', ''))) {
            $like = '%'.addcslashes($search, '%_\\').'%';
            $query->where(function (Builder $inner) use ($like) {
                $inner->where('name', 'like', $like)
                    ->orWhere('email', 'like', $like)
                    ->orWhereHas('staffProfile', fn (Builder $sq) => $sq->where('employee_no', 'like', $like));
            });
        }
    }
}
