<?php

namespace App\Http\Controllers\Api;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\StaffProfile;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class UserManagementController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = User::query()->with(['studentProfile', 'staffProfile']);

        if ($role = $request->query('role')) {
            $query->where('role', $role);
        }

        if ($request->filled('is_active')) {
            $active = filter_var($request->query('is_active'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($active !== null) {
                $query->where('is_active', $active);
            }
        }

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        $perPage = min(100, max(10, (int) $request->query('per_page', 50)));

        return response()->json($query->orderBy('name')->paginate($perPage));
    }

    public function store(Request $request): JsonResponse
    {
        $roleValue = $request->input('role');
        if (in_array($roleValue, [UserRole::Student->value, UserRole::Alumni->value], true)) {
            return response()->json([
                'message' => 'Student and alumni accounts are created by the Registrar.',
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
            'employee_no' => ['required', 'string', 'max:50'],
            'department' => ['nullable', 'string', 'max:255'],
            'position' => ['nullable', 'string', 'max:255'],
            'mobile' => ['nullable', 'string', 'max:30'],
        ]);

        $user = DB::transaction(function () use ($data, $request) {
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => $data['password'],
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
}
