<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WardResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return $this->resource->only(['id', 'code', 'name', 'type', 'daily_rate', 'is_active', 'version']);
    }
}
