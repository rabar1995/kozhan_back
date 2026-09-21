<?php

namespace App\Http\Controllers;

use App\Http\Concerns\ApiResponse;
use App\Models\Account;
use App\Models\JournalEntry;
use App\Models\Transaction;
use App\Services\AccountingService;
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
        $accounts = Account::with(['accountType', 'currency'])
            ->withoutSystemTypes()
            ->when($request->filled('type'), fn ($q) => $q->byType($request->string('type')))
            ->when($request->filled('currency_id'), fn ($q) => $q->byCurrency($request->string('currency_id')))
            ->when($request->filled('active'), fn ($q) => $q->active())
            ->orderBy('name')
            ->get();

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
            'opening_balance' => ['nullable', 'numeric', 'min:0'],
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

            if ($openingBalance > 0) {
                $equity = app(AccountingService::class)->findOrCreateOfficeAccount(
                    $user->office_id,
                    'owner_equity',
                    $data['currency_id'],
                    'owner_private'
                );

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
                            'account_id' => $account->id,
                            'entry_type' => 'debit',
                            'amount' => $openingBalance,
                            'currency_id' => $data['currency_id'],
                            'description' => 'Opening balance',
                        ],
                        [
                            'account_id' => $equity->id,
                            'entry_type' => 'credit',
                            'amount' => $openingBalance,
                            'currency_id' => $data['currency_id'],
                            'description' => 'Opening balance (owner capital)',
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
        $account = Account::withoutGlobalScopes()->where('office_id', $request->user()->office_id)->findOrFail($id);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'account_type_id' => ['sometimes', 'uuid', 'exists:account_types,id'],
            'currency_id' => ['sometimes', 'uuid', 'exists:currencies,id'],
            'visibility' => ['sometimes', 'in:owner_private,office_shared'],
            'is_active' => ['sometimes', 'boolean'],
            'logo_url' => ['nullable', 'url', 'max:255'],
        ]);
        $balance = $request->validate(['new_balance' => ['nullable', 'numeric', 'min:0']])['new_balance'] ?? null;

        /**
         * Account types whose balances are ledger-managed only. Manual
         * balance edits are blocked: agent balances move solely through
         * remittances, payouts and settlements.
         */
        $lockedTypeCodes = ['agent_wallet', 'exchange_pending', 'owner_equity'];
        $account->loadMissing('accountType');
        $isLockedType = in_array($account->accountType?->code, $lockedTypeCodes, true);

        if ($isLockedType && $balance !== null
            && round(abs($balance - round((float) $account->current_balance, 4)), 4) > 0.0001) {
            return $this->fail(
                'The balance of this wallet cannot be changed manually. '
                .'Agent balances only move through remittances, payouts and settlements.',
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
        $delta = round($targetBalance - $currentBalance, 4);

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
        $account = Account::withoutGlobalScopes()->where('office_id', $request->user()->office_id)->findOrFail($id);

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
        $account = Account::withoutGlobalScopes()->where('office_id', $request->user()->office_id)->findOrFail($id);

        $account->forceFill(['is_active' => true])->save();

        return $this->ok($account->load(['accountType', 'currency']), 'Account activated.');
    }

    /**
     * Permanently delete an account/wallet (owner only).
     * Only allowed when the balance is zero. Journal entries referencing
     * the wallet (deals, transfers, expenses, remittances, adjustments)
     * normally block deletion; passing ?force=true erases the wallet's
     * ledger records together with the wallet itself.
     */
    public function destroy(string $id, Request $request): JsonResponse
    {
        $account = Account::withoutGlobalScopes()->where('office_id', $request->user()->office_id)->findOrFail($id);

        if (round(abs((float) $account->current_balance), 4) > 0) {
            return $this->fail('The wallet cannot be deleted while its balance is not zero. Deactivate it instead.', 422);
        }

        $txIds = Transaction::whereHas('journalEntries', fn ($q) => $q->where('account_id', $account->id))->pluck('id');

        if ($txIds->isNotEmpty() && ! $request->boolean('force')) {
            return $this->fail('The wallet cannot be deleted because it has ledger history. Deactivate it instead.', 422);
        }

        DB::transaction(function () use ($account, $txIds) {
            if ($txIds->isNotEmpty()) {
                // Open a transaction-local maintenance window so the
                // immutability trigger allows this controlled cleanup.
                DB::statement("SELECT set_config('app.journal_maintenance', 'on', true)");

                // Accounts that will lose entries — captured before delete.
                $affectedIds = array_values(
                    JournalEntry::whereIn('transaction_id', $txIds)
                        ->distinct()
                        ->pluck('account_id')
                        ->all()
                );

                JournalEntry::whereIn('transaction_id', $txIds)->delete();
                Transaction::whereIn('id', $txIds)->delete();

                // The balance trigger only fires on INSERT (never on
                // DELETE), so mirror its math here for every account
                // whose entries were just removed.
                if ($affectedIds) {
                    $accountPlaceholders = implode(',', array_fill(0, count($affectedIds), '?'));

                    DB::statement(<<<SQL
                        UPDATE accounts a
                        SET current_balance = COALESCE(v.bal, 0), updated_at = NOW()
                        FROM (
                            SELECT je.account_id,
                                   SUM(CASE
                                       WHEN at.normal_balance = 'debit'
                                           THEN CASE WHEN je.entry_type = 'debit' THEN je.amount ELSE -je.amount END
                                       ELSE CASE WHEN je.entry_type = 'credit' THEN je.amount ELSE -je.amount END
                                   END) AS bal
                            FROM journal_entries je
                            JOIN accounts ac ON ac.id = je.account_id
                            JOIN account_types at ON at.id = ac.account_type_id
                            WHERE je.account_id IN ({$accountPlaceholders})
                            GROUP BY je.account_id
                        ) v
                        WHERE a.id = v.account_id
                    SQL, $affectedIds);
                }
            }

            $account->delete();
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
