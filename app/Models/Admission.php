<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([])]
class Admission extends Model
{
    /** @use HasFactory<\Database\Factories\AdmissionFactory> */
    use HasFactory;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $attributes = ['status' => 'admitted', 'version' => 1];

    public const TRANSITIONS = ['admitted' => ['discharge_authorized'], 'discharge_authorized' => ['discharged'], 'discharged' => []];

    public function encounter(): BelongsTo
    {
        return $this->belongsTo(Encounter::class);
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function admittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admitted_by');
    }

    public function authorizedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'authorized_by');
    }

    public function dischargedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'discharged_by');
    }

    public function assessment(): HasOne
    {
        return $this->hasOne(AccommodationAssessment::class);
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(BedAllocation::class)->orderBy('assigned_at')->orderBy('id');
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(AdmissionRevision::class)->orderBy('workflow_version');
    }

    public function scopeVisibleTo(Builder $query, User $viewer): Builder
    {
        if (! $viewer->is_active || ! $viewer->hasPermission('admissions.view')) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereHas('encounter', fn (Builder $visits): Builder => $visits->visibleTo($viewer));
    }

    public function currentOccupancy(): HasOne
    {
        return $this->hasOne(CurrentBedOccupancy::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', ['admitted', 'discharge_authorized']);
    }

    public function canTransitionTo(string $status): bool
    {
        return in_array($status, self::TRANSITIONS[$this->status] ?? [], true);
    }

    protected function casts(): array
    {
        return ['admitted_at' => 'immutable_datetime', 'authorized_at' => 'immutable_datetime',
            'discharged_at' => 'immutable_datetime', 'version' => 'integer'];
    }
}
