<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([])]
class InvestigationOrder extends Model
{
    /** @use HasFactory<\Database\Factories\InvestigationOrderFactory> */
    use HasFactory;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    public function scopeVisibleTo(Builder $query, User $actor): Builder
    {
        if (! $actor->is_active || ! $actor->hasPermission('investigations.view')) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereHas('encounter', fn (Builder $visits): Builder => $visits->visibleTo($actor));
    }

    public function encounter(): BelongsTo { return $this->belongsTo(Encounter::class); }
    public function patient(): BelongsTo { return $this->belongsTo(Patient::class); }
    public function orderedBy(): BelongsTo { return $this->belongsTo(User::class, 'ordered_by'); }
    public function assignment(): BelongsTo { return $this->belongsTo(DoctorAssignment::class, 'assignment_id'); }
    public function items(): HasMany { return $this->hasMany(InvestigationItem::class, 'order_id')->orderBy('id'); }
    public function revisions(): HasMany { return $this->hasMany(InvestigationRevision::class, 'order_id')->orderBy('version'); }

    protected function casts(): array
    {
        return ['version' => 'integer', 'assignment_version' => 'integer', 'requested_at' => 'immutable_datetime', 'last_changed_at' => 'immutable_datetime'];
    }
}
