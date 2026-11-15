<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BedResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return $this->resource->only(['id', 'ward_id', 'label', 'daily_rate', 'is_active', 'version']) + [
            'available' => $this->when($this->resource->getAttribute('ward_active') !== null,
                fn (): bool => $this->is_active && (bool) $this->ward_active && ! $this->occupied),
        ];
    }
}
