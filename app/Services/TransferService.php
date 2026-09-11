<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Transfer;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class TransferService
{
    public function __construct(private AccountingService $accounting)
    {
    }

    /**
     * Transfer money between two accounts of the same office:
     * DR to_account   (amount)
     * CR from_account (amount).
     * Owner role is required when either account is owner_private.
     *
     * @param  array  $data  {from_account_id, to_account_id, amount, currency_id, description?}
     * @param  User  $user  Acting user
     *
     * @throws RuntimeException on validation failures
     */
    public function transfer(array $data, User $user): Transfer
    {
        return DB::transaction(function () use ($data, $user) {
            $from = Account::withoutGlobalScopes()->findOrFail($data['from_account_id']);
            $to = Account::withoutGlobalScopes()->findOrFail($data['to_account_id']);

            if ($from->office_id !== $user->office_id || $to->office_id !== $user->office_id) {
                throw new RuntimeException('Both accounts must belong to your office.');
            }

            if (($from->visibility === 'owner_private' || $to->visibility === 'owner_private') && ! $user->isOwner()) {
                throw new RuntimeException('Only the owner can move private wallets.');
            }

            if ($from->currency_id !== $data['currency_id'] || $to->currency_id !== $data['currency_id']) {
                throw new RuntimeException('Both accounts must use the transfer currency.');
            }

            $transaction = $this->accounting->createTransaction([
                'office_id' => $user->office_id,
                'tx_type' => 'transfer',
                'description' => $data['description'] ?? 'Internal transfer',
                'created_by' => $user->id,
                'reference_type' => 'transfer',
                'reference_id' => null,
                'entries' => [
                    [
                        'account_id' => $to->id,
                        'entry_type' => 'debit',
                        'amount' => $data['amount'],
                        'currency_id' => $data['currency_id'],
                    ],
                    [
                        'account_id' => $from->id,
                        'entry_type' => 'credit',
                        'amount' => $data['amount'],
                        'currency_id' => $data['currency_id'],
                    ],
                ],
            ]);

            $transfer = Transfer::create([
                'office_id' => $user->office_id,
                'from_account_id' => $from->id,
                'to_account_id' => $to->id,
                'amount' => $data['amount'],
                'currency_id' => $data['currency_id'],
                'description' => $data['description'] ?? null,
                'transaction_id' => $transaction->id,
                'created_by' => $user->id,
            ]);

            $transaction->forceFill(['reference_id' => $transfer->id])->save();

            return $transfer;
        });
    }
}
