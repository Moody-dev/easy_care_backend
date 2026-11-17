<?php

namespace Database\Factories;

use App\Models\StockOperationRevision;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<StockOperationRevision> */
class StockOperationRevisionFactory extends Factory
{
    public function definition(): array
    {
        return ['operation_id' => \App\Models\StockOperation::factory(),
            'version' => fn (array $data): int => \App\Models\StockOperation::findOrFail($data['operation_id'])->version,
            'actor_id' => fn (array $data): int => \App\Models\StockOperation::findOrFail($data['operation_id'])->last_actor_id,
            'recorded_at' => fn (array $data): string => \App\Models\StockOperation::findOrFail($data['operation_id'])->last_changed_at->format('Y-m-d H:i:s.u'),
            'action' => 'created', 'reason' => 'Synthetic operation draft', 'snapshot' => ['synthetic' => true],
        ];
    }
}
