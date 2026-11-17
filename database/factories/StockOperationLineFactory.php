<?php

namespace Database\Factories;

use App\Models\StockOperationLine;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<StockOperationLine> */
class StockOperationLineFactory extends Factory
{
    public function definition(): array
    {
        return ['operation_id' => \App\Models\StockOperation::factory(), 'batch_id' => \App\Models\DrugBatch::factory(),
            'drug_id' => fn (array $data): int => \App\Models\DrugBatch::findOrFail($data['batch_id'])->drug_id,
            'drug_version' => 1, 'quantity_micros' => 1000000, 'unit_cost_micros' => 1250000, 'pack_id' => null, 'pack_count_micros' => null,
            'source_balance_version' => null, 'catalog_snapshot' => ['synthetic' => true],
        ];
    }
}
