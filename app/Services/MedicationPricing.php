<?php

namespace App\Services;

use App\Models\DrugPrice;
use App\Models\StockCatalogRevision;
use App\Support\FixedPoint;
use Illuminate\Support\Collection;

class MedicationPricing
{
    /** @param Collection<int, \App\Models\Drug> $drugs */
    public function attach(Collection $drugs): void
    {
        if ($drugs->isEmpty()) {
            return;
        }
        $time = now('UTC')->format('Y-m-d H:i:s.u');
        $prices = DrugPrice::query()->whereIn('drug_id', $drugs->pluck('id'))->where('kind', 'selling')->where('currency', 'NGN')
            ->where('effective_at', '<=', $time)->whereNotExists(function ($query) use ($time): void {
                $query->selectRaw('1')->from('drug_prices as newer')->whereColumn('newer.drug_id', 'drug_prices.drug_id')
                    ->whereColumn('newer.kind', 'drug_prices.kind')->whereColumn('newer.currency', 'drug_prices.currency')
                    ->where('newer.effective_at', '<=', $time)->where(function ($query): void {
                        $query->whereColumn('newer.effective_at', '>', 'drug_prices.effective_at')->orWhere(function ($query): void {
                            $query->whereColumn('newer.effective_at', 'drug_prices.effective_at')->whereColumn('newer.version', '>', 'drug_prices.version');
                        });
                    });
            })->get()->keyBy('drug_id');
        $approved = StockCatalogRevision::query()->where('catalog', 'prices')->whereIn('record_id', $prices->pluck('id'))
            ->get()->keyBy('record_id');
        foreach ($drugs as $drug) {
            $price = $prices->get($drug->id);
            $valid = $price !== null && $approved->get($price->id)?->version === $price->version;
            $drug->setAttribute('current_selling_price', $valid ? ['id' => $price->id, 'version' => $price->version,
                'amount' => FixedPoint::format($price->amount_micros), 'currency' => 'NGN', 'quantity' => '1.000000',
                'unit_id' => $drug->base_unit_id, 'effective_at' => $price->effective_at->toISOString()] : null);
        }
    }
}
