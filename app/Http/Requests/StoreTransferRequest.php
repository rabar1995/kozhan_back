<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreTransferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'from_account_id' => ['required', 'uuid', 'exists:accounts,id', 'different:to_account_id'],
            'to_account_id' => ['required', 'uuid', 'exists:accounts,id'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'currency_id' => ['required', 'uuid', 'exists:currencies,id'],
            'description' => ['nullable', 'string'],
        ];
    }
}
