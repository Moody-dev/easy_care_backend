<?php

namespace App\Http\Resources;

use App\Services\StockOperations;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StockOperationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return StockOperations::snapshot($this->resource);
    }
}
