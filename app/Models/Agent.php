<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Agent extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected $appends = ['classification', 'logo_url', 'balances'];

    public function currencyAccounts(): HasMany
    {
        return $this->hasMany(AgentCurrencyAccount::class);
    }

    public function office(): BelongsTo
    {
        return $this->belongsTo(Office::class);
    }

    public function receivableAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'receivable_account_id');
    }

    public function payableAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'payable_account_id');
    }

    public function balanceCurrency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'balance_currency_id');
    }

    public function remittances(): HasMany
    {
        return $this->hasMany(Remittance::class);
    }

    /**
     * Classification derived from net_balance:
     * net_balance > 0 => debtor (agent owes office),
     * net_balance < 0 => creditor (office owes agent),
     * net_balance = 0 => settled.
     */
    /**
     * Logo: manually pasted image URL.
     */
    protected function logoUrl(): Attribute
    {
        return Attribute::get(function () {
            $url = $this->getAttributeFromArray('logo_url');

            return $url ? (string) $url : null;
        });
    }

    protected function classification(): Attribute
    {
        return Attribute::get(function () {
            $balance = (float) $this->net_balance;

            if ($balance > 0) {
                return 'debtor';
            }

            if ($balance < 0) {
                return 'creditor';
            }

            return 'settled';
        });
    }

    protected function casts(): array
    {
        return [
            'net_balance' => 'decimal:4',
            'is_active' => 'boolean',
            'allow_all_currencies' => 'boolean',
        ];
    }

    /**
     * Per-currency net balances for multi-currency agents:
     * [{currency, code, net, classification}] — a single signed amount
     * per currency (positive = agent owes office, negative = office
     * owes agent). Works for legacy receivable/payable pairs and the
     * new single agent_wallet accounts.
     */
    protected function balances(): Attribute
    {
        return Attribute::get(function () {
            $rows = $this->relationLoaded('currencyAccounts')
                ? $this->currencyAccounts
                : $this->currencyAccounts()->with(['account:id,current_balance', 'receivableAccount:id,current_balance', 'payableAccount:id,current_balance', 'currency'])->get();

            return $rows->map(function (AgentCurrencyAccount $pair) {
                if ($pair->account) {
                    $net = (float) ($pair->account->current_balance ?? 0);
                } else {
                    $receivable = (float) ($pair->receivableAccount?->current_balance ?? 0);
                    $payable = (float) ($pair->payableAccount?->current_balance ?? 0);
                    $net = $receivable - $payable;
                }

                return [
                    'currency_id' => $pair->currency_id,
                    'currency' => $pair->currency?->code,
                    'net' => $net,
                    'classification' => $net > 0 ? 'debtor' : ($net < 0 ? 'creditor' : 'settled'),
                ];
            })->values();
        });
    }
}
