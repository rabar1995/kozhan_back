<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreRemittanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $isOutgoing = $this->route()->getName() === 'remittances.outgoing';

        return [
            'agent_id' => ['required', 'uuid', 'exists:agents,id'],
            'sender_name' => ['required', 'string', 'max:255'],
            'sender_phone' => ['nullable', 'string', 'max:255'],
            'receiver_name' => ['required', 'string', 'max:255'],
            'receiver_phone' => ['nullable', 'string', 'max:255'],
            'send_amount' => ['required', 'numeric', 'min:0.01'],
            'send_currency_id' => ['required', 'uuid', 'exists:currencies,id'],
            'receive_amount' => ['required', 'numeric', 'min:0.01'],
            'receive_currency_id' => ['required', 'uuid', 'exists:currencies,id'],
            'commission_amount' => ['nullable', 'numeric', 'min:0'],
            'commission_currency_id' => ['nullable', 'uuid', 'exists:currencies,id'],
            'commission_type' => ['required', 'in:earned,paid'],
            'payment_account_id' => [
                $isOutgoing ? 'required' : 'nullable',
                'uuid',
                'exists:accounts,id',
            ],
            'notes' => ['nullable', 'string'],
        ];
    }
}
