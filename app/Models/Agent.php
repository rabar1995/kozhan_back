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

    protected $appends = ['classification', 'logo_url'];

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
            'commission_rate' => 'decimal:4',
            'is_active' => 'boolean',
        ];
    }
}
