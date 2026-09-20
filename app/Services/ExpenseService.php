<?php

namespace App\Services;

use App\Models\Expense;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ExpenseService
{
    public function __construct(private AccountingService $accounting)
    {
    }

    /**
     * Record an operational expense:
     * DR expense_account   (amount)
     * CR paid_from_account (amount)
     * and persist the linked Expense record.
     * When no expense_account_id is given it is auto-resolved to the
     * office's Operating Expenses account for the expense currency
     * (lazily created on first use).
     *
     * @param  array  $data  Validated request data
     * @param  User  $user  Acting user
     *
     * @throws RuntimeException when the two accounts use different currencies
     */
    public function record(array $data, User $user): Expense
    {
        return DB::transaction(function () use ($data, $user) {
            $paidFrom = \App\Models\Account::withoutGlobalScopes()->findOrFail($data['paid_from_account_id']);

            if (! empty($data['expense_account_id'])) {
                $expenseAccount = \App\Models\Account::withoutGlobalScopes()->findOrFail($data['expense_account_id']);
            } else {
                $expenseAccount = $this->ensureExpenseAccount($user->office_id, $data['currency_id']);
            }

            if ($paidFrom->currency_id !== $data['currency_id'] || $expenseAccount->currency_id !== $data['currency_id']) {
                throw new RuntimeException('Both accounts must use the expense currency.');
            }

            $transaction = $this->accounting->createTransaction([
                'office_id' => $user->office_id,
                'tx_type' => 'expense',
                'description' => $data['description'],
                'created_by' => $user->id,
                'reference_type' => 'expense',
                'reference_id' => null,
                'entries' => [
                    [
                        'account_id' => $expenseAccount->id,
                        'entry_type' => 'debit',
                        'amount' => $data['amount'],
                        'currency_id' => $data['currency_id'],
                    ],
                    [
                        'account_id' => $paidFrom->id,
                        'entry_type' => 'credit',
                        'amount' => $data['amount'],
                        'currency_id' => $data['currency_id'],
                    ],
                ],
            ]);

            $expense = Expense::create([
                'office_id' => $user->office_id,
                'expense_category_id' => $data['expense_category_id'],
                'paid_from_account_id' => $paidFrom->id,
                'expense_account_id' => $expenseAccount->id,
                'amount' => $data['amount'],
                'currency_id' => $data['currency_id'],
                'description' => $data['description'],
                'expense_date' => $data['expense_date'],
                'transaction_id' => $transaction->id,
                'is_void' => false,
                'created_by' => $user->id,
            ]);

            $transaction->forceFill(['reference_id' => $expense->id])->save();

            return $expense;
        });
    }

    /**
     * Find or lazily create the office's Operating Expenses account for
     * the given currency (same pattern as the system's other lazy system
     * wallets, e.g. Owner's Equity).
     */
    private function ensureExpenseAccount(string $officeId, string $currencyId): \App\Models\Account
    {
        $account = \App\Models\Account::withoutGlobalScopes()
            ->where('office_id', $officeId)
            ->where('currency_id', $currencyId)
            ->whereHas('accountType', fn ($q) => $q->where('code', 'operating_expense'))
            ->first();

        if ($account) {
            return $account;
        }

        $type = \App\Models\AccountType::where('code', 'operating_expense')->firstOrFail();
        $code = \App\Models\Currency::where('id', $currencyId)->value('code') ?: 'CUR';

        return \App\Models\Account::create([
            'office_id' => $officeId,
            'account_type_id' => $type->id,
            'currency_id' => $currencyId,
            'name' => "Operating Expenses - {$code}",
            'visibility' => 'office_shared',
            'current_balance' => 0,
            'is_active' => true,
        ]);
    }
}
