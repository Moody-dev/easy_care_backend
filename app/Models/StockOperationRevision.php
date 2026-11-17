<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([])]
class StockOperationRevision extends Model
{
    /** @use HasFactory<\Database\Factories\StockOperationRevisionFactory> */
    use HasFactory;

    public $timestamps = false;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    public function operation(): BelongsTo
    {
        return $this->belongsTo(StockOperation::class, 'operation_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'recorded_at' => 'immutable_datetime',
            'snapshot' => 'array',
        ];
    }
}
