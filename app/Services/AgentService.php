<?php

namespace App\Services;

use App\Models\Account;
use App\Models\AccountType;
use App\Models\Agent;
use App\Models\AgentCurrencyAccount;
use App\Models\Currency;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

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
            $agent->allow_all_currencies = $data['allow_all_currencies'] ?? false;
            $agent->save();

            // Primary currency pair on the legacy columns...
            $agent->currencyAccounts()->create([
                'currency_id' => $data['balance_currency_id'],
                'receivable_account_id' => $receivable->id,
                'payable_account_id' => $payable->id,
            ]);

            // ...plus additional selected currencies (receivable/payable
            // pair per currency), so the agent can hold balances in any
            // set of currencies — "any, some, or all" via all_currencies.
            $extra = array_values(array_unique(array_diff($data['currency_ids'] ?? [], [$data['balance_currency_id']])));
            foreach ($extra as $currencyId) {
                $this->createPair($agent, $currencyId, $receivableType->id, $payableType->id);
            }

            return $agent;
        });
    }

    /**
     * Update the agent's handled currencies.
     * - allow_all_currencies: agents take any currency; pairs are created
     *   lazily on first use by the resolver.
     * - currency_ids: fixed set of currencies — removing a currency with a
     *   non-zero balance is refused to keep the books intact.
     */
    public function updateCurrencies(Agent $agent, ?array $currencyIds, bool $allowAll, string $officeId): void
    {
        if ($allowAll) {
            $agent->allow_all_currencies = true;
            $agent->save();

            return;
        }

        $agent->allow_all_currencies = false;

        $current = $agent->currencyAccounts()->get();
        $keep = $currencyIds ?: [$agent->balance_currency_id];

        foreach ($current as $pair) {
            if (! in_array($pair->currency_id, $keep, true)) {
                $rec = $pair->receivableAccount()->first();
                $pay = $pair->payableAccount()->first();
                if ((abs((float) ($rec?->current_balance ?? 0)) > 0.0001)
                    || (abs((float) ($pay?->current_balance ?? 0)) > 0.0001)) {
                    throw new InvalidArgumentException(
                        'Cannot remove currency '.$pair->currency?->code.' from this agent while its balance is not zero.'
                    );
                }
                $pair->delete();
            }
        }

        foreach ($keep as $currencyId) {
            if (! $current->first(fn ($p) => $p->currency_id === $currencyId)) {
                $receivableType = AccountType::where('code', 'agent_receivable')->firstOrFail();
                $payableType = AccountType::where('code', 'agent_payable')->firstOrFail();
                $this->createPair($agent, $currencyId, $receivableType->id, $payableType->id);
            }
        }

        $agent->save();
    }

    private function createPair(Agent $agent, string $currencyId, string $receivableTypeId, string $payableTypeId): AgentCurrencyAccount
    {
        $receivable = Account::create([
            'office_id' => $agent->office_id,
            'account_type_id' => $receivableTypeId,
            'currency_id' => $currencyId,
            'name' => $agent->name.' - Receivable ('.Currency::findOrFail($currencyId)->code.')',
            'visibility' => 'office_shared',
            'current_balance' => 0,
            'is_active' => true,
        ]);

        $payable = Account::create([
            'office_id' => $agent->office_id,
            'account_type_id' => $payableTypeId,
            'currency_id' => $currencyId,
            'name' => $agent->name.' - Payable ('.Currency::findOrFail($currencyId)->code.')',
            'visibility' => 'office_shared',
            'current_balance' => 0,
            'is_active' => true,
        ]);

        return $agent->currencyAccounts()->create([
            'currency_id' => $currencyId,
            'receivable_account_id' => $receivable->id,
            'payable_account_id' => $payable->id,
        ]);
    }

    /**
     * Resolve the receivable/payable accounts of an agent for a specific
     * currency, respecting its configured currency set.
     *
     * @throws InvalidArgumentException when the agent does not deal in
     *                                   this currency.
     */
    public function resolveAgentAccounts(Agent $agent, string $currencyId): AgentCurrencyAccount
    {
        $pair = $agent->currencyAccounts()->where('currency_id', $currencyId)->first();

        if ($pair) {
            // Reuse (and keep as the agent's primary) the matching pair.
            return $pair;
        }

        if ($agent->allow_all_currencies) {
            $receivableType = AccountType::where('code', 'agent_receivable')->firstOrFail();
            $payableType = AccountType::where('code', 'agent_payable')->firstOrFail();

            // Guard against concurrent lazy-creation: if a unique-violation
            // happens, fall back to the pair created by the parallel request.
            try {
                return $this->createPair($agent, $currencyId, $receivableType->id, $payableType->id);
            } catch (QueryException $e) {
                if (($pair = $agent->currencyAccounts()->where('currency_id', $currencyId)->first())) {
                    return $pair;
                }

                throw $e;
            }
        }

        $code = Currency::find($currencyId)?->code ?? $currencyId;

        throw new InvalidArgumentException("Agent {$agent->name} does not deal in {$code}.");
    }
}
