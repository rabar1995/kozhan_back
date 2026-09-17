<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreAgentRequest extends FormRequest
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
            'balance_currency_id' => ['required', 'uuid', 'exists:currencies,id'],
            // Multi-currency support: either a fixed subset of currencies
            // (currency_ids) or "all currencies" (lazy pairs on first use).
            'currency_ids' => ['nullable', 'array'],
            'currency_ids.*' => ['uuid', 'exists:currencies,id'],
            'allow_all_currencies' => ['nullable', 'boolean'],
            'commission_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'logo_url' => ['nullable', 'url', 'max:255'],
        ];
    }
}
