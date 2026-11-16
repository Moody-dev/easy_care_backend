<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([])]
class InvestigationItem extends Model
{
    /** @use HasFactory<\Database\Factories\InvestigationItemFactory> */
    use HasFactory;

    public const STATUSES = ['requested', 'collected', 'processing', 'completed', 'cancelled'];
    public $timestamps = false;
    protected $dateFormat = 'Y-m-d H:i:s.u';

    public function order(): BelongsTo { return $this->belongsTo(InvestigationOrder::class, 'order_id'); }
    public function encounter(): BelongsTo { return $this->belongsTo(Encounter::class); }
    public function test(): BelongsTo { return $this->belongsTo(InvestigationTest::class, 'test_id'); }
    public function schema(): BelongsTo { return $this->belongsTo(InvestigationSchema::class, 'schema_id'); }
    public function collectedBy(): BelongsTo { return $this->belongsTo(User::class, 'collected_by'); }
    public function processingBy(): BelongsTo { return $this->belongsTo(User::class, 'processing_by'); }
    public function cancelledBy(): BelongsTo { return $this->belongsTo(User::class, 'cancelled_by'); }
    public function results(): HasMany { return $this->hasMany(InvestigationResult::class, 'item_id')->orderBy('sequence'); }

    public function scopeVisibleTo(Builder $query, User $actor): Builder
    {
        return $query->whereHas('order', fn (Builder $orders): Builder => $orders->visibleTo($actor));
    }

    protected function casts(): array
    {
        return ['version' => 'integer', 'collected_at' => 'immutable_datetime', 'processing_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime', 'last_event_at' => 'immutable_datetime'];
    }
}
