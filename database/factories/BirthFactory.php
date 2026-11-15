<?php

namespace Database\Factories;

use App\Models\CareProgram;
use Illuminate\Database\Eloquent\Factories\Factory;

class BirthFactory extends Factory
{
    public function definition(): array
    {
        return ['delivery_id' => CareProgram::factory()->state(['type' => 'delivery']), 'birth_order' => 1, 'reason' => 'Synthetic baby identity',
            'encounter_id' => fn (array $a): int => CareProgram::findOrFail($a['delivery_id'])->encounter_id,
            'recorded_by' => fn (array $a): int => CareProgram::findOrFail($a['delivery_id'])->started_by,
            'recorded_at' => fn (array $a) => CareProgram::findOrFail($a['delivery_id'])->started_at];
    }
}
