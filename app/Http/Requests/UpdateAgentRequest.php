<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateAgentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:255'],
            'country' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:255'],
            // Multi-currency support: edit the handled currency set.
            'currency_ids' => ['nullable', 'array'],
            'currency_ids.*' => ['uuid', 'exists:currencies,id'],
            'allow_all_currencies' => ['nullable', 'boolean'],
            'logo_url' => ['nullable', 'url', 'max:255'],
        ];
    }
}
