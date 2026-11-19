<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([])]
class HospitalPriceImport extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $hidden = ['sources'];

    public function prices(): HasMany
    {
        return $this->hasMany(HospitalReferencePrice::class, 'import_id');
    }

    protected function casts(): array
    {
        return ['sources' => 'encrypted:array', 'effective_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime'];
    }
}
