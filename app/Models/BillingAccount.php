<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([])]
class BillingAccount extends Model
{
    /** @use HasFactory<\Database\Factories\BillingAccountFactory> */
    use HasFactory;

    public $timestamps = false;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class, 'patient_id');
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(BillingInvoice::class, 'account_id');
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(BillingReceipt::class, 'account_id');
    }

    protected function casts(): array
    {
        return ['currency' => 'string', 'recorded_at' => 'immutable_datetime'];
    }
}
