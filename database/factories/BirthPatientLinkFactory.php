<?php

namespace Database\Factories;

use App\Models\Birth;
use App\Models\Patient;
use Illuminate\Database\Eloquent\Factories\Factory;

class BirthPatientLinkFactory extends Factory
{
    public function definition(): array
    {
        return ['birth_id' => Birth::factory(), 'child_patient_id' => Patient::factory(), 'reason' => 'Synthetic identity verification',
            'encounter_id' => fn (array $a): int => Birth::findOrFail($a['birth_id'])->encounter_id,
            'verified_by' => fn (array $a): int => Birth::findOrFail($a['birth_id'])->recorded_by,
            'verified_at' => fn (array $a) => Birth::findOrFail($a['birth_id'])->recorded_at];
    }
}
