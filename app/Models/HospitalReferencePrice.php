<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([])]
class HospitalReferencePrice extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $hidden = ['raw_cells'];

    public function import(): BelongsTo
    {
        return $this->belongsTo(HospitalPriceImport::class, 'import_id');
    }

    protected function casts(): array
    {
        return ['amount_micros' => 'integer', 'issues' => 'array', 'raw_cells' => 'array'];
    }
}
