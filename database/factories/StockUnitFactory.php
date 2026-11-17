<?php

namespace Database\Factories;

use App\Models\StockUnit;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<StockUnit> */
class StockUnitFactory extends Factory
{
    public function definition(): array
    {
        return ['code' => 'synthetic-unit-'.fake()->unique()->uuid(), 'name' => 'Synthetic base unit',
            'created_by' => User::factory(),
            'recorded_at' => fn (): string => now('UTC')->format('Y-m-d H:i:s.u'),
            'reason' => 'Synthetic foundation reason',
        ];
    }
}
