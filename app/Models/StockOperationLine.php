<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([])]
class StockOperationLine extends Model
{
    /** @use HasFactory<\Database\Factories\StockOperationLineFactory> */
    use HasFactory;

    public $timestamps = false;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    public function movements(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(StockMovement::class, 'stock_operation_legs', 'line_id', 'movement_id')->withPivot('store_id')->orderBy('stock_movements.id');
    }

    public function operation(): BelongsTo
    {
        return $this->belongsTo(StockOperation::class, 'operation_id');
    }

    public function drug(): BelongsTo
    {
        return $this->belongsTo(Drug::class, 'drug_id');
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(DrugBatch::class, 'batch_id');
    }

    public function pack(): BelongsTo
    {
        return $this->belongsTo(DrugPack::class, 'pack_id');
    }

    protected function casts(): array
    {
        return [
            'drug_version' => 'integer',
            'quantity_micros' => 'integer',
            'pack_count_micros' => 'integer',
            'unit_cost_micros' => 'integer',
            'source_balance_version' => 'integer',
            'catalog_snapshot' => 'array',
        ];
    }
}
