<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([])]
class BedAllocation extends Model
{
    /** @use HasFactory<\Database\Factories\BedAllocationFactory> */
    use HasFactory;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $attributes = ['version' => 1];

    public function bed(): BelongsTo
    {
        return $this->belongsTo(Bed::class);
    }

    public function admission(): BelongsTo
    {
        return $this->belongsTo(Admission::class);
    }

    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    public function releasedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'released_by');
    }

    public function scopeCurrent(Builder $query): Builder
    {
        return $query->whereNull('released_at');
    }

    protected function casts(): array
    {
        return ['assigned_at' => 'immutable_datetime', 'released_at' => 'immutable_datetime', 'version' => 'integer'];
    }
}
