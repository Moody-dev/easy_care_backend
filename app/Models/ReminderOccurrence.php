<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([])]
class ReminderOccurrence extends Model
{
    /** @use HasFactory<\Database\Factories\ReminderOccurrenceFactory> */
    use HasFactory;
    public $timestamps = false;
    protected $dateFormat = 'Y-m-d H:i:s.u';
    public function reminder(): BelongsTo { return $this->belongsTo(Reminder::class); }
    public function revision(): BelongsTo { return $this->belongsTo(ReminderRevision::class); }
    public function deliveries(): HasMany { return $this->hasMany(ReminderDelivery::class, 'occurrence_id'); }
    protected function casts(): array { return ['sequence'=>'integer','due_at'=>'immutable_datetime','processed_at'=>'immutable_datetime']; }
}
