<?php

namespace Database\Factories;

use App\Models\InvestigationResult;
use App\Models\InvestigationResultRevision;
use App\Services\InvestigationResults;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<InvestigationResultRevision> */
class InvestigationResultRevisionFactory extends Factory
{
    public function definition(): array
    {
        return ['result_id' => InvestigationResult::factory(), 'version' => 1, 'operation' => 'created',
            'actor_id' => fn (array $data): int => InvestigationResult::findOrFail($data['result_id'])->author_id,
            'recorded_at' => fn (array $data): string => InvestigationResult::findOrFail($data['result_id'])->last_changed_at->format('Y-m-d H:i:s.u'),
            'reason' => 'Synthetic result entry',
            'snapshot' => fn (array $data): array => InvestigationResults::snapshot(InvestigationResult::findOrFail($data['result_id']))];
    }
}
