<?php

namespace App\Models;

use App\Scopes\VisibilityScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Account extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::addGlobalScope(new VisibilityScope);
    }

    public function office(): BelongsTo
    {
        return $this->belongsTo(Office::class);
    }

    public function accountType(): BelongsTo
    {
        return $this->belongsTo(AccountType::class);
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    public function journalEntries(): HasMany
    {
        return $this->hasMany(JournalEntry::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeByType(Builder $query, string $typeCode): Builder
    {
        return $query->whereHas('accountType', function (Builder $q) use ($typeCode) {
            $q->where('code', $typeCode);
        });
    }

    public function scopeByCurrency(Builder $query, string $currencyId): Builder
    {
        return $query->where('currency_id', $currencyId);
    }

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

    protected function casts(): array
    {
        return [
            'current_balance' => 'decimal:4',
            'metadata' => 'array',
            'is_active' => 'boolean',
        ];
    }
}
