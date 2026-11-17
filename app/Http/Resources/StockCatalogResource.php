<?php

namespace App\Http\Resources;

use App\Services\StockCatalog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StockCatalogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return StockCatalog::snapshot($request->route('catalog') ?? 'store-access', $this->resource);
    }
}
