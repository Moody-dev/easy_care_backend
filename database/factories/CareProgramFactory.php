<?php

namespace Database\Factories;

use App\Models\Encounter;
use Illuminate\Database\Eloquent\Factories\Factory;

class CareProgramFactory extends Factory
{
    public function definition(): array
    {
        return ['encounter_id' => Encounter::factory(), 'type' => 'antenatal', 'start_reason' => 'Synthetic program opening',
            'patient_id' => fn (array $a): int => Encounter::findOrFail($a['encounter_id'])->patient_id,
            'started_by' => fn (array $a): int => Encounter::findOrFail($a['encounter_id'])->opened_by,
            'started_at' => fn (array $a) => Encounter::findOrFail($a['encounter_id'])->opened_at];
    }
}
