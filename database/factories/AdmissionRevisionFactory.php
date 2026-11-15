<?php

namespace Database\Factories;

use App\Models\Admission;
use App\Models\AdmissionRevision;
use App\Services\AdmissionFlow;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AdmissionRevision> */
class AdmissionRevisionFactory extends Factory
{
    public function definition(): array
    {
        return ['admission_id' => Admission::factory(), 'workflow_version' => 1, 'operation' => 'admitted',
            'reason' => 'Synthetic admission record', 'recorded_at' => now('UTC'),
            'actor_id' => fn (array $attributes): int => Admission::findOrFail($attributes['admission_id'])->admitted_by,
            'snapshot' => fn (array $attributes): array => AdmissionFlow::snapshot(Admission::findOrFail($attributes['admission_id']), $attributes['workflow_version'])];
    }
}
