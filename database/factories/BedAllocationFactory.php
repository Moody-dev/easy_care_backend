<?php

namespace Database\Factories;

use App\Models\Admission;
use App\Models\Bed;
use App\Models\BedAllocation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BedAllocation>
 */
class BedAllocationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'bed_id' => Bed::factory(), 'admission_id' => Admission::factory(),
            'assigned_by' => fn (array $attributes): int => Admission::query()->findOrFail($attributes['admission_id'])->admitted_by,
            'assigned_at' => fn (array $attributes): string => Admission::query()->findOrFail($attributes['admission_id'])->admitted_at->format('Y-m-d H:i:s.u'),
            'assignment_reason' => 'Synthetic initial bed allocation',
        ];
    }
}
