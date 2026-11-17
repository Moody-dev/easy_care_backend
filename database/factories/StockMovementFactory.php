<?php

namespace Database\Factories;

use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<StockMovement> */
class StockMovementFactory extends Factory
{
    public function definition(): array
    {
        return ['movement_key' => fake()->unique()->uuid(), 'batch_id' => \App\Models\DrugBatch::factory(),
            'drug_id' => fn (array $data): int => \App\Models\DrugBatch::findOrFail($data['batch_id'])->drug_id,
            'store_id' => \App\Models\StockStore::factory(), 'pack_id' => null, 'supplier_id' => null,
            'kind' => 'opening', 'quantity_micros' => 10000000, 'unit_cost_micros' => null, 'currency' => null,
            'reference_type' => 'synthetic', 'reference_id' => 'synthetic-reference', 'reversal_of' => null,
            'created_by' => User::factory(),
            'recorded_at' => fn (): string => now('UTC')->format('Y-m-d H:i:s.u'),
            'reason' => 'Synthetic foundation reason',
        ];
    }
}
