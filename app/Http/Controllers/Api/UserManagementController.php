<?php

namespace App\Http\Controllers\Api;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\ClassSection;
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
            'avatar' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
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
                'new_values' => ['email' => $user->email, 'role' => $user->role->value],
                'ip_address' => $request->ip(),
            ]);

            return $user;
        });

        return response()->json($user->load(['studentProfile', 'staffProfile']), 201);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'email' => ['sometimes', 'email', Rule::unique('users', 'email')->ignore($user->id)],
            'role' => ['sometimes', Rule::in(array_column(UserRole::cases(), 'value'))],
            'is_active' => ['sometimes', 'boolean'],
            'password' => ['nullable', 'string', 'min:6'],
        ]);

        if (! empty($data['password'])) {
            // User model casts password to hashed
        } else {
            unset($data['password']);
        }

        $old = $user->only(['name', 'email', 'role', 'is_active']);
        $user->update($data);

        AuditLog::create([
            'user_id' => $request->user()->id,
            'action' => 'user.updated',
            'auditable_type' => User::class,
            'auditable_id' => $user->id,
            'old_values' => $old,
            'new_values' => $user->only(['name', 'email', 'role', 'is_active']),
            'ip_address' => $request->ip(),
        ]);

        return response()->json($user->fresh()->load(['studentProfile', 'staffProfile']));
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
            'ip_address' => $request->ip(),
        ]);

        return response()->json(['message' => 'Password reset successfully.']);
    }

    public function updateAvatar(Request $request, User $user): JsonResponse
    {
        $data = $request->validate([
            'avatar' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
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
                'ip_address' => $request->ip(),
            ]);
        }

        return response()->json($user->fresh()->load(['studentProfile', 'staffProfile']));
    }
}
