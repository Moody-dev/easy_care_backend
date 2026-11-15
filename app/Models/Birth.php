<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([])]
class Birth extends Model
{
    use HasFactory;
    public $timestamps = false;
    protected $dateFormat = 'Y-m-d H:i:s.u';
    public function delivery(): BelongsTo { return $this->belongsTo(CareProgram::class, 'delivery_id'); }
    public function encounter(): BelongsTo { return $this->belongsTo(Encounter::class); }
    public function patientLink(): HasOne { return $this->hasOne(BirthPatientLink::class); }
    protected function casts(): array { return ['birth_order' => 'integer', 'recorded_at' => 'immutable_datetime']; }
}
