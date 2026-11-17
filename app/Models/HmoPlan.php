<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([])]
class HmoPlan extends Model
{
    /** @use HasFactory<\Database\Factories\HmoPlanFactory> */
    use HasFactory;
    public $timestamps = false;
    protected $table = 'hmo_plans';
    protected $dateFormat = 'Y-m-d H:i:s.u';
    public function provider(): BelongsTo { return $this->belongsTo(HmoProvider::class,'provider_id'); }
    public function coverages(): HasMany { return $this->hasMany(HmoCoverage::class,'plan_id'); }
    protected function casts(): array { return [ 'recorded_at'=>'immutable_datetime']; }
}
