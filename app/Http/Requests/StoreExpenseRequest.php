<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreExpenseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'expense_category_id' => ['required', 'uuid', 'exists:expense_categories,id'],
            'paid_from_account_id' => ['required', 'uuid', 'exists:accounts,id'],
            'expense_account_id' => ['nullable', 'uuid', 'exists:accounts,id'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'currency_id' => ['required', 'uuid', 'exists:currencies,id'],
            'description' => ['required', 'string'],
            'expense_date' => ['required', 'date'],
        ];
    }
}
