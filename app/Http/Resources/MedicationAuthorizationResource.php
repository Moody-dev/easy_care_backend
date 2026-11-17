<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MedicationAuthorizationResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $authorization = $this->resource;
        $last = $authorization->events()->orderByDesc('version')->lockForUpdate()->firstOrFail();
        $issued = (int) \Illuminate\Support\Facades\DB::table('medication_dispenses')->where('authorization_id', $authorization->id)
            ->lockForUpdate()->get()->sum('quantity_micros');

        return ['id' => $authorization->id, 'version' => $last->version, 'prescription_item_id' => $authorization->prescription_item_id,
            'drug_id' => $authorization->drug_id, 'maximum_quantity' => \App\Support\FixedPoint::format($authorization->maximum_quantity_micros),
            'issued_quantity' => \App\Support\FixedPoint::format($issued),
            'remaining_issue_quantity' => \App\Support\FixedPoint::format($authorization->maximum_quantity_micros - $issued),
            'snapshot' => $authorization->snapshot, 'actor_id' => $authorization->actor_id, 'recorded_at' => $authorization->recorded_at->toISOString(),
            'last_action' => $last->action, 'last_record_id' => $last->record_id];
    }
}
