<?php

namespace Database\Factories;

use App\Models\Service;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Service> */
class ServiceFactory extends Factory
{
    public function definition(): array
    {
        return ['code' => 'synthetic-service-'.fake()->unique()->uuid(), 'name' => 'Synthetic investigation service',
            'category' => 'laboratory', 'is_active' => true, 'created_by' => User::factory()];
    }
}
