<?php

namespace App\Models;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([])]
class DrugBatch extends Model
{
    /** @use HasFactory<\Database\Factories\DrugBatchFactory> */
    use HasFactory;

    public $timestamps = false;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    /** @return Attribute<never, string|DateTimeInterface|null> */
    protected function manufacturedOn(): Attribute
    {
        return Attribute::make(set: fn (string|DateTimeInterface|null $value): ?string => $value instanceof DateTimeInterface ? $value->format('Y-m-d') : $value);
    }

    /** @return Attribute<never, string|DateTimeInterface|null> */
    protected function expiresOn(): Attribute
    {
        return Attribute::make(set: fn (string|DateTimeInterface|null $value): ?string => $value instanceof DateTimeInterface ? $value->format('Y-m-d') : $value);
    }

    public function drug(): BelongsTo
    {
        return $this->belongsTo(Drug::class, 'drug_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class, 'batch_id');
    }

    public function balances(): HasMany
    {
        return $this->hasMany(StockBalance::class, 'batch_id');
    }

    protected function casts(): array
    {
        return ['recorded_at' => 'immutable_datetime', 'manufactured_on' => 'immutable_date', 'expires_on' => 'immutable_date'];
    }
}
