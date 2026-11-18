<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable([])]
class ReminderRevision extends Model
{
    /** @use HasFactory<\Database\Factories\ReminderRevisionFactory> */
    use HasFactory;
    public $timestamps = false;
    protected $dateFormat = 'Y-m-d H:i:s.u';
    protected $hidden = ['content'];
    public function reminder(): BelongsTo { return $this->belongsTo(Reminder::class); }
    public function recipients(): BelongsToMany { return $this->belongsToMany(User::class, 'reminder_recipients', 'revision_id', 'user_id'); }
    protected function casts(): array
    {
        return ['content'=>'encrypted:array','starts_at'=>'immutable_datetime','ends_at'=>'immutable_datetime','recorded_at'=>'immutable_datetime',
            'version'=>'integer','interval'=>'integer','occurrence_count'=>'integer'];
    }
}
