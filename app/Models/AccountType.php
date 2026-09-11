<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AccountType extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    const UPDATED_AT = null;

    public function accounts(): HasMany
    {
        return $this->hasMany(Account::class);
    }

    public function scopeAssets(Builder $query): Builder
    {
        return $query->where('category', 'asset');
    }

    public function scopeLiabilities(Builder $query): Builder
    {
        return $query->where('category', 'liability');
    }

    public function scopeRevenue(Builder $query): Builder
    {
        return $query->where('category', 'revenue');
    }

    public function scopeExpense(Builder $query): Builder
    {
        return $query->where('category', 'expense');
    }

    protected function casts(): array
    {
        return [
            'is_system' => 'boolean',
        ];
    }
}
