<?php

namespace Database\Factories;

use App\Models\AccommodationAssessment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AccommodationAssessment>
 */
class AccommodationAssessmentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'admission_id' => \App\Models\Admission::factory()->afterCreating(function (\App\Models\Admission $admission): void {
                $admission->forceFill(['status' => 'discharge_authorized', 'version' => 2,
                    'authorized_by' => $admission->admitted_by, 'authorized_at' => now('UTC'),
                    'discharge_reason' => 'Synthetic exit'])->save();
                $admission->forceFill(['status' => 'discharged', 'version' => 3,
                    'discharged_by' => \App\Models\User::factory()->create()->id, 'discharged_at' => now('UTC')])->save();
            }),
            'source_key' => fn (array $data): string => hash('sha256', 'accommodation|'.$data['admission_id']),
            'status' => 'ready', 'total_kobo' => 0, 'snapshot' => ['completed_days' => 0, 'total' => '0.00'],
            'actor_id' => fn (array $data): int => \App\Models\Admission::findOrFail($data['admission_id'])->discharged_by,
            'recorded_at' => fn (array $data): string => \App\Models\Admission::findOrFail($data['admission_id'])->discharged_at->format('Y-m-d H:i:s.u'),
        ];
    }
}
