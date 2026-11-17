<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([])]
class BillingReceipt extends Model
{
    /** @use HasFactory<\Database\Factories\BillingReceiptFactory> */
    use HasFactory;

    public $timestamps = false;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    public function account(): BelongsTo
    {
        return $this->belongsTo(BillingAccount::class, 'account_id');
    }

    protected function casts(): array
    {
        return ['amount_kobo' => 'integer', 'externally_confirmed' => 'boolean', 'transaction_details' => 'array',
            'paid_at' => 'immutable_datetime', 'recorded_at' => 'immutable_datetime'];
    }
}
