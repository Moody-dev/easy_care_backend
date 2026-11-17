<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([])]
class StockMovement extends Model
{
    /** @use HasFactory<\Database\Factories\StockMovementFactory> */
    use HasFactory;

    public $timestamps = false;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    public const KINDS = ['opening', 'receipt', 'return', 'dispense', 'damage', 'expiry', 'transfer', 'adjustment', 'reversal'];

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

    public function pack(): BelongsTo
    {
        return $this->belongsTo(DrugPack::class, 'pack_id');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'supplier_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function reverses(): BelongsTo
    {
        return $this->belongsTo(StockMovement::class, 'reversal_of');
    }

    public function reversal(): HasOne
    {
        return $this->hasOne(StockMovement::class, 'reversal_of');
    }

    protected function casts(): array
    {
        return ['recorded_at' => 'immutable_datetime', 'quantity_micros' => 'integer', 'unit_cost_micros' => 'integer'];
    }
}
