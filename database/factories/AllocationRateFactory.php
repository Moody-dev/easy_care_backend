<?php

namespace Database\Factories;

use App\Models\AllocationRate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AllocationRate>
 */
class AllocationRateFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'allocation_id' => \App\Models\BedAllocation::factory(), 'daily_rate_kobo' => 1000000,
            'source' => 'bed', 'source_version' => 1,
            'actor_id' => fn (array $data): int => \App\Models\BedAllocation::findOrFail($data['allocation_id'])->assigned_by,
            'recorded_at' => fn (array $data): string => \App\Models\BedAllocation::findOrFail($data['allocation_id'])->assigned_at->format('Y-m-d H:i:s.u'),
        ];
    }
}
