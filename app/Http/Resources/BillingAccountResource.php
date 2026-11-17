<?php

namespace App\Http\Resources;

use App\Services\BillingBalances;
use App\Support\BillingMoney;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\DB;

class BillingAccountResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $last = DB::table('billing_events')->where('account_id', $this->id)->orderByDesc('version')->lockForUpdate()->first();
        $state = app(BillingBalances::class)->snapshot($this->resource);

        return ['id' => $this->id, 'patient_id' => $this->patient_id, 'currency' => 'NGN', 'version' => (int) ($last->version ?? 0),
            'totals' => array_map(fn (int $amount): string => BillingMoney::format($amount), $state['totals']),
            'last_action' => $last?->action, 'last_record_id' => $last === null ? null : (int) $last->record_id, 'payments_processed_by_platform' => false];
    }
}
