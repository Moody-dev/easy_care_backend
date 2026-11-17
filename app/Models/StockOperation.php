<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([])]
class StockOperation extends Model
{
    /** @use HasFactory<\Database\Factories\StockOperationFactory> */
    use HasFactory;

    public $timestamps = false;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    public const KINDS = ['receipt', 'opening', 'transfer', 'requisition', 'adjustment_in', 'adjustment_out', 'damage', 'expiry'];

    public function scopeVisibleTo(\Illuminate\Database\Eloquent\Builder $query, User $actor): \Illuminate\Database\Eloquent\Builder
    {
        foreach (['source_store_id', 'destination_store_id'] as $column) {
            $query->where(function (\Illuminate\Database\Eloquent\Builder $stores) use ($actor, $column): void {
                $stores->whereNull($column)->orWhereIn($column, StockStoreAccess::query()->select('store_id')->where('user_id', $actor->id)->where('is_active', true));
            });
        }

        return $query;
    }

    public function sourceStore(): BelongsTo
    {
        return $this->belongsTo(StockStore::class, 'source_store_id');
    }

    public function destinationStore(): BelongsTo
    {
        return $this->belongsTo(StockStore::class, 'destination_store_id');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'supplier_id');
    }

    public function preparer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'prepared_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function lastActor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'last_actor_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(StockOperationLine::class, 'operation_id');
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(StockOperationRevision::class, 'operation_id');
    }

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'source_store_version' => 'integer',
            'destination_store_version' => 'integer',
            'supplier_version' => 'integer',
            'catalog_snapshot' => 'array',
            'prepared_at' => 'immutable_datetime',
            'reviewed_at' => 'immutable_datetime',
            'posted_at' => 'immutable_datetime',
            'last_changed_at' => 'immutable_datetime',
        ];
    }
}
