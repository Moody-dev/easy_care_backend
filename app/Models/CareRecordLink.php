<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([])]
class CareRecordLink extends Model
{
    use HasFactory;
    public $timestamps = false;
    public function program(): BelongsTo { return $this->belongsTo(CareProgram::class, 'care_program_id'); }
    public function record(): BelongsTo { return $this->belongsTo(ClinicalRecord::class, 'clinical_record_id'); }
    public function birth(): BelongsTo { return $this->belongsTo(Birth::class); }
}
