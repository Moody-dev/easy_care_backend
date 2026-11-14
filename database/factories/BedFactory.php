<?php

namespace Database\Factories;

use App\Models\Ward;
use App\Models\Bed;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Bed>
 */
class BedFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'ward_id' => Ward::factory(), 'label' => 'Bed-'.fake()->unique()->numerify('########'), 'daily_rate' => null,
        ];
    }
}
