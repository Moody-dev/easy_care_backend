<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([])]
class ServicePrice extends Model
{
    /** @use HasFactory<\Database\Factories\ServicePriceFactory> */
    use HasFactory;

    public $timestamps = false;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class, 'service_id');
    }

    protected function casts(): array
    {
        return ['amount_micros' => 'integer', 'version' => 'integer', 'is_active' => 'boolean', 'effective_at' => 'immutable_datetime', 'recorded_at' => 'immutable_datetime'];
    }
}
