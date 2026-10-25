<?php

namespace App\Http\Requests;

use App\Models\Account;
use App\Scopes\VisibilityScope;
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
                $this->officeAccountRule(),
            ],
            'notes' => ['nullable', 'string'],
        ];
    }

    /**
     * The cash/bank account used for an outgoing remittance must belong to
     * the acting user's office and be an Office wallet (not agent/personal/private).
     */
    private function officeAccountRule(): \Closure
    {
        return function ($attribute, $value, $fail) {
            if (! $value) {
                return;
            }

            $account = Account::withoutGlobalScope(VisibilityScope::class)
                ->where('id', $value)
                ->where('office_id', $this->user()->office_id)
                ->first();

            if (! $account) {
                $fail('The selected payment account was not found or is inactive.');
            } elseif ($account->wallet_group !== 'office') {
                $fail('The selected payment account must be an office account.');
            }
        };
    }
}
