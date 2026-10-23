<?php

namespace App\Http\Controllers\Api;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\ClassSection;
use App\Models\GradeChangeRequest;
use App\Models\StaffProfile;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class UserManagementController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = User::query()->with(['studentProfile', 'staffProfile']);

        $role = $request->query('role');
        if (is_string($role) && $role !== '' && $role !== 'all') {
            $allowed = array_column(UserRole::cases(), 'value');
            if (in_array($role, $allowed, true)) {
                $query->where('role', $role);
            }
        }

        if ($request->filled('is_active')) {
            $active = filter_var($request->query('is_active'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($active !== null) {
                $query->where('is_active', $active);
            }
        } elseif ($request->filled('status')) {
            $status = strtolower((string) $request->query('status'));
            if ($status === 'active') {
                $query->where('is_active', true);
            } elseif ($status === 'inactive') {
                $query->where('is_active', false);
            }
        }

        if ($search = trim((string) $request->query('search', ''))) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        $perPage = min(100, max(10, (int) $request->query('per_page', 10)));

        $paginator = $query->orderBy('name')->paginate($perPage)->appends($request->query());
        $payload = $paginator->toArray();
        $payload['summary'] = [
            'total' => User::query()->count(),
            'active' => User::query()->where('is_active', true)->count(),
            'inactive' => User::query()->where('is_active', false)->count(),
            'students' => User::query()->where('role', UserRole::Student)->count(),
        ];

        return response()->json($payload);
    }

    public function show(User $user): JsonResponse
    {
        $user->load(['studentProfile', 'staffProfile']);

        $payload = $user->toArray();

        if ($user->role === UserRole::Teacher) {
            $payload['class_sections'] = ClassSection::query()
                ->where('teacher_id', $user->id)
                ->with([
                    'subject:id,code,title,units,academic_level',
                    'schoolTerm:id,name,school_year,term_type',
                ])
                ->withCount('enrollmentSubjects')
                ->orderByDesc('id')
                ->get();
        } else {
            $payload['class_sections'] = [];
        }

        return response()->json($payload);
    }

    public function store(Request $request): JsonResponse
    {
        $roleValue = $request->input('role');
        if ($roleValue === UserRole::Student->value) {
            return response()->json([
                'message' => 'Student accounts are created by the Registrar.',
            ], 422);
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'string',
                'max:255',
                'unique:users,email',
                function (string $attribute, mixed $value, \Closure $fail) {
                    $value = trim((string) $value);
                    if (str_contains($value, '@')) {
                        if (! filter_var($value, FILTER_VALIDATE_EMAIL)) {
                            $fail('Enter a valid email address or username.');
                        }

                        return;
                    }
                    if (! preg_match('/^[a-zA-Z0-9._-]{3,60}$/', $value)) {
                        $fail('Username must be 3–60 characters (letters, numbers, . _ -).');
                    }
                },
            ],
            'password' => ['required', 'string', 'min:6'],
            'role' => ['required', Rule::in([
                UserRole::Teacher->value,
                UserRole::Registrar->value,
                UserRole::Admin->value,
                UserRole::It->value,
                UserRole::Stakeholder->value,
            ])],
            'employee_no' => [
                'nullable', 'string', 'max:50',
                Rule::unique('staff_profiles', 'employee_no'),
            ],
            'department' => ['nullable', 'string', 'max:255'],
            'position' => ['nullable', 'string', 'max:255'],
            'mobile' => ['nullable', 'string', 'max:30'],
            'avatar' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:20480'],
        ]);

        $avatarPath = $request->hasFile('avatar')
            ? $request->file('avatar')->store('avatars', 'public')
            : null;

        $user = DB::transaction(function () use ($data, $request, $avatarPath) {
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => $data['password'],
                'avatar_path' => $avatarPath,
                'role' => $data['role'],
                'is_active' => true,
            ]);

            $role = UserRole::from($data['role']);

            StaffProfile::create([
                'user_id' => $user->id,
                'employee_no' => $data['employee_no'] ?? null,
                'department' => $data['department'] ?? 'West Prime Horizon Institute',
                'position' => $data['position'] ?? $role->label(),
                'mobile' => $data['mobile'] ?? null,
            ]);

            AuditLog::create([
                'user_id' => $request->user()->id,
                'action' => 'user.created',
                'auditable_type' => User::class,
                'auditable_id' => $user->id,
                'new_values' => [
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->role->value,
                ],
                'ip_address' => $request->ip(),
            ]);

            return $user;
        });

        return response()->json($user->load(['studentProfile', 'staffProfile']), 201);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $user->load('staffProfile');

        $loginRule = [
            'sometimes',
            'required',
            'string',
            'max:255',
            Rule::unique('users', 'email')->ignore($user->id),
            function (string $attribute, mixed $value, \Closure $fail) {
                $value = trim((string) $value);
                if (str_contains($value, '@')) {
                    if (! filter_var($value, FILTER_VALIDATE_EMAIL)) {
                        $fail('Enter a valid email address or username.');
                    }

                    return;
                }
                if (! preg_match('/^[a-zA-Z0-9._-]{3,60}$/', $value)) {
                    $fail('Username must be 3–60 characters (letters, numbers, . _ -).');
                }
            },
        ];

        $staffRoleValues = [
            UserRole::Teacher->value,
            UserRole::Registrar->value,
            UserRole::Admin->value,
            UserRole::It->value,
            UserRole::Stakeholder->value,
        ];

        if ($user->role === UserRole::Student) {
            $data = $request->validate([
                'name' => ['sometimes', 'required', 'string', 'max:255'],
                'email' => $loginRule,
                'is_active' => ['sometimes', 'boolean'],
                'password' => ['nullable', 'string', 'min:6'],
            ]);

            if ($request->exists('role')) {
                return response()->json([
                    'message' => 'Student role cannot be changed here. Use Registrar student records for academic profile edits.',
                ], 422);
            }
        } else {
            $data = $request->validate([
                'name' => ['sometimes', 'required', 'string', 'max:255'],
                'email' => $loginRule,
                'role' => ['sometimes', 'required', Rule::in($staffRoleValues)],
                'is_active' => ['sometimes', 'boolean'],
                'password' => ['nullable', 'string', 'min:6'],
                'employee_no' => [
                    'nullable', 'string', 'max:50',
                    Rule::unique('staff_profiles', 'employee_no')->ignore($user->staffProfile?->id),
                ],
                'department' => ['nullable', 'string', 'max:255'],
                'position' => ['nullable', 'string', 'max:255'],
                'mobile' => ['nullable', 'string', 'max:30'],
            ]);
        }

        if ($request->user()->id === $user->id) {
            if (array_key_exists('is_active', $data) && ! $data['is_active']) {
                return response()->json([
                    'message' => 'You cannot deactivate your own account.',
                ], 422);
            }
            if (array_key_exists('role', $data) && $data['role'] !== $user->role->value) {
                return response()->json([
                    'message' => 'You cannot change your own role.',
                ], 422);
            }
        }

        if (empty($data['password'])) {
            unset($data['password']);
        }

        $staffFields = ['employee_no', 'department', 'position', 'mobile'];
        $staffData = [];
        foreach ($staffFields as $field) {
            if (array_key_exists($field, $data)) {
                $staffData[$field] = $data[$field];
                unset($data[$field]);
            }
        }

        $old = $user->only(['name', 'email', 'role', 'is_active']);
        $oldStaff = $user->staffProfile?->only($staffFields);

        DB::transaction(function () use ($user, $data, $staffData) {
            if ($data !== []) {
                $user->update($data);
            }

            if ($staffData !== [] && $user->role !== UserRole::Student) {
                $profile = $user->staffProfile;
                if ($profile) {
                    $profile->update($staffData);
                } else {
                    StaffProfile::create(array_merge([
                        'user_id' => $user->id,
                        'department' => 'West Prime Horizon Institute',
                        'position' => $user->fresh()->role->label(),
                    ], $staffData));
                }
            }
        });

        $user->refresh()->load(['studentProfile', 'staffProfile']);

        AuditLog::create([
            'user_id' => $request->user()->id,
            'action' => 'user.updated',
            'auditable_type' => User::class,
            'auditable_id' => $user->id,
            'old_values' => array_filter([
                ...$old,
                'staff_profile' => $oldStaff,
            ]),
            'new_values' => array_filter([
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role instanceof \BackedEnum ? $user->role->value : (string) $user->role,
                'is_active' => $user->is_active,
                'staff_profile' => $user->staffProfile?->only($staffFields),
            ]),
            'ip_address' => $request->ip(),
        ]);

        return response()->json($user);
    }

    /**
     * Permanently delete an account. Accounts that own academic records are refused so
     * no class, grade, or student history is lost; deactivate those instead.
     */
    public function destroy(Request $request, User $user): JsonResponse
    {
        if ($request->user()->id === $user->id) {
            return response()->json(['message' => 'You cannot delete your own account.'], 422);
        }

        $profile = null;

        if ($user->role === UserRole::Student) {
            $profile = $user->studentProfile;
            if ($profile && ! $profile->deletionUsage()['can_delete']) {
                return response()->json([
                    'message' => 'This student has admission or grade records and cannot be deleted. Deactivate the account instead.',
                ], 422);
            }
        } else {
            $classes = ClassSection::where('teacher_id', $user->id)->count();
            $changeRequests = GradeChangeRequest::where('requested_by', $user->id)->count();
            if ($classes > 0 || $changeRequests > 0) {
                return response()->json([
                    'message' => "This account is linked to {$classes} class(es) and {$changeRequests} grade change request(s) and cannot be deleted. Reassign them or deactivate the account instead.",
                ], 422);
            }
        }

        $snapshot = [
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role->value,
        ];

        DB::transaction(function () use ($user, $profile) {
            if ($user->getRawOriginal('avatar_path')) {
                Storage::disk('public')->delete($user->getRawOriginal('avatar_path'));
            }

            DB::table('notifications')
                ->where('notifiable_type', $user->getMorphClass())
                ->where('notifiable_id', $user->id)
                ->delete();

            $user->tokens()->delete();
            $profile?->delete();
            $user->delete();
        });

        AuditLog::create([
            'user_id' => $request->user()->id,
            'action' => 'user.deleted',
            'auditable_type' => User::class,
            'auditable_id' => null,
            'old_values' => $snapshot,
            'ip_address' => $request->ip(),
        ]);

        return response()->json(['message' => 'User deleted.']);
    }

    public function resetPassword(Request $request, User $user): JsonResponse
    {
        $data = $request->validate([
            'password' => ['required', 'string', 'min:6'],
        ]);

        $user->update(['password' => $data['password']]);

        AuditLog::create([
            'user_id' => $request->user()->id,
            'action' => 'user.password_reset',
            'auditable_type' => User::class,
            'auditable_id' => $user->id,
            'new_values' => [
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role->value,
            ],
            'ip_address' => $request->ip(),
        ]);

        return response()->json(['message' => 'Password reset successfully.']);
    }

    public function updateAvatar(Request $request, User $user): JsonResponse
    {
        $data = $request->validate([
            'avatar' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:20480'],
        ]);

        $oldPath = $user->getRawOriginal('avatar_path');
        $newPath = $data['avatar']->store('avatars', 'public');

        $user->update(['avatar_path' => $newPath]);

        if ($oldPath) {
            Storage::disk('public')->delete($oldPath);
        }

        AuditLog::create([
            'user_id' => $request->user()->id,
            'action' => 'user.avatar_updated',
            'auditable_type' => User::class,
            'auditable_id' => $user->id,
            'new_values' => [
                'name' => $user->name,
                'email' => $user->email,
            ],
            'ip_address' => $request->ip(),
        ]);

        return response()->json($user->fresh()->load(['studentProfile', 'staffProfile']));
    }

    public function destroyAvatar(Request $request, User $user): JsonResponse
    {
        $oldPath = $user->getRawOriginal('avatar_path');

        if ($oldPath) {
            Storage::disk('public')->delete($oldPath);
            $user->update(['avatar_path' => null]);

            AuditLog::create([
                'user_id' => $request->user()->id,
                'action' => 'user.avatar_removed',
                'auditable_type' => User::class,
                'auditable_id' => $user->id,
                'new_values' => [
                    'name' => $user->name,
                    'email' => $user->email,
                ],
                'ip_address' => $request->ip(),
            ]);
        }

        return response()->json($user->fresh()->load(['studentProfile', 'staffProfile']));
    }
}
