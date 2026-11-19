<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([])]
class AllocationRate extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    public function allocation(): BelongsTo
    {
        return $this->belongsTo(BedAllocation::class);
    }

    protected function casts(): array
    {
        return ['daily_rate_kobo' => 'integer', 'recorded_at' => 'immutable_datetime'];
    }
}
