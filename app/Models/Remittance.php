<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Remittance extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    const UPDATED_AT = null;

    public function office(): BelongsTo
    {
        return $this->belongsTo(Office::class);
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    public function sendCurrency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'send_currency_id');
    }

    public function receiveCurrency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'receive_currency_id');
    }

    public function commissionCurrency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'commission_currency_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function bookingTransaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'booking_tx_id');
    }

    public function settlementTransaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'settlement_tx_id');
    }

    public function commissionTransaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'commission_tx_id');
    }

    public function paymentAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'payment_account_id');
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', 'pending');
    }

    public function scopeCompleted(Builder $query): Builder
    {
        return $query->where('status', 'completed');
    }

    public function scopeCancelled(Builder $query): Builder
    {
        return $query->where('status', 'cancelled');
    }

    public function scopeIncoming(Builder $query): Builder
    {
        return $query->where('direction', 'incoming');
    }

    public function scopeOutgoing(Builder $query): Builder
    {
        return $query->where('direction', 'outgoing');
    }

    protected function casts(): array
    {
        return [
            'send_amount' => 'decimal:4',
            'receive_amount' => 'decimal:4',
            'exchange_rate' => 'decimal:6',
            'commission_amount' => 'decimal:4',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }
}
