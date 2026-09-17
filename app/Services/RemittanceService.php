<?php

namespace App\Services;

use App\Models\Account;
use App\Models\AccountType;
use App\Models\Agent;
use App\Models\Currency;
use App\Models\Remittance;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class RemittanceService
{
    public function __construct(private AccountingService $accounting, private AgentService $agents)
    {
    }

    /**
     * Book an incoming remittance received from an agent.
     *
     * Booking entries: DR agent.receivable_account (send_amount)
     *                  CR remittance_payable (send_amount).
     * When commission > 0 a separate commission transaction is created:
     * earned  => DR agent.receivable_account, CR commission_revenue
     * paid    => DR commission_expense,       CR agent.payable_account.
     *
     * @param  array  $data  Validated request data (agent_id, amounts, currencies, commission...)
     * @param  User  $user  Acting user (office is taken from the user)
     */
    public function createIncoming(array $data, User $user): Remittance
    {
        return DB::transaction(function () use ($data, $user) {
            $officeId = $user->office_id;

            $agent = Agent::whereKey($data['agent_id'])->lockForUpdate()->firstOrFail();

            $remittance = Remittance::create([
                'office_id' => $officeId,
                'remittance_number' => $this->nextNumber($officeId, 'remittance_in'),
                'direction' => 'incoming',
                'status' => 'pending',
                'agent_id' => $agent->id,
                'sender_name' => $data['sender_name'],
                'sender_phone' => $data['sender_phone'] ?? null,
                'receiver_name' => $data['receiver_name'],
                'receiver_phone' => $data['receiver_phone'] ?? null,
                'send_amount' => $data['send_amount'],
                'send_currency_id' => $data['send_currency_id'],
                'receive_amount' => $data['receive_amount'],
                'receive_currency_id' => $data['receive_currency_id'],
                'exchange_rate' => $data['exchange_rate'],
                'commission_amount' => $data['commission_amount'] ?? 0,
                'commission_currency_id' => $data['commission_currency_id'] ?? null,
                'commission_type' => $data['commission_type'] ?? null,
                'notes' => $data['notes'] ?? null,
                'created_by' => $user->id,
            ]);

            $payableAccount = $this->findOfficeAccountByType($officeId, 'remittance_payable', $data['send_currency_id']);

            $booking = $this->accounting->createTransaction([
                'office_id' => $officeId,
                'tx_type' => 'remittance',
                'description' => 'Incoming remittance '.$remittance->remittance_number,
                'created_by' => $user->id,
                'reference_type' => 'remittance',
                'reference_id' => $remittance->id,
                'entries' => [
                    [
                        'account_id' => $this->agents->resolveAgentAccounts($agent, $data['send_currency_id'])->receivable_account_id,
                        'entry_type' => 'debit',
                        'amount' => $data['send_amount'],
                        'currency_id' => $data['send_currency_id'],
                    ],
                    [
                        'account_id' => $payableAccount->id,
                        'entry_type' => 'credit',
                        'amount' => $data['send_amount'],
                        'currency_id' => $data['send_currency_id'],
                    ],
                ],
            ]);

            $remittance->booking_tx_id = $booking->id;

            if ((float) ($data['commission_amount'] ?? 0) > 0) {
                $commissionTx = $this->createCommissionTransaction($remittance, $agent, $officeId, $user);
                $remittance->commission_tx_id = $commissionTx->id;
            }

            $remittance->save();

            return $remittance;
        });
    }

    /**
     * Book an outgoing remittance: collect money from a walk-in client and
     * assign the payout to an agent.
     *
     * Booking entries: DR payment_account (receive_amount)
     *                  CR agent.payable_account (send_amount).
     * The numeric spread between the two sides is booked as commission
     * (revenue when positive, expense when negative) so the transaction
     * always stays balanced; commission_tx_id points at the booking
     * transaction.
     *
     * @param  array  $data  Validated request data
     * @param  User  $user  Acting user
     */
    public function createOutgoing(array $data, User $user): Remittance
    {
        return DB::transaction(function () use ($data, $user) {
            $officeId = $user->office_id;

            $agent = Agent::whereKey($data['agent_id'])->lockForUpdate()->firstOrFail();

            $remittance = Remittance::create([
                'office_id' => $officeId,
                'remittance_number' => $this->nextNumber($officeId, 'remittance_out'),
                'direction' => 'outgoing',
                'status' => 'pending',
                'agent_id' => $agent->id,
                'sender_name' => $data['sender_name'],
                'sender_phone' => $data['sender_phone'] ?? null,
                'receiver_name' => $data['receiver_name'],
                'receiver_phone' => $data['receiver_phone'] ?? null,
                'send_amount' => $data['send_amount'],
                'send_currency_id' => $data['send_currency_id'],
                'receive_amount' => $data['receive_amount'],
                'receive_currency_id' => $data['receive_currency_id'],
                'exchange_rate' => $data['exchange_rate'],
                'commission_amount' => $data['commission_amount'] ?? 0,
                'commission_currency_id' => $data['commission_currency_id'] ?? null,
                'commission_type' => $data['commission_type'] ?? null,
                'payment_account_id' => $data['payment_account_id'],
                'notes' => $data['notes'] ?? null,
                'created_by' => $user->id,
            ]);

            $entries = [
                [
                    'account_id' => $data['payment_account_id'],
                    'entry_type' => 'debit',
                    'amount' => $data['receive_amount'],
                    'currency_id' => $data['receive_currency_id'],
                ],
                [
                    'account_id' => $this->agents->resolveAgentAccounts($agent, $data['send_currency_id'])->payable_account_id,
                    'entry_type' => 'credit',
                    'amount' => $data['send_amount'],
                    'currency_id' => $data['send_currency_id'],
                ],
            ];

            $spread = round((float) $data['receive_amount'] - (float) $data['send_amount'], 4);

            if (abs($spread) > 0.0001) {
                $spreadEntry = $spread > 0
                    ? [
                        'account_id' => $this->findOfficeAccountByType($officeId, 'commission_revenue', $data['receive_currency_id'])->id,
                        'entry_type' => 'credit',
                        'amount' => abs($spread),
                        'currency_id' => $data['receive_currency_id'],
                    ]
                    : [
                        'account_id' => $this->findOfficeAccountByType($officeId, 'commission_expense', $data['receive_currency_id'])->id,
                        'entry_type' => 'debit',
                        'amount' => abs($spread),
                        'currency_id' => $data['receive_currency_id'],
                    ];

                $entries[] = $spreadEntry;
            }

            $booking = $this->accounting->createTransaction([
                'office_id' => $officeId,
                'tx_type' => 'remittance',
                'description' => 'Outgoing remittance '.$remittance->remittance_number,
                'created_by' => $user->id,
                'reference_type' => 'remittance',
                'reference_id' => $remittance->id,
                'entries' => $entries,
            ]);

            $remittance->booking_tx_id = $booking->id;
            $remittance->commission_tx_id = $booking->id;
            $remittance->commission_amount = abs($spread);
            $remittance->save();

            return $remittance;
        });
    }

    /**
     * Complete the payout of a pending remittance.
     *
     * Incoming: creates a settlement transaction
     *      DR remittance_payable (send_amount)
     *      CR payment_account    (receive_amount)
     * with a spread balancing line when needed.
     * Outgoing: status change only (no new journal entries).
     *
     * @param  string  $remittanceId  UUID of the remittance
     * @param  string|null  $paymentAccountId  UUID of the payout cash account (incoming)
     * @param  User  $user  Acting user
     *
     * @throws RuntimeException when the remittance is not pending
     */
    public function complete(string $remittanceId, ?string $paymentAccountId, User $user): Remittance
    {
        return DB::transaction(function () use ($remittanceId, $paymentAccountId, $user) {
            $remittance = Remittance::whereKey($remittanceId)->lockForUpdate()->firstOrFail();

            if ($remittance->status !== 'pending') {
                throw new RuntimeException('Only pending remittances can be completed.');
            }

            if ($remittance->direction === 'incoming') {
                $officeId = $user->office_id;
                $agent = $remittance->agent;

                $payableAccount = $this->findOfficeAccountByType($officeId, 'remittance_payable', $remittance->send_currency_id);

                $entries = [
                    [
                        'account_id' => $payableAccount->id,
                        'entry_type' => 'debit',
                        'amount' => $remittance->send_amount,
                        'currency_id' => $remittance->send_currency_id,
                    ],
                    [
                        'account_id' => $paymentAccountId,
                        'entry_type' => 'credit',
                        'amount' => $remittance->receive_amount,
                        'currency_id' => $remittance->receive_currency_id,
                    ],
                ];

                $spread = round((float) $remittance->send_amount - (float) $remittance->receive_amount, 4);

                if (abs($spread) > 0.0001) {
                    $entries[] = $spread > 0
                        ? [
                            'account_id' => $this->findOfficeAccountByType($officeId, 'commission_revenue', $remittance->receive_currency_id)->id,
                            'entry_type' => 'credit',
                            'amount' => $spread,
                            'currency_id' => $remittance->receive_currency_id,
                        ]
                        : [
                            'account_id' => $this->findOfficeAccountByType($officeId, 'commission_expense', $remittance->receive_currency_id)->id,
                            'entry_type' => 'debit',
                            'amount' => abs($spread),
                            'currency_id' => $remittance->receive_currency_id,
                        ];
                }

                $settlement = $this->accounting->createTransaction([
                    'office_id' => $officeId,
                    'tx_type' => 'remittance',
                    'description' => 'Payout of incoming remittance '.$remittance->remittance_number,
                    'created_by' => $user->id,
                    'reference_type' => 'remittance',
                    'reference_id' => $remittance->id,
                    'entries' => $entries,
                ]);

                $remittance->settlement_tx_id = $settlement->id;
                $remittance->payment_account_id = $paymentAccountId;
            }

            $remittance->status = 'completed';
            $remittance->completed_at = now();
            $remittance->completed_by = $user->id;
            $remittance->save();

            return $remittance;
        });
    }

    /**
     * Cancel a pending remittance by voiding its booking (and commission)
     * transactions through reversal entries.
     *
     * @param  string  $remittanceId  UUID of the remittance
     * @param  string  $reason  Why it is cancelled
     * @param  User  $user  Acting user (owner only, enforced by the route)
     *
     * @throws RuntimeException when the remittance is not pending
     */
    public function cancel(string $remittanceId, string $reason, User $user): Remittance
    {
        return DB::transaction(function () use ($remittanceId, $reason, $user) {
            $remittance = Remittance::whereKey($remittanceId)->lockForUpdate()->firstOrFail();

            if ($remittance->status !== 'pending') {
                throw new RuntimeException('Only pending remittances can be cancelled.');
            }

            if ($remittance->booking_tx_id && ! $remittance->bookingTransaction->is_void) {
                $this->accounting->voidTransaction($remittance->booking_tx_id, $user->id, 'Remittance cancelled: '.$reason);
            }

            if ($remittance->commission_tx_id
                && $remittance->commission_tx_id !== $remittance->booking_tx_id
                && ! $remittance->commissionTransaction->is_void) {
                $this->accounting->voidTransaction($remittance->commission_tx_id, $user->id, 'Remittance commission reversed: '.$reason);
            }

            $remittance->status = 'cancelled';
            $remittance->cancelled_at = now();
            $remittance->cancelled_by = $user->id;
            $remittance->cancel_reason = $reason;
            $remittance->save();

            return $remittance;
        });
    }

    /**
     * Create the commission transaction for an incoming remittance.
     * earned => DR agent.receivable, CR commission_revenue
     * paid   => DR commission_expense, CR agent.payable.
     */
    private function createCommissionTransaction(Remittance $remittance, Agent $agent, string $officeId, User $user)
    {
        $amount = $remittance->commission_amount;
        $currencyId = $remittance->commission_currency_id ?? $remittance->send_currency_id;

        if ($remittance->commission_type === 'paid') {
            $entries = [
                [
                    'account_id' => $this->findOfficeAccountByType($officeId, 'commission_expense', $currencyId)->id,
                    'entry_type' => 'debit',
                    'amount' => $amount,
                    'currency_id' => $currencyId,
                ],
                [
                    'account_id' => $this->agents->resolveAgentAccounts($agent, $currencyId)->payable_account_id,
                    'entry_type' => 'credit',
                    'amount' => $amount,
                    'currency_id' => $currencyId,
                ],
            ];
        } else {
            $entries = [
                [
                    'account_id' => $this->agents->resolveAgentAccounts($agent, $currencyId)->receivable_account_id,
                    'entry_type' => 'debit',
                    'amount' => $amount,
                    'currency_id' => $currencyId,
                ],
                [
                    'account_id' => $this->findOfficeAccountByType($officeId, 'commission_revenue', $currencyId)->id,
                    'entry_type' => 'credit',
                    'amount' => $amount,
                    'currency_id' => $currencyId,
                ],
            ];
        }

        return $this->accounting->createTransaction([
            'office_id' => $officeId,
            'tx_type' => 'commission',
            'description' => 'Commission for remittance '.$remittance->remittance_number,
            'created_by' => $user->id,
            'reference_type' => 'remittance',
            'reference_id' => $remittance->id,
            'entries' => $entries,
        ]);
    }

    /**
     * Generate the next office-scoped document number via fn_next_sequence().
     */
    private function nextNumber(string $officeId, string $type): string
    {
        return DB::select('SELECT fn_next_sequence(?, ?) AS number', [$officeId, $type])[0]->number;
    }

    /**
     * Find an office account by its account type code and currency,
     * creating it (office_shared) when it does not exist yet.
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
        $currencyCode = Currency::findOrFail($currencyId)->code;

        return Account::create([
            'office_id' => $officeId,
            'account_type_id' => $type->id,
            'currency_id' => $currencyId,
            'name' => $type->name.' - '.$currencyCode,
            'visibility' => 'office_shared',
            'current_balance' => 0,
            'is_active' => true,
        ]);
    }
}
