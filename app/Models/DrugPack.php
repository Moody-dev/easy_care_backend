<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([])]
class DrugPack extends Model
{
    /** @use HasFactory<\Database\Factories\DrugPackFactory> */
    use HasFactory;

    public $timestamps = false;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    public function convertToBase(mixed $packs): string
    {
        return \App\Support\FixedPoint::convert($packs, \App\Support\FixedPoint::format($this->base_quantity_micros));
    }

    public function drug(): BelongsTo
    {
        return $this->belongsTo(Drug::class, 'drug_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class, 'pack_id');
    }

    protected function casts(): array
    {
        return ['recorded_at' => 'immutable_datetime', 'version' => 'integer', 'is_active' => 'boolean', 'base_quantity_micros' => 'integer'];
    }
}
