<?php

namespace App\Services;

use App\Models\Account;
use App\Models\AccountType;
use App\Models\Agent;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class AgentService
{
    /**
     * Create an agent together with its two linked accounts
     * (receivable + payable), both office_shared, inside a transaction.
     *
     * @param  array  $data  {name, phone?, country?, city?, balance_currency_id, commission_rate?}
     * @param  User  $user  Acting user
     */
    public function create(array $data, User $user): Agent
    {
        return DB::transaction(function () use ($data, $user) {
            $officeId = $user->office_id;

            $receivableType = AccountType::where('code', 'agent_receivable')->firstOrFail();
            $payableType = AccountType::where('code', 'agent_payable')->firstOrFail();

            $agent = Agent::create([
                'office_id' => $officeId,
                'name' => $data['name'],
                'phone' => $data['phone'] ?? null,
                'country' => $data['country'] ?? null,
                'city' => $data['city'] ?? null,
                'balance_currency_id' => $data['balance_currency_id'],
                'commission_rate' => $data['commission_rate'] ?? 0,
                'logo_url' => $data['logo_url'] ?? null,
                'is_active' => true,
            ]);

            $receivable = Account::create([
                'office_id' => $officeId,
                'account_type_id' => $receivableType->id,
                'currency_id' => $data['balance_currency_id'],
                'name' => $data['name'].' - Receivable',
                'visibility' => 'office_shared',
                'current_balance' => 0,
                'is_active' => true,
            ]);

            $payable = Account::create([
                'office_id' => $officeId,
                'account_type_id' => $payableType->id,
                'currency_id' => $data['balance_currency_id'],
                'name' => $data['name'].' - Payable',
                'visibility' => 'office_shared',
                'current_balance' => 0,
                'is_active' => true,
            ]);

            $agent->receivable_account_id = $receivable->id;
            $agent->payable_account_id = $payable->id;
            $agent->save();

            return $agent;
        });
    }
}
