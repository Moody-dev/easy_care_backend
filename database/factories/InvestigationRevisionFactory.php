<?php

namespace Database\Factories;

use App\Models\InvestigationItem;
use App\Models\InvestigationOrder;
use App\Models\InvestigationRevision;
use App\Services\InvestigationRequests;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<InvestigationRevision> */
class InvestigationRevisionFactory extends Factory
{
    public function definition(): array
    {
        return ['order_id' => fn (): int => InvestigationItem::factory()->create()->order_id, 'item_id' => null, 'version' => 1, 'operation' => 'requested',
            'actor_id' => fn (array $data): int => InvestigationOrder::findOrFail($data['order_id'])->ordered_by,
            'recorded_at' => fn (array $data): string => InvestigationOrder::findOrFail($data['order_id'])->requested_at->format('Y-m-d H:i:s.u'),
            'reason' => 'Synthetic investigation request',
            'snapshot' => fn (array $data): array => InvestigationRequests::snapshot(InvestigationOrder::findOrFail($data['order_id']))];
    }
}
