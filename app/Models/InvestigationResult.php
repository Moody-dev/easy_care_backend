<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([])]
class InvestigationResult extends Model
{
    /** @use HasFactory<\Database\Factories\InvestigationResultFactory> */
    use HasFactory;

    public const STATUSES = ['draft', 'submitted', 'verified', 'released', 'voided'];
    protected $dateFormat = 'Y-m-d H:i:s.u';

    public function scopeVisibleTo(Builder $query, User $actor): Builder
    {
        if (! $actor->is_active || ! $actor->hasPermission('investigations.results.view')) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereHas('item', fn (Builder $items): Builder => $items->visibleTo($actor))
            ->where(function (Builder $results) use ($actor): void {
                $results->where('status', 'released');
                if ($actor->roles()->where('code', 'diagnostics-and-radiologist')->exists()) {
                    if ($actor->hasPermission('investigations.results.enter')) {
                        $results->orWhere('author_id', $actor->id);
                    }
                    if ($actor->hasPermission('investigations.results.verify')) {
                        $results->orWhereIn('status', ['submitted', 'verified']);
                    }
                }
            });
    }

    public function item(): BelongsTo { return $this->belongsTo(InvestigationItem::class, 'item_id'); }
    public function schema(): BelongsTo { return $this->belongsTo(InvestigationSchema::class, 'schema_id'); }
    public function encounter(): BelongsTo { return $this->belongsTo(Encounter::class); }
    public function author(): BelongsTo { return $this->belongsTo(User::class, 'author_id'); }
    public function verifier(): BelongsTo { return $this->belongsTo(User::class, 'verified_by'); }
    public function releaser(): BelongsTo { return $this->belongsTo(User::class, 'released_by'); }
    public function previous(): BelongsTo { return $this->belongsTo(self::class, 'previous_result_id'); }
    public function replacements(): HasMany { return $this->hasMany(self::class, 'previous_result_id'); }
    public function attachments(): HasMany { return $this->hasMany(InvestigationResultAttachment::class, 'result_id')->orderBy('file_id'); }
    public function revisions(): HasMany { return $this->hasMany(InvestigationResultRevision::class, 'result_id')->orderBy('version'); }

    protected function casts(): array
    {
        return ['sequence' => 'integer', 'version' => 'integer', 'fields' => 'array', 'last_changed_at' => 'immutable_datetime',
            'submitted_at' => 'immutable_datetime', 'verified_at' => 'immutable_datetime', 'released_at' => 'immutable_datetime', 'voided_at' => 'immutable_datetime'];
    }
}
