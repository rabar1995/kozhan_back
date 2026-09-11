<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ExpenseCategory extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    const UPDATED_AT = null;

    public function office(): BelongsTo
    {
        return $this->belongsTo(Office::class);
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }
}
