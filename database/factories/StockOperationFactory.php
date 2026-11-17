<?php

namespace Database\Factories;

use App\Models\StockOperation;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<StockOperation> */
class StockOperationFactory extends Factory
{
    public function definition(): array
    {
        return ['kind' => 'opening', 'status' => 'draft', 'version' => 1, 'source_store_id' => null, 'source_store_version' => null,
            'destination_store_id' => \App\Models\StockStore::factory(), 'destination_store_version' => 1,
            'supplier_id' => null, 'supplier_version' => null, 'invoice_number' => null, 'currency' => 'NGN',
            'reference' => 'Synthetic opening reference', 'catalog_snapshot' => ['synthetic' => true],
            'prepared_by' => \App\Models\User::factory(), 'prepared_at' => fn (): string => now('UTC')->format('Y-m-d H:i:s.u'),
            'last_actor_id' => fn (array $data): int => $data['prepared_by'], 'last_changed_at' => fn (array $data): string => $data['prepared_at'],
            'reason' => 'Synthetic opening reason',
        ];
    }
}
