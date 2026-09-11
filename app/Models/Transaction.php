<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Transaction extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    const UPDATED_AT = null;

    public function office(): BelongsTo
    {
        return $this->belongsTo(Office::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function journalEntries(): HasMany
    {
        return $this->hasMany(JournalEntry::class);
    }

    public function reference(): MorphTo
    {
        return $this->morphTo();
    }

    public function scopeNotVoid(Builder $query): Builder
    {
        return $query->where('is_void', false);
    }

    public function scopeByType(Builder $query, string $type): Builder
    {
        return $query->where('tx_type', $type);
    }

    protected function casts(): array
    {
        return [
            'is_void' => 'boolean',
        ];
    }
}
