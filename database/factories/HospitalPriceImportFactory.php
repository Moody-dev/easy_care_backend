<?php

namespace Database\Factories;

use App\Models\HospitalPriceImport;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<HospitalPriceImport>
 */
class HospitalPriceImportFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'content_hash' => hash('sha256', fake()->unique()->uuid()),
            'sources' => ['fixture.csv' => ['sha256' => hash('sha256', 'fixture'), 'encoding' => 'UTF-8', 'base64' => base64_encode('fixture')]],
            'effective_at' => now('UTC'),
            'created_at' => now('UTC'),
        ];
    }
}
