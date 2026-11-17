<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([])]
class Drug extends Model
{
    /** @use HasFactory<\Database\Factories\DrugFactory> */
    use HasFactory;

    public $timestamps = false;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    public function baseUnit(): BelongsTo
    {
        return $this->belongsTo(StockUnit::class, 'base_unit_id');
    }

    public function packs(): HasMany
    {
        return $this->hasMany(DrugPack::class, 'drug_id');
    }

    public function batches(): HasMany
    {
        return $this->hasMany(DrugBatch::class, 'drug_id');
    }

    public function prices(): HasMany
    {
        return $this->hasMany(DrugPrice::class, 'drug_id');
    }

    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class, 'drug_id');
    }

    public function balances(): HasMany
    {
        return $this->hasMany(StockBalance::class, 'drug_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    protected function casts(): array
    {
        return ['recorded_at' => 'immutable_datetime', 'is_active' => 'boolean', 'version' => 'integer', 'quantity_scale' => 'integer', 'updated_at' => 'immutable_datetime'];
    }
}
