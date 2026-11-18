<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([])]
class StaffNotification extends Model
{
    /** @use HasFactory<\Database\Factories\StaffNotificationFactory> */
    use HasFactory;
    protected $dateFormat = 'Y-m-d H:i:s.u';
    public function recipient(): BelongsTo { return $this->belongsTo(User::class, 'recipient_id'); }
    protected function casts(): array
    {
        return ['version'=>'integer','available_at'=>'immutable_datetime','read_at'=>'immutable_datetime','created_at'=>'immutable_datetime','updated_at'=>'immutable_datetime'];
    }
}
