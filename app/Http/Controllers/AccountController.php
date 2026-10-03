<?php

namespace App\Http\Controllers;

use App\Http\Concerns\ApiResponse;
use App\Models\Account;
use App\Models\Agent;
use App\Models\AgentCurrencyAccount;
use App\Models\JournalEntry;
use App\Services\AccountingService;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AccountController extends Controller
{
    use ApiResponse;

    /**
     * List accounts. The VisibilityScope automatically filters
     * owner_private accounts for office managers.
     */
    public function index(Request $request): JsonResponse
    {
        $accounts = Account::with(['accountType', 'currency', 'agentCurrencyAccount.agent:id,name'])
            ->withoutSystemTypes()
            ->when($request->filled('type'), fn ($q) => $q->byType($request->string('type')))
            ->when($request->filled('currency_id'), fn ($q) => $q->byCurrency($request->string('currency_id')))
            ->when($request->filled('active'), fn ($q) => $q->active())
            ->orderBy('name')
            ->get()
            ->map(function (Account $account) {
                $agent = $account->agentCurrencyAccount?->agent;
                $account->setAttribute('agent', $agent ? ['id' => $agent->id, 'name' => $agent->name] : null);

                return $account;
            });

        return $this->ok($accounts);
    }

    /**
     * Create a new wallet/account (owner only).
     * An optional opening_balance posts a balanced "opening balance"
     * transaction (debit the new account, credit the office's
     * Owner's Equity account) so the double-entry system stays intact.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'account_type_id' => ['required', 'uuid', 'exists:account_types,id'],
            'currency_id' => ['required', 'uuid', 'exists:currencies,id'],
            'name' => ['required', 'string', 'max:255'],
            'visibility' => ['required', 'in:owner_private,office_shared'],
            'is_active' => ['sometimes', 'boolean'],
            'logo_url' => ['nullable', 'url', 'max:255'],
            'opening_balance' => ['nullable', 'numeric'],
        ]);

        $openingBalance = round((float) ($data['opening_balance'] ?? 0), 4);
        unset($data['opening_balance']);

        $user = $request->user();

        $account = DB::transaction(function () use ($data, $openingBalance, $user) {
            $account = Account::create([
                ...$data,
                'office_id' => $user->office_id,
                'current_balance' => 0,
            ]);

            // An opening balance may be positive (cash we hold / we owe) or
            // negative (someone owes us). Either way post a balanced
            // entry against Owner's Equity, flipping the sides by sign.
            if (abs($openingBalance) >= 0.0001) {
                $equity = app(AccountingService::class)->findOrCreateOfficeAccount(
                    $user->office_id,
                    'owner_equity',
                    $data['currency_id'],
                    'owner_private'
                );

                $amount = abs($openingBalance);
                $debitSide = $openingBalance > 0 ? $account : $equity;
                $creditSide = $openingBalance > 0 ? $equity : $account;

                app(AccountingService::class)->createTransaction([
                    'office_id' => $user->office_id,
                    // 'adjustment' is one of the tx_type values allowed by
                    // the transactions_tx_type_check constraint in the DB.
                    'tx_type' => 'adjustment',
                    'description' => 'Opening balance for '.$account->name,
                    'created_by' => $user->id,
                    'reference_type' => 'account',
                    'reference_id' => $account->id,
                    'entries' => [
                        [
                            'account_id' => $debitSide->id,
                            'entry_type' => 'debit',
                            'amount' => $amount,
                            'currency_id' => $data['currency_id'],
                            'description' => 'Opening balance',
                        ],
                        [
                            'account_id' => $creditSide->id,
                            'entry_type' => 'credit',
                            'amount' => $amount,
                            'currency_id' => $data['currency_id'],
                            'description' => 'Opening balance (owner equity)',
                        ],
                    ],
                ]);
            }

            return $account->fresh(['accountType', 'currency']);
        });

        return $this->ok($account, 'Account created.', 201);
    }

    /**
     * Update an account/wallet (owner only).
     * Name, type and currency are editable; changing the currency is
     * blocked while the balance is not zero.
     * An optional new_balance posts a balanced "Balance adjustment"
     * transaction (against Owner's Equity) so the double-entry system
     * stays intact.
     */
    public function update(string $id, Request $request): JsonResponse
    {
        $account = Account::withoutGlobalScope(\App\Scopes\VisibilityScope::class)->where('office_id', $request->user()->office_id)->findOrFail($id);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'account_type_id' => ['sometimes', 'uuid', 'exists:account_types,id'],
            'currency_id' => ['sometimes', 'uuid', 'exists:currencies,id'],
            'visibility' => ['sometimes', 'in:owner_private,office_shared'],
            'is_active' => ['sometimes', 'boolean'],
            'logo_url' => ['nullable', 'url', 'max:255'],
        ]);
        $balance = $request->validate(['new_balance' => ['nullable', 'numeric']])['new_balance'] ?? null;

        /**
         * Account types whose balances are ledger-managed only. Manual
         * balance edits are blocked for these internal ledger accounts.
         * Agent wallets can be adjusted by the owner (posted against
         * Owner's Equity) so they can be brought to zero and deleted.
         */
        $lockedTypeCodes = ['exchange_pending', 'owner_equity'];
        $account->loadMissing('accountType');
        $isLockedType = in_array($account->accountType?->code, $lockedTypeCodes, true);

        if ($isLockedType && $balance !== null
            && round(abs($balance - round((float) $account->current_balance, 4)), 4) > 0.0001) {
            return $this->fail(
                'The balance of this system wallet cannot be changed manually.',
                422
            );
        }

        if (array_key_exists('currency_id', $data)
            && $data['currency_id'] !== $account->currency_id
            && round(abs((float) $account->current_balance), 4) > 0) {
            return $this->fail('The currency cannot be changed while the wallet balance is not zero.', 422);
        }

        $targetBalance = $balance === null ? null : round((float) $balance, 4);
        $currentBalance = round((float) $account->current_balance, 4);
        // No new_balance sent (e.g. a rename): leave the balance untouched.
        $delta = $targetBalance === null ? 0.0 : round($targetBalance - $currentBalance, 4);

        $account = DB::transaction(function () use ($account, $data, $delta, $request) {
            $account->fill($data)->save();

            if ($delta !== 0.0 && abs($delta) >= 0.0001) {
                $equity = app(AccountingService::class)->findOrCreateOfficeAccount(
                    $request->user()->office_id,
                    'owner_equity',
                    $account->currency_id,
                    'owner_private'
                );
                $creditSide = $delta > 0 ? $equity : $account;
                $debitSide = $delta > 0 ? $account : $equity;

                app(AccountingService::class)->createTransaction([
                    'office_id' => $request->user()->office_id,
                    'tx_type' => 'adjustment',
                    'description' => 'Balance adjustment for '.$account->name,
                    'created_by' => $request->user()->id,
                    'reference_type' => 'account',
                    'reference_id' => $account->id,
                    'entries' => [
                        [
                            'account_id' => $debitSide->id,
                            'entry_type' => 'debit',
                            'amount' => abs($delta),
                            'currency_id' => $account->currency_id,
                            'description' => 'Balance adjustment',
                        ],
                        [
                            'account_id' => $creditSide->id,
                            'entry_type' => 'credit',
                            'amount' => abs($delta),
                            'currency_id' => $account->currency_id,
                            'description' => 'Balance adjustment (owner equity)',
                        ],
                    ],
                ]);
            }

            return $account->fresh(['accountType', 'currency']);
        });

        return $this->ok($account, 'Account updated.');
    }

    /**
     * Deactivate an account/wallet (owner only).
     * Blocked while the balance is not zero so the ledger stays consistent.
     */
    public function deactivate(string $id, Request $request): JsonResponse
    {
        $account = Account::withoutGlobalScope(\App\Scopes\VisibilityScope::class)->where('office_id', $request->user()->office_id)->findOrFail($id);

        if (round(abs((float) $account->current_balance), 4) > 0) {
            return $this->fail('The wallet cannot be deactivated while its balance is not zero.', 422);
        }

        $account->forceFill(['is_active' => false])->save();

        return $this->ok($account->load(['accountType', 'currency']), 'Account deactivated.');
    }

    /**
     * Re-activate a previously deactivated account/wallet (owner only).
     */
    public function activate(string $id, Request $request): JsonResponse
    {
        $account = Account::withoutGlobalScope(\App\Scopes\VisibilityScope::class)->where('office_id', $request->user()->office_id)->findOrFail($id);

        $account->forceFill(['is_active' => true])->save();

        return $this->ok($account->load(['accountType', 'currency']), 'Account activated.');
    }

    /**
     * Delete an account/wallet (owner only).
     * Only allowed when the balance is zero (the owner can bring it to
     * zero first via the balance adjustment on update). The wallet is
     * soft-deleted so the immutable ledger history that references it
     * stays intact. System accounts cannot be deleted.
     */
    public function destroy(string $id, Request $request): JsonResponse
    {
        $account = Account::withoutGlobalScope(\App\Scopes\VisibilityScope::class)
            ->where('office_id', $request->user()->office_id)
            ->with('accountType')
            ->findOrFail($id);

        if (in_array($account->accountType?->code, ['owner_equity', 'exchange_pending'], true)) {
            return $this->fail('System wallets cannot be deleted.', 422);
        }

        // Agents whose wallet is being removed — used to delete the agent
        // once its last wallet is gone.
        $agentIds = $account->accountType?->code === 'agent_wallet'
            ? AgentCurrencyAccount::where('account_id', $account->id)->pluck('agent_id')->unique()->values()->all()
            : [];

        DB::transaction(function () use ($account, $agentIds) {
            // Lock the row so a concurrent transaction cannot move the
            // balance between the check and the delete.
            $locked = Account::withoutGlobalScope(\App\Scopes\VisibilityScope::class)
                ->lockForUpdate()
                ->findOrFail($account->id);

            if (round(abs((float) $locked->current_balance), 4) > 0) {
                throw new HttpResponseException($this->fail(
                    'The wallet cannot be deleted while its balance is not zero. Set the balance to 0 first.',
                    422
                ));
            }

            // An agent wallet is unlinked from its agent so the agent
            // wallet is re-created on demand for later remittances.
            AgentCurrencyAccount::where('account_id', $locked->id)->delete();

            $locked->delete();

            // Once an agent has no wallets left, soft-delete the agent too
            // so it disappears from lists while its remittance history
            // (FK, no cascade) stays intact.
            foreach ($agentIds as $agentId) {
                $agent = Agent::find($agentId);

                if ($agent && $agent->currencyAccounts()->count() === 0) {
                    $agent->delete();
                }
            }
        });

        return $this->ok(null, 'Account deleted.');
    }
    /**
     * Journal entries (ledger) of an account, with optional date filters.
     */
    public function ledger(string $id, Request $request): JsonResponse
    {
        $account = Account::findOrFail($id);

        $entries = JournalEntry::where('account_id', $account->id)
            ->with(['transaction:id,tx_number,tx_type,description,is_void,created_at', 'currency:id,code,symbol'])
            ->when($request->filled('date_from'), fn ($q) => $q->where('created_at', '>=', $request->date('date_from')->startOfDay()))
            ->when($request->filled('date_to'), fn ($q) => $q->where('created_at', '<=', $request->date('date_to')->endOfDay()))
            ->orderBy('created_at')
            ->paginate($request->integer('per_page', 50));

        return $this->ok($entries);
    }

    /**
     * Summary of all visible account balances grouped by account type.
     */
    public function balances(Request $request): JsonResponse
    {
        $accounts = Account::with(['accountType:id,code,name,category,normal_balance', 'currency:id,code,symbol'])
            ->withoutSystemTypes()
            ->active()
            ->orderBy('name')
            ->get()
            ->map(fn (Account $a) => [
                'id' => $a->id,
                'name' => $a->name,
                'type' => $a->accountType?->code,
                'category' => $a->accountType?->category,
                'visibility' => $a->visibility,
                'currency' => $a->currency?->code,
                'symbol' => $a->currency?->symbol,
                'current_balance' => $a->current_balance,
            ]);

        return $this->ok($accounts);
    }
}
