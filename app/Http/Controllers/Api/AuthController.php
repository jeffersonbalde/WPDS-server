<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string'],
        ]);

        $user = User::where('email', $credentials['email'])->first();

        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            Audit::write($request, 'auth.login_failed', null, null, [
                'login' => $credentials['email'],
            ], null);

            throw ValidationException::withMessages([
                'email' => ['These credentials do not match our records.'],
            ]);
        }

        if (! $user->is_active) {
            Audit::write($request, 'auth.login_blocked', $user, null, [
                'login' => $user->email,
                'reason' => 'deactivated',
            ], $user->id);

            return response()->json(['message' => 'Account is deactivated.'], 403);
        }

        $token = $user->createToken('wpds-portal')->plainTextToken;

        Audit::write($request, 'auth.login', $user, null, [
            'login' => $user->email,
            'role' => $user->role instanceof \BackedEnum ? $user->role->value : (string) $user->role,
        ], $user->id);

        return response()->json([
            'token' => $token,
            'user' => $this->userPayload($user),
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user()->load(['studentProfile.program', 'staffProfile']);

        return response()->json(['user' => $this->userPayload($user)]);
    }

    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();

        Audit::write($request, 'auth.logout', $user, null, [
            'login' => $user->email,
            'role' => $user->role instanceof \BackedEnum ? $user->role->value : (string) $user->role,
        ], $user->id);

        $token = $user->currentAccessToken();
        if ($token) {
            $token->delete();
        }

        return response()->json(['message' => 'Logged out.']);
    }

    private function userPayload(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role->value,
            'role_label' => $user->role->label(),
            'is_active' => $user->is_active,
            'avatar_url' => $user->avatar_url,
            'created_at' => $user->created_at,
            'updated_at' => $user->updated_at,
            'student_profile' => $user->studentProfile,
            'staff_profile' => $user->staffProfile,
        ];
    }
}
