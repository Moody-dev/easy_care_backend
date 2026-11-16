<?php

namespace Database\Factories;

use App\Models\InvestigationItem;
use App\Models\InvestigationResult;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<InvestigationResult> */
class InvestigationResultFactory extends Factory
{
    public function definition(): array
    {
        return ['item_id' => function (): int {
            $item = InvestigationItem::factory()->create();
            $time = $item->last_event_at;
            if ($item->department === 'laboratory') {
                $item->forceFill(['status' => 'collected', 'version' => 2, 'specimen_ref' => 'Synthetic specimen',
                    'collected_by' => $item->order->ordered_by, 'collected_at' => $time])->save();
            }
            $item->forceFill(['status' => 'processing', 'version' => $item->version + 1,
                'processing_by' => $item->order->ordered_by, 'processing_at' => $time])->save();

            return $item->id;
        }, 'test_id' => fn (array $data): int => InvestigationItem::findOrFail($data['item_id'])->test_id,
            'schema_id' => fn (array $data): int => InvestigationItem::findOrFail($data['item_id'])->schema_id,
            'encounter_id' => fn (array $data): int => InvestigationItem::findOrFail($data['item_id'])->encounter_id,
            'sequence' => 1, 'version' => 1, 'status' => 'draft', 'author_id' => User::factory(),
            'fields' => (object) [], 'comment' => 'Synthetic result', 'reason' => 'Synthetic result entry',
            'last_changed_at' => fn (array $data): string => InvestigationItem::findOrFail($data['item_id'])->last_event_at->format('Y-m-d H:i:s.u'),
            'created_at' => fn (array $data): string => $data['last_changed_at'], 'updated_at' => fn (array $data): string => $data['last_changed_at']];
    }
}
