<?php

namespace Database\Factories;

use App\Models\Encounter;
use App\Models\Admission;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Admission>
 */
class AdmissionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'encounter_id' => Encounter::factory(),
            'patient_id' => fn (array $attributes): int => Encounter::query()->findOrFail($attributes['encounter_id'])->patient_id,
            'admitted_by' => fn (array $attributes): int => Encounter::query()->findOrFail($attributes['encounter_id'])->opened_by,
            'admitted_at' => fn (array $attributes): string => Encounter::query()->findOrFail($attributes['encounter_id'])->opened_at->format('Y-m-d H:i:s.u'),
        ];
    }

    public function forEncounter(Encounter $encounter): static
    {
        return $this->state(fn (): array => ['encounter_id' => $encounter->id, 'patient_id' => $encounter->patient_id,
            'admitted_by' => $encounter->opened_by, 'admitted_at' => $encounter->opened_at->format('Y-m-d H:i:s.u')]);
    }
}
