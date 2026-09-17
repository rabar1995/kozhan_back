<?php

namespace App\Services;

use App\Models\Account;
use App\Models\AccountType;
use App\Models\ExchangeDeal;
use App\Models\Currency;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

class ExchangeDealService
{
    public function __construct(private AccountingService $accounting)
    {
    }

    /**
     * Book a two-sided deal from the single open card.
     *
     * status = 'pending':
     *   Send leg booked right away:
     *      DR pending (send_amount) / CR send wallet (send_amount)
     *   The planned receive side (wallet + amount) is stored on the row
     *   WITHOUT booking — its booking happens when the deal is settled.
     *
     * status = 'completed':
     *   Both legs are booked atomically: send leg + settle leg, so the
     *   receive wallet actually gets the receive amount and the FX
     *   spread (receive_amount - send_amount) is booked as P/L.
     *
     * @param  array  $data  Validated request data
     * @param  User  $user  Acting user (owner only)
     */
    public function openDeal(array $data, User $user): ExchangeDeal
    {
        return DB::transaction(function () use ($data, $user) {
            $officeId = $user->office_id;

            $wallet = $this->findOwnWallet($data['send_account_id'], $officeId);
            $pending = $this->findPendingAccount($officeId, $wallet->currency_id);
            $amount = (float) $data['send_amount'];

            $record = $this->createRecord([
                'office_id' => $officeId,
                'tx_number' => $this->nextNumber($officeId),
                'direction' => 'deal_send',
                'counterparty_name' => '—',
                'account_id' => $wallet->id,
                'amount' => $amount,
                'currency_id' => $wallet->currency_id,
                'settle_account_id' => $data['receive_account_id'] ?? null,
                'settle_currency_id' => null,
                'settle_amount' => $data['receive_amount'] ?? null,
                'notes' => $data['notes'] ?? null,
                'created_by' => $user->id,
            ]);

            $this->book(
                $officeId,
                'Deal opened — sent from '.$wallet->name.($data['status'] === 'completed' ? ' (done in same action)' : ''),
                [
                    $this->entry($pending->id, 'debit', $amount, $wallet->currency_id),
                    $this->entry($wallet->id, 'credit', $amount, $wallet->currency_id),
                ],
                $user,
                $record,
            );

            // Completed deal: book the receive side in the same database
            // transaction so the funds land in the receiving wallet at once.
            if ($data['status'] === 'completed') {
                $receiveWallet = $this->findOwnWallet($data['receive_account_id'], $officeId);
                $record->settle_currency_id = $receiveWallet->currency_id;
                $record->save();

                $this->settleDeal($record->id, [
                    'settle_account_id' => $receiveWallet->id,
                    'settle_amount' => $data['receive_amount'],
                    'notes' => $data['notes'] ?? null,
                ], $user);
            }

            return $record;
        });
    }

    /**
     * Close an open deal: the money comes back / is paid out in another
     * wallet.
     *
     * Closing a deal_send (person returns the money):
     *      DR settle wallet (settle_amount)
     *      CR pending       (amount)
     * Closing a deal_receive (owner pays the person back):
     *      DR pending       (amount)
     *      CR settle wallet (settle_amount)
     * The numeric spread (settle_amount - amount) is booked as
     * profit/loss in the settle wallet's currency.
     *
     * @param  string  $openTxId  UUID of the deal_send / deal_receive row
     * @param  array  $data  Validated request data
     * @param  User  $user  Acting user (owner only)
     */
    public function settleDeal(string $openTxId, array $data, User $user): ExchangeDeal
    {
        return DB::transaction(function () use ($openTxId, $data, $user) {
            $officeId = $user->office_id;

            $open = ExchangeDeal::whereKey($openTxId)
                ->where('office_id', $officeId)
                ->whereIn('direction', ['deal_send', 'deal_receive'])
                ->where('status', 'completed')
                ->lockForUpdate()
                ->firstOrFail();

            $settled = $open->dealSettlements()->where('status', 'completed')->first();
            if ($settled) {
                throw new RuntimeException('This deal was already settled by '.$settled->tx_number.'.');
            }

            $pending = $this->findPendingAccount($officeId, $open->currency_id);
            $wallet = $this->findOwnWallet($data['settle_account_id'], $officeId);

            $amount = (float) $open->amount;
            $settleAmount = (float) $data['settle_amount'];
            $settleCurrencyId = $wallet->currency_id;

            $record = $this->createRecord([
                'office_id' => $officeId,
                'tx_number' => $this->nextNumber($officeId),
                'direction' => 'deal_settle',
                'counterparty_name' => $open->counterparty_name,
                'account_id' => $open->account_id,
                'amount' => $amount,
                'currency_id' => $open->currency_id,
                'settle_account_id' => $wallet->id,
                'settle_currency_id' => $settleCurrencyId,
                'settle_amount' => $settleAmount,
                'deal_parent_id' => $open->id,
                'notes' => $data['notes'] ?? $open->notes,
                'created_by' => $user->id,
            ]);

            if ($open->direction === 'deal_send') {
                $entries = [
                    $this->entry($wallet->id, 'debit', $settleAmount, $settleCurrencyId),
                    $this->entry($pending->id, 'credit', $amount, $open->currency_id),
                ];
                $isDebitSide = false;
            } else {
                $entries = [
                    $this->entry($pending->id, 'debit', $amount, $open->currency_id),
                    $this->entry($wallet->id, 'credit', $settleAmount, $settleCurrencyId),
                ];
                $isDebitSide = true;
            }

            if ($spread = $this->spreadEntry($settleAmount - $amount, $isDebitSide, $settleCurrencyId, $officeId)) {
                $entries[] = $spread;
            }

            $this->book($officeId, 'Deal settled - '.$open->counterparty_name, $entries, $user, $record);

            return $record;
        });
    }

    /**
     * Cancel a deal leg by voiding its booking through reversal entries
     * (journal entries are immutable).
     *
     * Cancelling a settle leg re-opens its deal.
     */
    public function cancel(string $txId, string $reason, User $user): ExchangeDeal
    {
        return DB::transaction(function () use ($txId, $reason, $user) {
            $record = ExchangeDeal::whereKey($txId)->lockForUpdate()->firstOrFail();

            if ($record->status !== 'completed') {
                throw new RuntimeException('Only completed deal legs can be cancelled.');
            }

            if (in_array($record->direction, ['deal_send', 'deal_receive'], true)) {
                $settled = $record->dealSettlements()->where('status', 'completed')->first();
                if ($settled) {
                    throw new RuntimeException('This deal was already settled by '.$settled->tx_number.
                        '. Cancel the settlement first.');
                }
            }

            if ($record->booking_tx_id && ! $record->bookingTransaction->is_void) {
                $this->accounting->voidTransaction($record->booking_tx_id, $user->id, 'Exchange deal cancelled: '.$reason);
            }

            $record->status = 'cancelled';
            $record->cancelled_at = now();
            $record->cancelled_by = $user->id;
            $record->cancel_reason = $reason;
            $record->save();

            return $record;
        });
    }

    /**
     * Owner dashboard: pending account balances and open (unsettled) deals.
     */
    public function summary(string $officeId): array
    {
        $pendingAccounts = Account::withoutGlobalScopes()
            ->with('currency:id,code,symbol')
            ->where('office_id', $officeId)
            ->where('is_active', true)
            ->whereHas('accountType', fn ($q) => $q->where('code', 'exchange_pending'))
            ->orderBy('name')
            ->get()
            ->map(fn (Account $a) => [
                'id' => $a->id,
                'name' => $a->name,
                'code' => $a->currency?->code,
                'symbol' => $a->currency?->symbol,
                'balance' => $a->current_balance,
            ]);

        $pendingDeals = ExchangeDeal::query()
            ->with(['currency:id,code,symbol', 'account:id,name', 'creator:id,name'])
            ->where('office_id', $officeId)
            ->whereIn('direction', ['deal_send', 'deal_receive'])
            ->where('status', 'completed')
            ->whereDoesntHave('dealSettlements', fn ($q) => $q->where('status', 'completed'))
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (ExchangeDeal $t) => [
                'id' => $t->id,
                'tx_number' => $t->tx_number,
                'direction' => $t->direction,
                'counterparty_name' => $t->counterparty_name,
                'wallet' => $t->account?->name,
                'amount' => $t->amount,
                'code' => $t->currency?->code,
                'created_at' => $t->created_at,
                // Planned receive side (saved when the deal was opened as pending)
                'settle_account_id' => $t->settle_account_id,
                'settle_amount' => $t->settle_amount,
            ]);

        return [
            'pending_accounts' => $pendingAccounts,
            'pending_deals' => $pendingDeals,
        ];
    }

    /**
     * Resolve (or lazily create) the shared clearing account that holds
     * all open deals for an office / currency, e.g. "Pending Deals - USD".
     */
    private function findPendingAccount(string $officeId, string $currencyId): Account
    {
        $pending = Account::withoutGlobalScopes()
            ->where('office_id', $officeId)
            ->where('currency_id', $currencyId)
            ->whereHas('accountType', fn ($q) => $q->where('code', 'exchange_pending'))
            ->first();

        if ($pending) {
            return $pending;
        }

        $type = AccountType::where('code', 'exchange_pending')->firstOrFail();
        $code = Currency::findOrFail($currencyId)->code;

        return Account::create([
            'office_id' => $officeId,
            'account_type_id' => $type->id,
            'currency_id' => $currencyId,
            'name' => 'Pending Deals - '.strtoupper($code),
            'visibility' => 'owner_private',
            'current_balance' => 0,
            'is_active' => true,
        ]);
    }

    /**
     * Book the balanced double-entry transaction for a deal leg and
     * link it as its booking transaction.
     */
    private function book(string $officeId, string $description, array $entries, User $user, ExchangeDeal $record): void
    {
        $booking = $this->accounting->createTransaction([
            'office_id' => $officeId,
            'tx_type' => 'exchange_deal',
            'description' => $description,
            'created_by' => $user->id,
            'reference_type' => 'exchange_deal',
            'reference_id' => $record->id,
            'entries' => $entries,
        ]);

        $record->booking_tx_id = $booking->id;
        $record->save();
    }

    /**
     * Numeric balancing line for cross-currency legs. Mirrors the
     * commission handling used by RemittanceService for FX spreads.
     *
     * @param  float  $spreadNum  settle_amount - amount (numeric)
     * @param  bool  $amountSideIsDebit  whether the leg-1 amount side is a debit
     */
    private function spreadEntry(float $spreadNum, bool $amountSideIsDebit, string $settleCurrencyId, string $officeId): ?array
    {
        if (abs($spreadNum) < 0.0001) {
            return null;
        }

        $isRevenue = $amountSideIsDebit ? $spreadNum < 0 : $spreadNum > 0;

        return [
            'account_id' => $this->findOfficeAccountByType(
                $officeId,
                $isRevenue ? 'commission_revenue' : 'commission_expense',
                $settleCurrencyId,
            )->id,
            'entry_type' => $isRevenue ? 'credit' : 'debit',
            'amount' => abs($spreadNum),
            'currency_id' => $settleCurrencyId,
        ];
    }

    private function entry(string $accountId, string $type, float $amount, string $currencyId): array
    {
        return [
            'account_id' => $accountId,
            'entry_type' => $type,
            'amount' => $amount,
            'currency_id' => $currencyId,
        ];
    }

    private function createRecord(array $data): ExchangeDeal
    {
        return ExchangeDeal::create($data + ['status' => 'completed']);
    }

    private function nextNumber(string $officeId): string
    {
        return DB::select('SELECT fn_next_sequence(?, ?) AS number', [$officeId, 'exchange_deal'])[0]->number;
    }

    /**
     * Find an office account by account type code and currency, creating
     * it (office_shared) when it does not exist yet.
     */
    private function findOfficeAccountByType(string $officeId, string $typeCode, string $currencyId): Account
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
            'visibility' => 'office_shared',
            'current_balance' => 0,
            'is_active' => true,
        ]);
    }

    /**
     * Validate that an owner_private wallet belongs to the acting
     * user's office and is active.
     */
    private function findOwnWallet(string $accountId, string $officeId): Account
    {
        $account = Account::withoutGlobalScopes()->findOrFail($accountId);

        if ($account->office_id !== $officeId) {
            throw new InvalidArgumentException('The selected wallet does not belong to your office.');
        }

        if ($account->visibility !== 'owner_private') {
            throw new InvalidArgumentException('Exchange deals can only use owner private wallets.');
        }

        if (! $account->is_active) {
            throw new InvalidArgumentException('The selected wallet is not active.');
        }

        return $account;
    }
}
