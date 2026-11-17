<?php

namespace Database\Factories;

use App\Models\DrugPack;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<DrugPack> */
class DrugPackFactory extends Factory
{
    public function definition(): array
    {
        return ['drug_id' => \App\Models\Drug::factory(), 'code' => 'synthetic-pack', 'name' => 'Synthetic pack',
            'version' => 1, 'base_quantity_micros' => 10000000, 'is_active' => true,
            'created_by' => User::factory(),
            'recorded_at' => fn (): string => now('UTC')->format('Y-m-d H:i:s.u'),
            'reason' => 'Synthetic foundation reason',
        ];
    }
}
