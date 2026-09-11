<?php

namespace App\Services;

use App\Models\JournalEntry;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

class AccountingService
{
    /**
     * Create a balanced double-entry transaction with its journal entries.
     *
     * Validates that SUM(debits) === SUM(credits), generates the transaction
     * number via the fn_next_sequence() PostgreSQL function, persists the
     * Transaction and its JournalEntry rows inside DB::transaction().
     * Database triggers automatically update account and agent balances.
     *
     * @param  array  $data  {office_id, tx_type, description, created_by, reference_type?, reference_id?, entries: [{account_id, entry_type, amount, currency_id, description?}]}
     *
     * @throws InvalidArgumentException when the entries are unbalanced
     */
    public function createTransaction(array $data): Transaction
    {
        return DB::transaction(function () use ($data) {
            $debits = 0.0;
            $credits = 0.0;

            foreach ($data['entries'] as $entry) {
                if (strtolower($entry['entry_type']) === 'debit') {
                    $debits += (float) $entry['amount'];
                } else {
                    $credits += (float) $entry['amount'];
                }
            }

            if (round($debits, 4) !== round($credits, 4)) {
                throw new InvalidArgumentException(
                    "Unbalanced transaction: debits ({$debits}) do not equal credits ({$credits})."
                );
            }

            $txNumber = DB::select('SELECT fn_next_sequence(?, ?) AS number', [
                $data['office_id'],
                'transaction',
            ])[0]->number;

            $transaction = Transaction::create([
                'office_id' => $data['office_id'],
                'tx_number' => $txNumber,
                'tx_type' => $data['tx_type'],
                'reference_type' => $data['reference_type'] ?? null,
                'reference_id' => $data['reference_id'] ?? null,
                'description' => $data['description'] ?? null,
                'is_void' => false,
                'created_by' => $data['created_by'],
            ]);

            foreach ($data['entries'] as $entry) {
                $transaction->journalEntries()->create([
                    'account_id' => $entry['account_id'],
                    'entry_type' => strtolower($entry['entry_type']),
                    'amount' => $entry['amount'],
                    'currency_id' => $entry['currency_id'],
                    'description' => $entry['description'] ?? ($data['description'] ?? null),
                ]);
            }

            return $transaction;
        });
    }

    /**
     * Void an existing transaction by creating reversal journal entries
     * (debit/credit swapped). The original transaction is flagged as void;
     * journal entries are never modified or deleted.
     *
     * @param  string  $txId  UUID of the transaction to void
     * @param  string  $userId  UUID of the acting user
     * @param  string  $reason  Why the transaction is being voided
     *
     * @throws RuntimeException when the transaction does not exist or is already void
     */
    public function voidTransaction(string $txId, string $userId, string $reason): Transaction
    {
        return DB::transaction(function () use ($txId, $userId, $reason) {
            $original = Transaction::whereKey($txId)->lockForUpdate()->first();

            if (! $original) {
                throw new RuntimeException('Transaction not found.');
            }

            if ($original->is_void) {
                throw new RuntimeException('Transaction is already voided.');
            }

            $reversalEntries = $original->journalEntries()
                ->get()
                ->map(fn (JournalEntry $entry) => [
                    'account_id' => $entry->account_id,
                    'entry_type' => $entry->entry_type === 'debit' ? 'credit' : 'debit',
                    'amount' => $entry->amount,
                    'currency_id' => $entry->currency_id,
                    'description' => 'Reversal of '.$original->tx_number,
                ])
                ->all();

            $reversal = $this->createTransaction([
                'office_id' => $original->office_id,
                'tx_type' => 'reversal',
                'description' => 'Reversal of '.$original->tx_number.': '.$reason,
                'created_by' => $userId,
                'reference_type' => $original->reference_type,
                'reference_id' => $original->reference_id,
                'entries' => $reversalEntries,
            ]);

            $original->forceFill([
                'is_void' => true,
                'void_reason' => $reason,
            ])->save();

            return $reversal;
        });
    }
}
