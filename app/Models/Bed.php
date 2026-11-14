<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['label', 'daily_rate'])]
class Bed extends Model
{
    /** @use HasFactory<\Database\Factories\BedFactory> */
    use HasFactory;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $attributes = ['is_active' => true, 'version' => 1];

    public function ward(): BelongsTo
    {
        return $this->belongsTo(Ward::class);
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(BedAllocation::class)->orderBy('assigned_at')->orderBy('id');
    }

    public function currentOccupancy(): HasOne
    {
        return $this->hasOne(CurrentBedOccupancy::class, 'bed_id');
    }

    public function scopeAvailable(Builder $query): Builder
    {
        return $query->where('is_active', true)->whereHas('ward', fn (Builder $wards): Builder => $wards->where('is_active', true))
            ->whereDoesntHave('currentOccupancy');
    }

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'version' => 'integer', 'daily_rate' => 'decimal:2'];
    }
}
