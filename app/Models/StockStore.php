<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([])]
class StockStore extends Model
{
    /** @use HasFactory<\Database\Factories\StockStoreFactory> */
    use HasFactory;

    public $timestamps = false;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    public const KINDS = ['main', 'dispensary', 'department'];

    public function accesses(): HasMany
    {
        return $this->hasMany(StockStoreAccess::class, 'store_id');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'department_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class, 'store_id');
    }

    public function balances(): HasMany
    {
        return $this->hasMany(StockBalance::class, 'store_id');
    }

    protected function casts(): array
    {
        return ['recorded_at' => 'immutable_datetime', 'is_active' => 'boolean', 'version' => 'integer', 'updated_at' => 'immutable_datetime'];
    }
}
