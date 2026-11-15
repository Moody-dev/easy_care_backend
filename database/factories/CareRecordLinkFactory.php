<?php

namespace Database\Factories;

use App\Models\CareProgram;
use App\Models\ClinicalRecord;
use Illuminate\Database\Eloquent\Factories\Factory;

class CareRecordLinkFactory extends Factory
{
    public function definition(): array
    {
        return ['care_program_id' => CareProgram::factory(),
            'clinical_record_id' => fn (array $a): int => ClinicalRecord::factory()->create([
                'kind' => 'entry', 'encounter_id' => CareProgram::findOrFail($a['care_program_id'])->encounter_id])->id];
    }
}
