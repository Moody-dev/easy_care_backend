<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([])]
class MedicationAuthorization extends Model
{
    /** @use HasFactory<\Database\Factories\MedicationAuthorizationFactory> */
    use HasFactory;

    public $timestamps = false;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    public function item(): BelongsTo
    {
        return $this->belongsTo(PrescriptionItem::class, 'prescription_item_id');
    }

    public function drug(): BelongsTo
    {
        return $this->belongsTo(Drug::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(MedicationEvent::class, 'authorization_id');
    }

    public function scopeVisibleTo(Builder $query, User $actor): Builder
    {
        return $query->whereHas('item.prescription.record', fn (Builder $records): Builder => $records->prescriptionsVisibleTo($actor));
    }

    protected function casts(): array
    {
        return ['snapshot' => 'array', 'recorded_at' => 'immutable_datetime', 'maximum_quantity_micros' => 'integer'];
    }
}
