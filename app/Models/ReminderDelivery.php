<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([])]
class ReminderDelivery extends Model
{
    /** @use HasFactory<\Database\Factories\ReminderDeliveryFactory> */
    use HasFactory;
    public $timestamps = false;
    protected $dateFormat = 'Y-m-d H:i:s.u';
    public function occurrence(): BelongsTo { return $this->belongsTo(ReminderOccurrence::class, 'occurrence_id'); }
    public function recipient(): BelongsTo { return $this->belongsTo(User::class, 'recipient_id'); }
    public function notification(): HasOne { return $this->hasOne(StaffNotification::class, 'delivery_id'); }
    protected function casts(): array { return ['recorded_at'=>'immutable_datetime']; }
}
