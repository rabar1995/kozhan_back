<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ExchangeDeal extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    const UPDATED_AT = null;

    public function office(): BelongsTo
    {
        return $this->belongsTo(Office::class);
    }

    /**
     * Wallet the leg-1 amount moved through.
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'account_id');
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'currency_id');
    }

    public function settleAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'settle_account_id');
    }

    public function settleCurrency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'settle_currency_id');
    }

    public function dealParent(): BelongsTo
    {
        return $this->belongsTo(ExchangeDeal::class, 'deal_parent_id');
    }

    /**
     * Settlement legs that close this open deal.
     */
    public function dealSettlements(): HasMany
    {
        return $this->hasMany(ExchangeDeal::class, 'deal_parent_id');
    }

    public function bookingTransaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'booking_tx_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:4',
            'settle_amount' => 'decimal:4',
        ];
    }
}
