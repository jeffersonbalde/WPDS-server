<?php

namespace App\Http\Controllers\Api;

use App\Enums\AcademicLevel;
use App\Http\Controllers\Controller;
use App\Models\Program;
use App\Models\ProgramMajor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProgramController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Program::query()->with($this->majorsEagerLoad());
        $this->applyListFilters($query, $request);
        $query->withCount(['admissions', 'studentProfiles', 'curriculumItems']);

        $query->orderBy('academic_level')->orderBy('code');

        $wantsPagination = $request->has('page') || $request->has('per_page');
        if (! $wantsPagination) {
            return response()->json(
                $query->get()->map(fn (Program $program) => $this->markUsage($program))->values()
            );
        }

        $perPage = min(100, max(10, (int) $request->query('per_page', 10)));
        $paginator = $query
            ->paginate($perPage)
            ->appends($request->query());
        $paginator->getCollection()->transform(fn (Program $program) => $this->markUsage($program));

        return response()->json(array_merge($paginator->toArray(), [
            'summary' => [
                'total' => Program::query()->count(),
                'college' => Program::query()->where('academic_level', AcademicLevel::College)->count(),
                'shs' => Program::query()->where('academic_level', AcademicLevel::Shs)->count(),
            ],
        ]));
    }

    public function export(Request $request): StreamedResponse
    {
        $query = Program::query()->with($this->majorsEagerLoad());
        $this->applyListFilters($query, $request);
        $programs = $query->orderBy('academic_level')->orderBy('code')->get();

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Programs');

        $headers = [
            'Code',
            'Program Name',
            'Majors',
            'Major Codes',
            'Level',
            'Track',
            'Years',
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
        foreach ($programs as $program) {
            $majors = $program->majors ?? collect();
            $majorLabels = $majors
                ->map(fn (ProgramMajor $major) => trim((string) $major->label))
                ->filter()
                ->implode(', ');
            $majorCodes = $majors
                ->map(fn (ProgramMajor $major) => trim((string) $major->code))
                ->filter()
                ->implode(', ');

            $sheet->fromArray([
                $program->code,
                $program->name,
                $majorLabels !== '' ? $majorLabels : '—',
                $majorCodes !== '' ? $majorCodes : '—',
                $this->levelLabel($program),
                $this->trackLabel($program->track_type),
                $program->duration_years,
                $program->is_active ? 'Active' : 'Inactive',
                optional($program->created_at)?->format('Y-m-d H:i:s'),
                optional($program->updated_at)?->format('Y-m-d H:i:s'),
            ], null, 'A'.$rowNum);

            $rowNum++;
        }

        foreach (range(1, count($headers)) as $col) {
            $sheet->getColumnDimension(
                Coordinate::stringFromColumnIndex($col)
            )->setAutoSize(true);
        }

        $filename = 'programs-export-'.now()->format('Ymd-His').'.xlsx';

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
        [$data, $majors] = $this->validatedPayload($request);

        $program = DB::transaction(function () use ($data, $majors) {
            $program = Program::create($data);
            $this->syncMajors($program, $majors ?? []);

            return $program;
        });

        return response()->json($this->present($program), 201);
    }

    public function update(Request $request, Program $program): JsonResponse
    {
        [$data, $majors] = $this->validatedPayload($request, $program);

        $program = DB::transaction(function () use ($program, $data, $majors) {
            if ($data !== []) {
                $program->update($data);
            }
            if ($majors !== null) {
                $this->syncMajors($program, $majors);
            }

            return $program;
        });

        return response()->json($this->present($program));
    }

    public function usage(Program $program): JsonResponse
    {
        return response()->json($this->usagePayload($program));
    }

    public function majorUsage(Program $program, ProgramMajor $major): JsonResponse
    {
        $this->assertMajorBelongsToProgram($program, $major);

        return response()->json($this->majorUsagePayload($major));
    }

    public function destroyMajor(Program $program, ProgramMajor $major): JsonResponse
    {
        $this->assertMajorBelongsToProgram($program, $major);

        $usage = $this->majorUsagePayload($major);
        if ($usage['in_use']) {
            return response()->json([
                'message' => 'This major cannot be deleted because students or admissions already use it.',
                'usage' => $usage,
            ], 422);
        }

        $major->delete();

        return response()->json(['message' => 'Major deleted.']);
    }

    public function destroy(Program $program): JsonResponse
    {
        $usage = $this->usagePayload($program);
        if ($usage['in_use']) {
            return response()->json([
                'message' => 'This program cannot be deleted because students, admissions, or curriculum records already use it. Deactivate it instead.',
                'usage' => $usage,
            ], 422);
        }

        $program->delete();

        return response()->json(['message' => 'Program deleted.']);
    }

    /**
     * @return array<string, mixed>
     */
    private function usagePayload(Program $program): array
    {
        $previewLimit = 12;

        $students = $program->studentProfiles()
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get(['id', 'student_no', 'last_name', 'first_name', 'middle_name', 'year_level', 'section']);

        $admissions = $program->admissions()
            ->with([
                'studentProfile:id,student_no,last_name,first_name,middle_name',
                'schoolTerm:id,name',
            ])
            ->orderByDesc('id')
            ->get();

        $curriculum = $program->curriculumItems()
            ->with('subject:id,code,title')
            ->orderBy('year_level')
            ->orderBy('semester')
            ->get();

        $studentPreview = $students->take($previewLimit)->map(fn ($student) => [
            'student_no' => $student->student_no,
            'name' => $student->fullName(),
            'year_level' => $student->year_level,
            'section' => $student->section,
        ])->values()->all();

        $admissionPreview = $admissions->take($previewLimit)->map(fn ($admission) => [
            'admission_number' => $admission->admission_number,
            'student_no' => $admission->studentProfile?->student_no,
            'name' => $admission->studentProfile?->fullName(),
            'term' => $admission->schoolTerm?->name,
            'status' => $admission->status,
        ])->values()->all();

        $curriculumPreview = $curriculum->take($previewLimit)->map(fn ($item) => [
            'code' => $item->subject?->code,
            'title' => $item->subject?->title,
            'year_level' => $item->year_level,
            'semester' => $item->semester,
        ])->values()->all();

        return [
            'code' => $program->code,
            'name' => $program->name,
            'in_use' => $students->isNotEmpty() || $admissions->isNotEmpty() || $curriculum->isNotEmpty(),
            'can_delete' => $students->isEmpty() && $admissions->isEmpty() && $curriculum->isEmpty(),
            'students' => [
                'total' => $students->count(),
                'preview' => $studentPreview,
            ],
            'admissions' => [
                'total' => $admissions->count(),
                'preview' => $admissionPreview,
            ],
            'curriculum' => [
                'total' => $curriculum->count(),
                'preview' => $curriculumPreview,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function majorUsagePayload(ProgramMajor $major): array
    {
        $previewLimit = 12;

        $students = $major->studentProfiles()
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get(['id', 'student_no', 'last_name', 'first_name', 'middle_name', 'year_level', 'section']);

        $admissions = $major->admissions()
            ->with([
                'studentProfile:id,student_no,last_name,first_name,middle_name',
                'schoolTerm:id,name',
            ])
            ->orderByDesc('id')
            ->get();

        $studentPreview = $students->take($previewLimit)->map(fn ($student) => [
            'student_no' => $student->student_no,
            'name' => $student->fullName(),
            'year_level' => $student->year_level,
            'section' => $student->section,
        ])->values()->all();

        $admissionPreview = $admissions->take($previewLimit)->map(fn ($admission) => [
            'admission_number' => $admission->admission_number,
            'student_no' => $admission->studentProfile?->student_no,
            'name' => $admission->studentProfile?->fullName(),
            'term' => $admission->schoolTerm?->name,
            'status' => $admission->status,
        ])->values()->all();

        return [
            'id' => $major->id,
            'code' => $major->code,
            'name' => $major->name,
            'label' => $major->label,
            'in_use' => $students->isNotEmpty() || $admissions->isNotEmpty(),
            'can_delete' => $students->isEmpty() && $admissions->isEmpty(),
            'students' => [
                'total' => $students->count(),
                'preview' => $studentPreview,
            ],
            'admissions' => [
                'total' => $admissions->count(),
                'preview' => $admissionPreview,
            ],
        ];
    }

    private function assertMajorBelongsToProgram(Program $program, ProgramMajor $major): void
    {
        if ((int) $major->program_id !== (int) $program->id) {
            abort(404);
        }
    }

    private function applyListFilters(Builder $query, Request $request): void
    {
        $level = $request->query('academic_level');
        if (is_string($level) && in_array($level, ['college', 'shs'], true)) {
            $query->where('academic_level', $level);
        }

        $track = $request->query('track_type');
        if (is_string($track) && $track !== '' && $track !== 'all') {
            $query->where('track_type', $track);
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
            $query->where(function (Builder $inner) use ($like) {
                $inner->where('code', 'like', $like)
                    ->orWhere('name', 'like', $like)
                    ->orWhereHas('majors', function (Builder $majors) use ($like) {
                        $majors->where('name', 'like', $like)
                            ->orWhere('code', 'like', $like);
                    });
            });
        }
    }

    /**
     * @return array{0: array<string, mixed>, 1: array<int, array<string, mixed>>|null}
     */
    private function validatedPayload(Request $request, ?Program $program = null): array
    {
        if ($request->exists('code')) {
            $request->merge([
                'code' => strtoupper((string) preg_replace('/\s+/', '', trim((string) $request->input('code')))),
            ]);
        }

        if ($request->exists('name')) {
            $request->merge([
                'name' => $this->normalizeProgramName((string) $request->input('name')),
            ]);
        }

        $level = (string) $request->input(
            'academic_level',
            $program?->academicLevelValue()
        );

        $allowedTracks = $level === 'shs'
            ? ['academic', 'tvl']
            : ['degree', 'associate'];

        $uniqueCode = Rule::unique('programs', 'code');
        $uniqueName = Rule::unique('programs', 'name');
        if ($program) {
            $uniqueCode->ignore($program->id);
            $uniqueName->ignore($program->id);
        }

        $data = $request->validate([
            'code' => [$program ? 'sometimes' : 'required', 'string', 'max:32', 'regex:/^[A-Z0-9][A-Z0-9\-]*$/', $uniqueCode],
            'name' => [$program ? 'sometimes' : 'required', 'string', 'max:255', $uniqueName],
            'academic_level' => [$program ? 'sometimes' : 'required', 'in:shs,college'],
            'track_type' => [$program ? 'sometimes' : 'required', 'string', Rule::in($allowedTracks)],
            'duration_years' => [$program ? 'sometimes' : 'required', 'integer', 'min:1', 'max:6'],
            'is_active' => ['sometimes', 'boolean'],
            'majors' => ['sometimes', 'array'],
            'majors.*.id' => ['nullable', 'integer'],
            'majors.*.name' => ['required_with:majors', 'string', 'max:255'],
            'majors.*.code' => ['nullable', 'string', 'max:32'],
            'majors.*.is_active' => ['sometimes', 'boolean'],
        ], [
            'code.required' => 'Program code is required.',
            'code.unique' => 'This program code is already in use.',
            'code.regex' => 'Use letters, numbers, and hyphens only (e.g. BSIT or BTVTED).',
            'name.required' => 'Program name is required.',
            'name.unique' => 'This program name is already in use.',
            'academic_level.required' => 'Academic level is required.',
            'academic_level.in' => 'Academic level must be College or Senior High.',
            'track_type.required' => 'Track type is required.',
            'track_type.in' => $level === 'shs'
                ? 'Senior High tracks must be Academic or TVL.'
                : 'College program types must be Degree or Associate.',
            'duration_years.required' => 'Duration in years is required.',
            'duration_years.min' => 'Duration must be at least 1 year.',
            'duration_years.max' => 'Duration cannot exceed 6 years.',
            'majors.*.name.required_with' => 'Major name is required.',
        ]);

        if (array_key_exists('name', $data)) {
            $this->assertUniqueProgramName($data['name'], $program?->id);
        }

        if (! $program && ! array_key_exists('is_active', $data)) {
            $data['is_active'] = true;
        }

        if (! $program && ! array_key_exists('duration_years', $data)) {
            $data['duration_years'] = $level === 'shs' ? 2 : 4;
        }

        $majors = null;
        if ($request->exists('majors')) {
            $majors = $this->normalizeMajorRows($data['majors'] ?? [], $program);
        }
        unset($data['majors']);

        return [$data, $majors];
    }

    private function normalizeProgramName(string $name): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $name));
    }

    private function assertUniqueProgramName(string $name, ?int $ignoreId): void
    {
        $exists = Program::query()
            ->when($ignoreId, fn ($query) => $query->where('id', '!=', $ignoreId))
            ->whereRaw('LOWER(TRIM(name)) = ?', [mb_strtolower($name)])
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'name' => 'This program name is already in use.',
            ]);
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function normalizeMajorRows(array $rows, ?Program $program): array
    {
        $normalized = [];
        $seenNames = [];
        $seenCodes = [];

        foreach ($rows as $index => $row) {
            $name = trim((string) ($row['name'] ?? ''));
            if ($name === '') {
                throw ValidationException::withMessages([
                    "majors.$index.name" => 'Major name is required.',
                ]);
            }

            $nameKey = mb_strtolower($name);
            if (isset($seenNames[$nameKey])) {
                throw ValidationException::withMessages([
                    "majors.$index.name" => 'Each major name must be unique in this program.',
                ]);
            }
            $seenNames[$nameKey] = true;

            $codeRaw = strtoupper((string) preg_replace('/\s+/', '', trim((string) ($row['code'] ?? ''))));
            $code = $codeRaw !== '' ? $codeRaw : null;
            if ($code !== null) {
                if (! preg_match('/^[A-Z0-9][A-Z0-9\-]*$/', $code)) {
                    throw ValidationException::withMessages([
                        "majors.$index.code" => 'Use letters, numbers, and hyphens only (e.g. CHS).',
                    ]);
                }
                if (isset($seenCodes[$code])) {
                    throw ValidationException::withMessages([
                        "majors.$index.code" => 'Each major code must be unique in this program.',
                    ]);
                }
                $seenCodes[$code] = true;
            }

            $id = isset($row['id']) ? (int) $row['id'] : null;
            if ($id) {
                $exists = $program
                    ? $program->majors()->where('id', $id)->exists()
                    : false;
                if (! $exists) {
                    throw ValidationException::withMessages([
                        "majors.$index.id" => 'This major does not belong to the program.',
                    ]);
                }
            } else {
                $id = null;
            }

            $normalized[] = [
                'id' => $id,
                'name' => $name,
                'code' => $code,
                'is_active' => array_key_exists('is_active', $row) ? (bool) $row['is_active'] : true,
            ];
        }

        return $normalized;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function syncMajors(Program $program, array $rows): void
    {
        $keepIds = [];

        foreach ($rows as $index => $row) {
            $payload = [
                'name' => $row['name'],
                'code' => $row['code'],
                'is_active' => $row['is_active'],
                'sort_order' => $index,
            ];

            if (! empty($row['id'])) {
                $major = ProgramMajor::query()
                    ->where('program_id', $program->id)
                    ->where('id', $row['id'])
                    ->first();
                if (! $major) {
                    continue;
                }
                $major->update($payload);
                $keepIds[] = $major->id;
            } else {
                $major = $program->majors()->create($payload);
                $keepIds[] = $major->id;
            }
        }

        $removeQuery = ProgramMajor::query()->where('program_id', $program->id);
        if ($keepIds !== []) {
            $removeQuery->whereNotIn('id', $keepIds);
        }
        $toRemove = $removeQuery->get();

        foreach ($toRemove as $major) {
            if ($major->isInUse()) {
                throw ValidationException::withMessages([
                    'majors' => 'Cannot remove "'.$major->name.'" because students or admissions already use it. Deactivate it instead.',
                ]);
            }
            $major->delete();
        }
    }

    /**
     * @return array<int, mixed>
     */
    private function majorsEagerLoad(): array
    {
        return [
            'majors' => fn ($q) => $q->orderBy('sort_order')->orderBy('name'),
        ];
    }

    private function levelLabel(Program $program): string
    {
        return $program->academicLevelValue() === 'shs' ? 'Senior High' : 'College';
    }

    private function trackLabel(mixed $track): string
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

    private function markUsage(Program $program): Program
    {
        $inUse = ((int) $program->admissions_count)
            + ((int) $program->student_profiles_count)
            + ((int) $program->curriculum_items_count) > 0;

        $program->setAttribute('in_use', $inUse);
        $program->makeHidden(['admissions_count', 'student_profiles_count', 'curriculum_items_count']);

        return $program;
    }

    private function present(Program $program): Program
    {
        $fresh = $program->fresh()->load($this->majorsEagerLoad());
        $fresh->loadCount(['admissions', 'studentProfiles', 'curriculumItems']);

        return $this->markUsage($fresh);
    }
}
