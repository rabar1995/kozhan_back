<?php

namespace App\Http\Controllers;

use App\Http\Concerns\ApiResponse;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    use ApiResponse;

    /**
     * Authenticate with email/username + password and issue a Sanctum token.
     */
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        $user = User::where('email', $data['email'])
            ->orWhere('username', $data['email'])
            ->first();

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            return $this->fail('Invalid credentials.', 401);
        }

        if (! $user->is_active) {
            return $this->fail('Account is deactivated.', 403);
        }

        $user->forceFill(['last_login_at' => now()])->save();

        $token = $user->createToken('api')->plainTextToken;

        return $this->ok([
            'user' => $user->load('office'),
            'token' => $token,
        ], 'Logged in.');
    }

    /**
     * Return the authenticated user with the office relation.
     */
    public function me(Request $request): JsonResponse
    {
        return $this->ok($request->user()->load('office'));
    }

    /**
     * Revoke the current access token.
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return $this->ok(null, 'Logged out.');
    }
}
