<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([])]
class Reminder extends Model
{
    /** @use HasFactory<\Database\Factories\ReminderFactory> */
    use HasFactory;
    protected $table = 'staff_reminders';
    protected $dateFormat = 'Y-m-d H:i:s.u';
    public function owner(): BelongsTo { return $this->belongsTo(User::class, 'owner_id'); }
    public function patient(): BelongsTo { return $this->belongsTo(Patient::class); }
    public function encounter(): BelongsTo { return $this->belongsTo(Encounter::class); }
    public function revisions(): HasMany { return $this->hasMany(ReminderRevision::class); }
    public function revision(): HasOne { return $this->hasOne(ReminderRevision::class)->ofMany('version', 'max'); }
    protected function casts(): array
    {
        return ['version'=>'integer','is_active'=>'boolean','next_sequence'=>'integer','next_due_at'=>'immutable_datetime',
            'created_at'=>'immutable_datetime','updated_at'=>'immutable_datetime'];
    }
}
