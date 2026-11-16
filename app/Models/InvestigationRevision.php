<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([])]
class InvestigationRevision extends Model
{
    /** @use HasFactory<\Database\Factories\InvestigationRevisionFactory> */
    use HasFactory;

    public $timestamps = false;
    protected $dateFormat = 'Y-m-d H:i:s.u';
    public function order(): BelongsTo { return $this->belongsTo(InvestigationOrder::class, 'order_id'); }
    public function item(): BelongsTo { return $this->belongsTo(InvestigationItem::class, 'item_id'); }
    public function actor(): BelongsTo { return $this->belongsTo(User::class, 'actor_id'); }

    protected function casts(): array
    {
        return ['version' => 'integer', 'snapshot' => 'array', 'recorded_at' => 'immutable_datetime'];
    }
}
