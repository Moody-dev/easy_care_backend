<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([])]
class AdmissionRevision extends Model
{
    /** @use HasFactory<\Database\Factories\AdmissionRevisionFactory> */
    use HasFactory;

    public $timestamps = false;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    public function admission(): BelongsTo
    {
        return $this->belongsTo(Admission::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    protected function casts(): array
    {
        return ['workflow_version' => 'integer', 'snapshot' => 'array', 'recorded_at' => 'immutable_datetime'];
    }
}
