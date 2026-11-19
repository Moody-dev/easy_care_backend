<?php

namespace App\Http\Resources;

use App\Models\Drug;
use App\Services\StockCatalog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StockCatalogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $result = StockCatalog::snapshot($request->route('catalog') ?? 'store-access', $this->resource);
        if ($this->resource instanceof Drug && array_key_exists('current_selling_price', $this->resource->getAttributes())) {
            $result['current_selling_price'] = $this->resource->getAttribute('current_selling_price');
        }

        return $result;
    }
}
