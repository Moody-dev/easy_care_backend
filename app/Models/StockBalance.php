<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([])]
class StockBalance extends Model
{
    public $timestamps = false;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $primaryKey = 'last_movement_id';

    public $incrementing = false;

    public function save(array $options = []): never
    {
        throw new \LogicException('Stock balances are a read-only projection of the ledger.');
    }

    public function delete(): never
    {
        throw new \LogicException('Post a reasoned stock movement to change a balance.');
    }

    public function drug(): BelongsTo
    {
        return $this->belongsTo(Drug::class, 'drug_id');
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(DrugBatch::class, 'batch_id');
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(StockStore::class, 'store_id');
    }

    public function lastMovement(): BelongsTo
    {
        return $this->belongsTo(StockMovement::class, 'last_movement_id');
    }

    protected function casts(): array
    {
        return ['quantity_micros' => 'integer', 'movement_count' => 'integer', 'last_movement_id' => 'integer', 'last_changed_at' => 'immutable_datetime'];
    }
}
