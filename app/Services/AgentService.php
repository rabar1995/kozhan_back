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
     * Create an agent together with a single net-position wallet per
     * currency (type agent_wallet), office_shared, inside a transaction.
     * Positive balance = agent owes the office (debtor), negative =
     * office owes the agent (creditor).
     *
     * @param  array  $data  {name, phone?, country?, city?, balance_currency_id}
     * @param  User  $user  Acting user
     */
    public function create(array $data, User $user): Agent
    {
        return DB::transaction(function () use ($data, $user) {
            $officeId = $user->office_id;

            $agent = Agent::create([
                'office_id' => $officeId,
                'name' => $data['name'],
                'phone' => $data['phone'] ?? null,
                'country' => $data['country'] ?? null,
                'city' => $data['city'] ?? null,
                'balance_currency_id' => $data['balance_currency_id'],
                'logo_url' => $data['logo_url'] ?? null,
                'is_active' => true,
                'allow_all_currencies' => (bool) ($data['allow_all_currencies'] ?? false),
            ]);

            $this->createWallet($agent, $data['balance_currency_id']);
            foreach (array_values(array_unique(array_diff($data['currency_ids'] ?? [], [$data['balance_currency_id']]))) as $currencyId) {
                $this->createWallet($agent, $currencyId);
            }

            return $agent;
        });
    }

    /**
     * Update the agent's handled currencies.
     * - allow_all_currencies: agents take any currency; wallets are
     *   created lazily on first use by the resolver.
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

        $current = $agent->currencyAccounts()->with(['account:id,current_balance', 'receivableAccount:id,current_balance', 'payableAccount:id,current_balance'])->get();
        $keep = $currencyIds ?: [$agent->balance_currency_id];

        foreach ($current as $row) {
            if (! in_array($row->currency_id, $keep, true)) {
                $balance = $row->account
                    ? (float) $row->account->current_balance
                    : ((float) ($row->receivableAccount?->current_balance ?? 0) - (float) ($row->payableAccount?->current_balance ?? 0));

                if (abs($balance) > 0.0001) {
                    throw new InvalidArgumentException(
                        'Cannot remove currency '.$row->currency?->code.' from this agent while its balance is not zero.'
                    );
                }
                $row->delete();
            }
        }

        foreach ($keep as $currencyId) {
            if (! $current->first(fn ($p) => $p->currency_id === $currencyId)) {
                $this->createWallet($agent, $currencyId);
            }
        }

        $agent->save();
    }

    /**
     * Create the single agent wallet for (agent, currency) and register
     * it on agent_currency_accounts.
     */
    public function createWallet(Agent $agent, string $currencyId): AgentCurrencyAccount
    {
        $type = AccountType::where('code', 'agent_wallet')->firstOrFail();
        $code = Currency::findOrFail($currencyId)->code;

        $account = Account::create([
            'office_id' => $agent->office_id,
            'account_type_id' => $type->id,
            'currency_id' => $currencyId,
            'name' => $agent->name.' ('.$code.')',
            'visibility' => 'office_shared',
            'current_balance' => 0,
            'is_active' => true,
        ]);

        return $agent->currencyAccounts()->create([
            'currency_id' => $currencyId,
            'account_id' => $account->id,
        ]);
    }

    /**
     * Resolve the balance wallet of an agent for a specific currency,
     * respecting its configured currency set.
     *
     * @throws InvalidArgumentException when the agent does not deal in
     *                                   this currency.
     */
    public function resolveAgentAccounts(Agent $agent, string $currencyId): AgentCurrencyAccount
    {
        $row = $agent->currencyAccounts()->where('currency_id', $currencyId)->first();

        if ($row) {
            // Reuse (and keep as the agent's primary) the matching wallet.
            return $row;
        }

        if ($agent->allow_all_currencies) {
            // Guard against concurrent lazy-creation: if a unique-violation
            // happens, fall back to the row created by the parallel request.
            try {
                return $this->createWallet($agent, $currencyId);
            } catch (QueryException $e) {
                if (($row = $agent->currencyAccounts()->where('currency_id', $currencyId)->first())) {
                    return $row;
                }

                throw $e;
            }
        }

        $code = Currency::find($currencyId)?->code ?? $currencyId;

        throw new InvalidArgumentException("Agent {$agent->name} does not deal in {$code}.");
    }
}
