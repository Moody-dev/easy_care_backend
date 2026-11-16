<?php

namespace App\Http\Resources;

use App\Services\InvestigationRequests;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InvestigationItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return InvestigationRequests::itemSnapshot($this->resource) + ['patient_id' => $this->order->patient_id,
            'ordered_by' => $this->order->ordered_by, 'requested_at' => $this->order->requested_at->toISOString(),
            'order_version' => $this->order->version, 'patient' => $this->order->patient->only(['id', 'mrn', 'first_name', 'last_name'])];
    }
}
