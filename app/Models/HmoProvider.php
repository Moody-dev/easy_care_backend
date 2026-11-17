<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([])]
class HmoProvider extends Model
{
    /** @use HasFactory<\Database\Factories\HmoProviderFactory> */
    use HasFactory;
    public $timestamps = false;
    protected $table = 'hmo_providers';
    protected $dateFormat = 'Y-m-d H:i:s.u';
    public function plans(): HasMany { return $this->hasMany(HmoPlan::class,'provider_id'); }
    protected function casts(): array { return [ 'recorded_at'=>'immutable_datetime']; }
}
