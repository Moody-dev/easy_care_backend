<?php

namespace Database\Factories;

use App\Models\InvestigationTest;
use App\Models\Service;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<InvestigationTest> */
class InvestigationTestFactory extends Factory
{
    public function definition(): array
    {
        return ['code' => 'synthetic-test-'.fake()->unique()->uuid(), 'name' => 'Synthetic investigation',
            'department' => 'laboratory', 'specimen' => null, 'is_active' => true, 'version' => 1,
            'service_id' => Service::factory(), 'created_by' => User::factory()];
    }
}
