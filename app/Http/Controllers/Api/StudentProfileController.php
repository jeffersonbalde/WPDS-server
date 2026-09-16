<?php

namespace App\Http\Controllers\Api;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class StudentProfileController extends Controller
{
    public function nextNumber(Request $request): JsonResponse
    {
        $data = $request->validate([
            'academic_level' => ['nullable', 'in:shs,college'],
        ]);

        $level = $data['academic_level'] ?? 'college';
        $year = (int) now()->format('Y');
        $prefix = $level === 'shs' ? "SHS-{$year}-" : "{$year}-";

        $latest = StudentProfile::query()
            ->where('student_no', 'like', $prefix.'%')
            ->orderByDesc('id')
            ->value('student_no');

        $sequence = 1;
        if (is_string($latest) && preg_match('/(\d+)$/', $latest, $matches)) {
            $sequence = ((int) $matches[1]) + 1;
        } else {
            // Fallback: count profiles created this year with matching prefix pattern
            $sequence = StudentProfile::query()
                ->where('student_no', 'like', $prefix.'%')
                ->count() + 1;
        }

        $studentNo = $prefix.str_pad((string) $sequence, 4, '0', STR_PAD_LEFT);

        // Ensure uniqueness even if gaps/manual numbers exist
        $guard = 0;
        while (StudentProfile::where('student_no', $studentNo)->exists() && $guard < 1000) {
            $sequence++;
            $studentNo = $prefix.str_pad((string) $sequence, 4, '0', STR_PAD_LEFT);
            $guard++;
        }

        return response()->json([
            'student_no' => $studentNo,
            'academic_level' => $level,
            'year' => $year,
        ]);
    }

    /**
     * Check whether contact email and/or portal login are already taken.
     * Both fields are checked against student_profiles.contact_email and users.email.
     */
    public function checkEmail(Request $request): JsonResponse
    {
        $data = $request->validate([
            'contact_email' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'string', 'max:255'],
            'ignore_student_id' => ['nullable', 'integer', 'exists:student_profiles,id'],
        ]);

        $ignoreStudentId = isset($data['ignore_student_id']) ? (int) $data['ignore_student_id'] : null;
        $ignoreUserId = null;
        if ($ignoreStudentId) {
            $ignoreUserId = StudentProfile::where('id', $ignoreStudentId)->value('user_id');
        }

        $errors = [];

        $contactRaw = isset($data['contact_email']) ? strtolower(trim($data['contact_email'])) : '';
        $loginRaw = isset($data['email']) ? trim($data['email']) : '';
        $loginNormalized = $loginRaw !== '' && str_contains($loginRaw, '@')
            ? strtolower($loginRaw)
            : $loginRaw;
        $sameContactAndPortal = $contactRaw !== '' && $contactRaw === $loginNormalized;

        if (! empty($data['contact_email'])) {
            $contact = $contactRaw;
            if (! filter_var($contact, FILTER_VALIDATE_EMAIL)) {
                $errors['contact_email'] = 'Enter a valid email address.';
            } else {
                $profileTaken = StudentProfile::where('contact_email', $contact)
                    ->when($ignoreStudentId, fn ($q) => $q->where('id', '!=', $ignoreStudentId))
                    ->exists();
                if ($profileTaken) {
                    $errors['contact_email'] = 'This email is already in use.';
                } elseif (! $sameContactAndPortal) {
                    $portalTaken = User::where('email', $contact)
                        ->when($ignoreUserId, fn ($q) => $q->where('id', '!=', $ignoreUserId))
                        ->exists();
                    if ($portalTaken) {
                        $errors['contact_email'] = 'This email is already used as a portal login.';
                    }
                }
            }
        }

        if (! empty($data['email'])) {
            $login = $loginRaw;
            $normalized = $loginNormalized;

            if (str_contains($login, '@')) {
                if (! filter_var($login, FILTER_VALIDATE_EMAIL)) {
                    $errors['email'] = 'Enter a valid email address or username.';
                } else {
                    $userTaken = User::where('email', $normalized)
                        ->when($ignoreUserId, fn ($q) => $q->where('id', '!=', $ignoreUserId))
                        ->exists();
                    if ($userTaken) {
                        $errors['email'] = 'This email or username is already in use.';
                    } elseif (! $sameContactAndPortal) {
                        $asContact = StudentProfile::where('contact_email', $normalized)
                            ->when($ignoreStudentId, fn ($q) => $q->where('id', '!=', $ignoreStudentId))
                            ->exists();
                        if ($asContact) {
                            $errors['email'] = 'This email is already used as a student email.';
                        }
                    }
                }
            } else {
                if (! preg_match('/^[a-zA-Z0-9._-]{3,60}$/', $login)) {
                    $errors['email'] = 'Username must be 3–60 characters (letters, numbers, . _ -).';
                } else {
                    $userTaken = User::where('email', $normalized)
                        ->when($ignoreUserId, fn ($q) => $q->where('id', '!=', $ignoreUserId))
                        ->exists();
                    if ($userTaken) {
                        $errors['email'] = 'This email or username is already in use.';
                    }
                }
            }
        }

        return response()->json([
            'available' => $errors === [],
            'errors' => $errors,
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $picker = $request->boolean('picker');

        $query = $picker
            ? StudentProfile::query()->with(['program:id,code,name,academic_level'])
            : StudentProfile::with(['user', 'program', 'programMajor'])->withCount('admissions');
        $this->applyListFilters($query, $request);

        $perPage = min(100, max(10, (int) $request->query('per_page', 10)));

        $paginator = $query
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->paginate($perPage)
            ->appends($request->query());

        if (! $picker) {
            $paginator->getCollection()->transform(function (StudentProfile $student) {
                $student->setAttribute('can_delete', ((int) $student->admissions_count) === 0);

                return $student;
            });
        }

        $payload = $paginator->toArray();

        if (! $picker) {
            $payload['summary'] = [
                'total' => StudentProfile::query()->count(),
                'college' => StudentProfile::query()->where('academic_level', 'college')->count(),
                'shs' => StudentProfile::query()->where('academic_level', 'shs')->count(),
            ];
        }

        return response()->json($payload);
    }

    public function export(Request $request): StreamedResponse
    {
        $query = StudentProfile::with(['user', 'program', 'programMajor']);
        $this->applyListFilters($query, $request);

        $students = $query
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get();

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Students');

        $headers = [
            'Student No.',
            'Last Name',
            'First Name',
            'Middle Name',
            'Academic Level',
            'Program Code',
            'Program Name',
            'Major Code',
            'Major',
            'Year Level',
            'Section',
            'Portal Login',
            'Portal Status',
            'Contact Email',
            'Date of Birth',
            'Place of Birth',
            'Gender',
            'Civil Status',
            'Address Line 1',
            'Address Line 2',
            'Mobile',
            'Telephone',
            'Ethnic Origin',
            'Religion',
            'Primary School',
            'Junior High School',
            'Senior High School',
            'Transferred From',
            'Father Name',
            'Father Occupation',
            'Father Company',
            'Father Contact',
            'Father Email',
            'Mother Name',
            'Mother Occupation',
            'Mother Company',
            'Mother Contact',
            'Mother Email',
            'Guardian Name',
            'Guardian Occupation',
            'Guardian Company',
            'Guardian Contact',
            'Guardian Email',
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
        foreach ($students as $student) {
            $level = $student->academic_level;
            $levelValue = $level instanceof \BackedEnum ? $level->value : (string) $level;
            $edu = is_array($student->educational_background) ? $student->educational_background : [];
            $parents = is_array($student->parents_guardian) ? $student->parents_guardian : [];
            $father = is_array($parents['father'] ?? null) ? $parents['father'] : [];
            $mother = is_array($parents['mother'] ?? null) ? $parents['mother'] : [];
            $guardian = is_array($parents['guardian'] ?? null) ? $parents['guardian'] : [];

            $sheet->fromArray([
                $student->student_no,
                $student->last_name,
                $student->first_name,
                $student->middle_name,
                strtoupper($levelValue),
                $student->program?->code,
                $student->program?->name,
                $student->programMajor?->code,
                $student->programMajor?->name,
                $student->year_level,
                $student->section,
                $student->user?->email,
                ($student->user?->is_active ?? true) ? 'Active' : 'Inactive',
                $student->contact_email,
                optional($student->date_of_birth)?->format('Y-m-d'),
                $student->place_of_birth,
                $student->gender,
                $student->civil_status,
                $student->address_line_1,
                $student->address_line_2,
                $student->mobile,
                $student->telephone,
                $student->ethnic_origin,
                $student->religion,
                $edu['primary_school'] ?? '',
                $edu['junior_high_school'] ?? '',
                $edu['senior_high_school'] ?? '',
                $edu['transferred_from'] ?? '',
                $father['name'] ?? '',
                $father['occupation'] ?? '',
                $father['company'] ?? '',
                $father['contact'] ?? '',
                $father['email'] ?? '',
                $mother['name'] ?? '',
                $mother['occupation'] ?? '',
                $mother['company'] ?? '',
                $mother['contact'] ?? '',
                $mother['email'] ?? '',
                $guardian['name'] ?? '',
                $guardian['occupation'] ?? '',
                $guardian['company'] ?? '',
                $guardian['contact'] ?? '',
                $guardian['email'] ?? '',
                optional($student->created_at)?->format('Y-m-d H:i:s'),
                optional($student->updated_at)?->format('Y-m-d H:i:s'),
            ], null, 'A'.$rowNum);

            $rowNum++;
        }

        foreach (range(1, count($headers)) as $col) {
            $sheet->getColumnDimension(
                Coordinate::stringFromColumnIndex($col)
            )->setAutoSize(true);
        }

        $filename = 'students-export-'.now()->format('Ymd-His').'.xlsx';

        return response()->streamDownload(function () use ($spreadsheet) {
            $writer = new Xlsx($spreadsheet);
            $writer->save('php://output');
            $spreadsheet->disconnectWorksheets();
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    protected function applyListFilters(Builder $query, Request $request): void
    {
        if ($level = $request->query('academic_level')) {
            if (in_array($level, ['shs', 'college'], true)) {
                $query->where('academic_level', $level);
            }
        }

        if ($request->filled('program_id')) {
            $query->where('program_id', (int) $request->query('program_id'));
        }

        if ($request->filled('status')) {
            $status = strtolower((string) $request->query('status'));
            if ($status === 'active') {
                $query->whereHas('user', fn ($uq) => $uq->where('is_active', true));
            } elseif ($status === 'inactive') {
                $query->whereHas('user', fn ($uq) => $uq->where('is_active', false));
            }
        }

        if ($search = trim((string) $request->query('search', ''))) {
            $query->where(function ($q) use ($search) {
                $q->where('student_no', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%")
                    ->orWhere('first_name', 'like', "%{$search}%")
                    ->orWhere('middle_name', 'like', "%{$search}%")
                    ->orWhere('contact_email', 'like', "%{$search}%")
                    ->orWhereHas('user', function ($uq) use ($search) {
                        $uq->where('email', 'like', "%{$search}%")
                            ->orWhere('name', 'like', "%{$search}%");
                    });
            });
        }
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => [
                'required',
                'string',
                'max:255',
                'unique:users,email',
                function (string $attribute, mixed $value, \Closure $fail) use ($request) {
                    $value = trim((string) $value);
                    if (str_contains($value, '@')) {
                        if (! filter_var($value, FILTER_VALIDATE_EMAIL)) {
                            $fail('Enter a valid email address or username.');

                            return;
                        }
                        $email = strtolower($value);
                        $contact = strtolower(trim((string) $request->input('contact_email', '')));
                        // Allow contact email and portal login to be the same on create.
                        if ($contact !== '' && $contact === $email) {
                            return;
                        }
                        if (StudentProfile::where('contact_email', $email)->exists()) {
                            $fail('This email is already used as a student email.');
                        }

                        return;
                    }
                    if (! preg_match('/^[a-zA-Z0-9._-]{3,60}$/', $value)) {
                        $fail('Username must be 3–60 characters (letters, numbers, . _ -).');
                    }
                },
            ],
            'contact_email' => [
                'required',
                'email',
                'max:255',
                Rule::unique('student_profiles', 'contact_email'),
                function (string $attribute, mixed $value, \Closure $fail) use ($request) {
                    $email = strtolower(trim((string) $value));
                    $portalLogin = trim((string) $request->input('email', ''));
                    $portalNormalized = str_contains($portalLogin, '@')
                        ? strtolower($portalLogin)
                        : $portalLogin;
                    // Same request may use this email as both contact + portal login.
                    if ($portalNormalized !== '' && $portalNormalized === $email) {
                        return;
                    }
                    if (User::where('email', $email)->exists()) {
                        $fail('This email is already used as a portal login.');
                    }
                },
            ],
            'password' => ['required', 'string', 'min:6', 'max:100'],
            'student_no' => ['required', 'string', 'max:50', 'unique:student_profiles,student_no'],
            'academic_level' => ['required', 'in:shs,college'],
            'program_id' => ['nullable', 'exists:programs,id'],
            'year_level' => ['nullable', 'integer', 'min:1', 'max:6'],
            'section' => ['nullable', 'string', 'max:50'],
            'last_name' => ['required', 'string', 'max:100'],
            'first_name' => ['required', 'string', 'max:100'],
            'middle_name' => ['required', 'string', 'max:100'],
            'date_of_birth' => ['required', 'date'],
            'place_of_birth' => ['required', 'string', 'max:255'],
            'gender' => ['required', 'string', 'max:50'],
            'civil_status' => ['required', 'string', 'max:50'],
            'address_line_1' => ['required', 'string', 'max:255'],
            'address_line_2' => ['required', 'string', 'max:255'],
            'mobile' => ['required', 'string', 'max:30'],
            'telephone' => ['required', 'string', 'max:30'],
            'ethnic_origin' => ['required', 'string', 'max:100'],
            'religion' => ['required', 'string', 'max:100'],
            'educational_background' => ['required', 'array'],
            'educational_background.primary_school' => ['required', 'string', 'max:255'],
            'educational_background.junior_high_school' => ['required', 'string', 'max:255'],
            'educational_background.senior_high_school' => ['required', 'string', 'max:255'],
            'educational_background.transferred_from' => ['required', 'string', 'max:255'],
            'parents_guardian' => ['required', 'array'],
            'parents_guardian.father' => ['required', 'array'],
            'parents_guardian.mother' => ['required', 'array'],
            'parents_guardian.guardian' => ['required', 'array'],
            'parents_guardian.*.name' => ['required', 'string', 'max:255'],
            'parents_guardian.*.occupation' => ['required', 'string', 'max:255'],
            'parents_guardian.*.company' => ['required', 'string', 'max:255'],
            'parents_guardian.*.contact' => ['required', 'string', 'max:50'],
            'parents_guardian.*.email' => ['required', 'email', 'max:255'],
        ], [
            'email.required' => 'Email or username is required.',
            'email.unique' => 'This email or username is already in use.',
            'contact_email.required' => 'Email is required.',
            'contact_email.email' => 'Enter a valid email address.',
            'contact_email.unique' => 'This email is already in use.',
            'student_no.required' => 'Student number is required.',
            'student_no.unique' => 'This student number is already in use.',
            'password.required' => 'Password is required.',
            'password.min' => 'Password must be at least 6 characters.',
            'middle_name.required' => 'Middle name is required.',
            'date_of_birth.required' => 'Date of birth is required.',
            'place_of_birth.required' => 'Place of birth is required.',
            'gender.required' => 'Gender is required.',
            'civil_status.required' => 'Civil status is required.',
            'address_line_1.required' => 'Address line 1 is required.',
            'address_line_2.required' => 'Address line 2 is required.',
            'mobile.required' => 'Mobile number is required.',
            'telephone.required' => 'Telephone is required.',
            'ethnic_origin.required' => 'Ethnic origin is required.',
            'religion.required' => 'Religion is required.',
            'educational_background.required' => 'Educational background is required.',
            'educational_background.primary_school.required' => 'Primary school is required.',
            'educational_background.junior_high_school.required' => 'Junior high school is required.',
            'educational_background.senior_high_school.required' => 'Senior high school is required.',
            'educational_background.transferred_from.required' => 'Transferred from is required.',
            'parents_guardian.required' => 'Parents/guardian information is required.',
        ]);

        $plainPassword = $data['password'];

        $profile = DB::transaction(function () use ($data) {
            $name = strtoupper(trim("{$data['last_name']}, {$data['first_name']} ".($data['middle_name'] ?? '')));
            $login = trim($data['email']);
            if (str_contains($login, '@')) {
                $login = strtolower($login);
            }

            $user = User::create([
                'name' => $name,
                'email' => $login,
                'password' => $data['password'],
                'role' => 'student',
                'is_active' => true,
            ]);

            $profileData = collect($data)->except(['email', 'password'])->all();
            $profileData['program_id'] = $data['program_id'] ?? null;
            // year_level is set under Admissions; omit null so DB default applies on SQLite
            if (array_key_exists('year_level', $data) && $data['year_level'] !== null && $data['year_level'] !== '') {
                $profileData['year_level'] = (int) $data['year_level'];
            } else {
                unset($profileData['year_level']);
            }
            $profileData['section'] = isset($data['section']) && trim((string) $data['section']) !== ''
                ? trim($data['section'])
                : null;
            $profileData['last_name'] = strtoupper(trim($data['last_name']));
            $profileData['first_name'] = strtoupper(trim($data['first_name']));
            $profileData['middle_name'] = strtoupper(trim($data['middle_name']));
            $profileData['contact_email'] = strtolower(trim($data['contact_email']));
            $profileData['user_id'] = $user->id;

            return StudentProfile::create($profileData);
        });

        $profile->load(['user', 'program', 'programMajor']);

        return response()->json([
            ...$profile->toArray(),
            'portal_credentials' => [
                'login' => $profile->user->email,
                'password' => $plainPassword,
            ],
        ], 201);
    }

    public function show(StudentProfile $student): JsonResponse
    {
        $student->load([
            'user',
            'program',
            'programMajor',
            'admissions' => fn ($q) => $q->orderByDesc('id'),
            'admissions.schoolTerm',
            'admissions.program',
            'admissions.programMajor',
            'admissions.enrollmentSubjects.classSection.subject',
            'admissions.enrollmentSubjects.classSection.teacher',
            'admissions.enrollmentSubjects.grade',
        ]);

        return response()->json($student);
    }

    public function update(Request $request, StudentProfile $student): JsonResponse
    {
        $data = $request->validate([
            'academic_level' => ['sometimes', 'in:shs,college'],
            'program_id' => ['nullable', 'exists:programs,id'],
            'year_level' => ['nullable', 'integer', 'min:1', 'max:6'],
            'section' => ['nullable', 'string', 'max:50'],
            'last_name' => ['sometimes', 'string', 'max:100'],
            'first_name' => ['sometimes', 'string', 'max:100'],
            'middle_name' => ['nullable', 'string', 'max:100'],
            'date_of_birth' => ['nullable', 'date'],
            'place_of_birth' => ['nullable', 'string', 'max:255'],
            'gender' => ['nullable', 'string', 'max:50'],
            'civil_status' => ['nullable', 'string', 'max:50'],
            'address_line_1' => ['nullable', 'string', 'max:255'],
            'address_line_2' => ['nullable', 'string', 'max:255'],
            'mobile' => ['nullable', 'string', 'max:30'],
            'telephone' => ['nullable', 'string', 'max:30'],
            'contact_email' => [
                'nullable',
                'email',
                'max:255',
                Rule::unique('student_profiles', 'contact_email')->ignore($student->id),
                function (string $attribute, mixed $value, \Closure $fail) use ($student) {
                    if ($value === null || trim((string) $value) === '') {
                        return;
                    }
                    $email = strtolower(trim((string) $value));
                    $portalTaken = User::where('email', $email)
                        ->when($student->user_id, fn ($q) => $q->where('id', '!=', $student->user_id))
                        ->exists();
                    if ($portalTaken) {
                        $fail('This email is already used as a portal login.');
                    }
                },
            ],
            'ethnic_origin' => ['nullable', 'string', 'max:100'],
            'religion' => ['nullable', 'string', 'max:100'],
            'educational_background' => ['nullable', 'array'],
            'educational_background.primary_school' => ['nullable', 'string', 'max:255'],
            'educational_background.junior_high_school' => ['nullable', 'string', 'max:255'],
            'educational_background.senior_high_school' => ['nullable', 'string', 'max:255'],
            'educational_background.transferred_from' => ['nullable', 'string', 'max:255'],
            'parents_guardian' => ['nullable', 'array'],
            'parents_guardian.father' => ['nullable', 'array'],
            'parents_guardian.mother' => ['nullable', 'array'],
            'parents_guardian.guardian' => ['nullable', 'array'],
            'parents_guardian.*.name' => ['nullable', 'string', 'max:255'],
            'parents_guardian.*.occupation' => ['nullable', 'string', 'max:255'],
            'parents_guardian.*.company' => ['nullable', 'string', 'max:255'],
            'parents_guardian.*.contact' => ['nullable', 'string', 'max:50'],
            'parents_guardian.*.email' => ['nullable', 'email', 'max:255'],
        ], [
            'contact_email.email' => 'Enter a valid email address.',
            'contact_email.unique' => 'This email is already in use.',
        ]);

        if (isset($data['contact_email'])) {
            $data['contact_email'] = strtolower(trim($data['contact_email']));
        }

        if (isset($data['last_name'])) {
            $data['last_name'] = strtoupper(trim($data['last_name']));
        }
        if (isset($data['first_name'])) {
            $data['first_name'] = strtoupper(trim($data['first_name']));
        }
        if (array_key_exists('middle_name', $data)) {
            $data['middle_name'] = $data['middle_name']
                ? strtoupper(trim($data['middle_name']))
                : null;
        }

        $student->update($data);

        if (isset($data['last_name']) || isset($data['first_name']) || array_key_exists('middle_name', $data)) {
            $fresh = $student->fresh();
            $student->user->update([
                'name' => $fresh->fullName(),
            ]);
        }

        return response()->json($student->fresh()->load([
            'user',
            'program',
            'programMajor',
            'admissions' => fn ($q) => $q->orderByDesc('id'),
            'admissions.schoolTerm',
            'admissions.program',
            'admissions.programMajor',
            'admissions.enrollmentSubjects.classSection.subject',
        ]));
    }

    public function usage(StudentProfile $student): JsonResponse
    {
        return response()->json($student->deletionUsage());
    }

    public function destroy(StudentProfile $student): JsonResponse
    {
        $usage = $student->deletionUsage();
        if (! $usage['can_delete']) {
            return response()->json([
                'message' => 'This student cannot be deleted because admission or grade records already exist. Use Edit Profile to correct details instead.',
                'usage' => $usage,
            ], 422);
        }

        DB::transaction(function () use ($student) {
            $user = $student->user;
            $student->delete();

            if ($user && $user->hasRole(UserRole::Student)) {
                $user->tokens()->delete();
                $user->delete();
            }
        });

        return response()->json(['message' => 'Student deleted.']);
    }

    public function myProfile(Request $request): JsonResponse
    {
        $profile = $request->user()->studentProfile;

        if (! $profile) {
            return response()->json(['message' => 'No student profile found.'], 404);
        }

        $profile->load(['program', 'programMajor', 'user:id,name,email']);

        return response()->json($profile);
    }

    public function updateMyProfile(Request $request): JsonResponse
    {
        return response()->json([
            'message' => 'Profile details can only be updated by the Registrar.',
        ], 403);
    }
}
