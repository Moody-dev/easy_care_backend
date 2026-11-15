<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([])]
class BirthPatientLink extends Model
{
    use HasFactory;
    public $timestamps = false;
    protected $dateFormat = 'Y-m-d H:i:s.u';
    public function birth(): BelongsTo { return $this->belongsTo(Birth::class); }
    public function encounter(): BelongsTo { return $this->belongsTo(Encounter::class); }
    public function patient(): BelongsTo { return $this->belongsTo(Patient::class, 'child_patient_id'); }
    protected function casts(): array { return ['verified_at' => 'immutable_datetime']; }
}
