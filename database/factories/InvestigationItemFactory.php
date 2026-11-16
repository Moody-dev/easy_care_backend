<?php

namespace Database\Factories;

use App\Models\InvestigationItem;
use App\Models\InvestigationOrder;
use App\Models\InvestigationSchema;
use App\Models\InvestigationTest;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<InvestigationItem> */
class InvestigationItemFactory extends Factory
{
    public function definition(): array
    {
        return ['order_id' => InvestigationOrder::factory(),
            'encounter_id' => fn (array $data): int => InvestigationOrder::findOrFail($data['order_id'])->encounter_id,
            'schema_id' => fn (array $data): int => InvestigationSchema::factory()->create([
                'published_at' => InvestigationOrder::findOrFail($data['order_id'])->requested_at->subSecond(),
                'schema_json' => fn (array $schema): array => ['code' => InvestigationTest::findOrFail($schema['test_id'])->code,
                    'name' => 'Synthetic approved test', 'specimen' => 'Synthetic specimen', 'service' => ['code' => 'synthetic', 'name' => 'Synthetic service'], 'title' => 'Synthetic form'],
            ])->id,
            'test_id' => fn (array $data): int => InvestigationSchema::findOrFail($data['schema_id'])->test_id,
            'department' => fn (array $data): string => InvestigationSchema::findOrFail($data['schema_id'])->test->department,
            'priority' => 'routine', 'status' => 'requested', 'version' => 1,
            'last_event_at' => fn (array $data): string => InvestigationOrder::findOrFail($data['order_id'])->requested_at->format('Y-m-d H:i:s.u')];
    }
}
