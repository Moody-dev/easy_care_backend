<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([])]
class CareProgram extends Model
{
    use HasFactory;

    public const TYPES = ['antenatal','postnatal','family_planning','delivery','immunization'];
    protected $dateFormat = 'Y-m-d H:i:s.u';
    protected $attributes = ['status' => 'active', 'version' => 1];

    public function patient(): BelongsTo { return $this->belongsTo(Patient::class); }
    public function encounter(): BelongsTo { return $this->belongsTo(Encounter::class); }
    public function completedEncounter(): BelongsTo { return $this->belongsTo(Encounter::class, 'completed_encounter_id'); }
    public function births(): HasMany { return $this->hasMany(Birth::class, 'delivery_id')->orderBy('birth_order'); }
    public function recordLinks(): HasMany { return $this->hasMany(CareRecordLink::class); }

    public function scopeVisibleTo(Builder $query, User $viewer): Builder
    {
        if (! $viewer->is_active || ! $viewer->hasPermission('care.view')) {
            return $query->whereRaw('1 = 0');
        }
        return $query->whereHas('encounter', fn (Builder $visits): Builder => $visits->visibleTo($viewer));
    }

    protected function casts(): array
    {
        return ['version' => 'integer', 'started_at' => 'immutable_datetime', 'completed_at' => 'immutable_datetime'];
    }
}
