<?php

namespace App\Services;

use App\Models\Account;
use App\Models\AccountType;
use App\Models\Currency;
use App\Models\JournalEntry;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class AccountingService
{
    /**
     * Find or lazily create an office account by account-type code and
     * currency (the single shared helper every lazy system wallet goes
     * through: Owner's Equity, Exchange Deals Pending, Commission,
     * Operating Expenses...).
     */
    public function findOrCreateOfficeAccount(string $officeId, string $typeCode, string $currencyId, string $visibility = 'office_shared'): Account
    {
        $account = Account::withoutGlobalScopes()
            ->where('office_id', $officeId)
            ->where('currency_id', $currencyId)
            ->whereHas('accountType', fn ($q) => $q->where('code', $typeCode))
            ->first();

        if ($account) {
            return $account;
        }

        $type = AccountType::where('code', $typeCode)->firstOrFail();
        $code = Currency::findOrFail($currencyId)->code;

        return Account::create([
            'office_id' => $officeId,
            'account_type_id' => $type->id,
            'currency_id' => $currencyId,
            'name' => $type->name.' - '.$code,
            'visibility' => $visibility,
            'current_balance' => 0,
            'is_active' => true,
        ]);
    }

    /**
     * Create a journal transaction with its balanced double-entry lines:
     *  1. Balance check per shared currency (single-currency payloads must
     *     net to zero; the DB trigger keeps account balances in sync).
     *  2. Transaction row with its office-scoped document number from
     *     fn_next_sequence().
     *  3. Journal entries (immutable once written).
     *
     * @param  array{office_id: string, tx_type: string, description: ?string,
     *     created_by: string, reference_type?: ?string, reference_id?: ?string,
     *     tx_number?: string, total_amount?: float, currency_id?: string,
     *     entries: array<int, array{account_id: string, entry_type: string,
     *         amount: float|int|string, currency_id: string, description?: ?string}>}  $data
     */
    public function createTransaction(array $data): Transaction
    {
        return DB::transaction(function () use ($data) {
            $entries = collect($data['entries']);

            foreach ($entries as $entry) {
                if ((string) $entry['entry_type'] !== 'debit' && (string) $entry['entry_type'] !== 'credit') {
                    throw new RuntimeException('Entry type must be debit or credit.');
                }

                if (round((float) $entry['amount'], 4) <= 0) {
                    throw new RuntimeException('Entry amount must be positive.');
                }
            }

            // For single-currency payloads the lines must balance.
            $byCurrency = $entries->groupBy(fn ($e) => (string) $e['currency_id']);
            if ($byCurrency->count() === 1) {
                $currency = $byCurrency->keys()->first();
                $mid = $entries->groupBy(fn ($e) => (string) $e['entry_type']);
                $debits = $mid->get('debit', collect())->sum(
                    fn ($e) => round((float) $e['amount'], 4)
                );
                $credits = $mid->get('credit', collect())->sum(
                    fn ($e) => round((float) $e['amount'], 4)
                );

                if (abs($debits - $credits) >= 0.0001) {
                    throw new RuntimeException(
                        "Unbalanced transaction ({$currency}): debits={$debits} credits={$credits}."
                    );
                }
            }

            $tx = Transaction::create([
                'office_id' => $data['office_id'],
                'tx_number' => $data['tx_number']
                    ?? DB::select('SELECT fn_next_sequence(?, ?) AS number', [$data['office_id'], $data['tx_type']])[0]->number,
                'tx_type' => $data['tx_type'],
                'reference_type' => $data['reference_type'] ?? null,
                'reference_id' => $data['reference_id'] ?? null,
                'description' => $data['description'] ?? null,
                'is_void' => false,
                'created_by' => $data['created_by'] ?? null,
            ]);

            $tx->journalEntries()->createMany(
                $entries->map(fn ($e) => [
                    'account_id' => $e['account_id'],
                    'entry_type' => $e['entry_type'],
                    'amount' => $e['amount'],
                    'currency_id' => $e['currency_id'],
                    'description' => $e['description'] ?? null,
                ])->all()
            );

            return $tx;
        });
    }

    /**
     * Void a transaction by booking its exact reversal (entries are
     * immutable, so the inverse is a new balanced transaction of type
     * 'reversal'), then flag the original as void.
     */
    public function voidTransaction(string $txId, string $userId, string $reason): Transaction
    {
        return DB::transaction(function () use ($txId, $userId, $reason) {
            $tx = Transaction::whereKey($txId)->lockForUpdate()->firstOrFail();

            if ($tx->is_void) {
                throw new RuntimeException('This transaction is already void.');
            }

            $entries = JournalEntry::where('transaction_id', $tx->id)
                ->get(['account_id', 'entry_type', 'amount', 'currency_id']);

            if ($entries->isNotEmpty()) {
                $this->createTransaction([
                    'office_id' => $tx->office_id,
                    'tx_type' => 'reversal',
                    'description' => ($reason ? $reason.' — ' : '').'Reversal of '.$tx->tx_number,
                    'created_by' => $userId,
                    'reference_type' => $tx->reference_type,
                    'reference_id' => $tx->reference_id,
                    'entries' => $entries
                        ->map(fn (JournalEntry $e) => [
                            'account_id' => $e->account_id,
                            'entry_type' => $e->entry_type === 'debit' ? 'credit' : 'debit',
                            'amount' => $e->amount,
                            'currency_id' => $e->currency_id,
                            'description' => 'Reversal of '.$tx->tx_number,
                        ])->all(),
                ]);
            }

            $tx->forceFill(['is_void' => true, 'void_reason' => $reason])->save();

            return $tx->refresh();
        });
    }
}
