<?php

namespace App\Http\Resources;

use App\Services\AdmissionFlow;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BedAllocationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return AdmissionFlow::allocation($this->resource);
    }
}
