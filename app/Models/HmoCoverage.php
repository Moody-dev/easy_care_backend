<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([])]
class HmoCoverage extends Model
{
    /** @use HasFactory<\Database\Factories\HmoCoverageFactory> */
    use HasFactory;
    public $timestamps = false;
    protected $table = 'hmo_coverages';
    protected $dateFormat = 'Y-m-d H:i:s.u';
    public function account(): BelongsTo { return $this->belongsTo(BillingAccount::class,'account_id'); }
    public function plan(): BelongsTo { return $this->belongsTo(HmoPlan::class,'plan_id'); }
    protected function casts(): array { return ['snapshot'=>'array', 'valid_from'=>'immutable_date', 'valid_until'=>'immutable_date', 'recorded_at'=>'immutable_datetime']; }
}
