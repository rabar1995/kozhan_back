<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $userId = $this->route('id');

        return [
            'name' => ['required', 'string', 'max:255'],
            'username' => ['required', 'string', 'max:255', 'unique:users,username'.($userId ? ",{$userId}" : '')],
            // Phone number is the login identifier; username stays accepted as a fallback.
            'phone' => ['required', 'string', 'max:50', 'unique:users,phone'.($userId ? ",{$userId}" : '')],
            'password' => [$userId ? 'nullable' : 'required', 'string', 'min:6'],
            'role' => ['required', 'in:owner,office_manager'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
