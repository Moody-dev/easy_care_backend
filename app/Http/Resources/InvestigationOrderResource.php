<?php

namespace App\Http\Resources;

use App\Services\InvestigationRequests;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InvestigationOrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return InvestigationRequests::snapshot($this->resource)
            + ['patient' => $this->patient->only(['id', 'mrn', 'first_name', 'last_name'])];
    }
}
