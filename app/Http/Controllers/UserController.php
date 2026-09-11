<?php

namespace App\Http\Controllers;

use App\Http\Concerns\ApiResponse;
use App\Http\Requests\StoreUserRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserController extends Controller
{
    use ApiResponse;

    /**
     * List the office users (owner only).
     */
    public function index(Request $request): JsonResponse
    {
        $rows = User::where('office_id', $request->user()->office_id)
            ->with('office:id,name')
            ->orderBy('name')
            ->get();

        return $this->ok($rows);
    }

    /**
     * Create an office user with a hashed password (owner only).
     */
    public function store(StoreUserRequest $request): JsonResponse
    {
        $data = $request->validated();

        $user = User::create([
            ...collect($data)->except('password')->all(),
            'password' => $data['password'],
            'office_id' => $request->user()->office_id,
            'is_active' => $data['is_active'] ?? true,
        ]);

        return $this->ok($user->load('office:id,name'), 'User created.', 201);
    }

    /**
     * Update user details (owner only).
     */
    public function update(string $id, StoreUserRequest $request): JsonResponse
    {
        $user = User::where('office_id', $request->user()->office_id)->findOrFail($id);

        $data = $request->validated();

        $user->fill(collect($data)->except('password')->all());

        if (! empty($data['password'])) {
            $user->password = $data['password'];
        }

        $user->save();

        return $this->ok($user->load('office:id,name'), 'User updated.');
    }

    /**
     * Deactivate a user (owner only).
     */
    public function deactivate(string $id, Request $request): JsonResponse
    {
        $user = User::where('office_id', $request->user()->office_id)->findOrFail($id);

        $user->forceFill(['is_active' => false])->save();

        return $this->ok($user, 'User deactivated.');
    }
}
