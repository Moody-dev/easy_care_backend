<?php

namespace Database\Factories;

use App\Models\Drug;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Drug> */
class DrugFactory extends Factory
{
    public function definition(): array
    {
        return ['code' => 'synthetic-drug-'.fake()->unique()->uuid(), 'name' => 'Synthetic medication',
            'generic_name' => 'Synthetic generic', 'brand_name' => null, 'strength' => 'Synthetic strength',
            'dosage_form' => 'Synthetic form', 'category' => null, 'base_unit_id' => \App\Models\StockUnit::factory(), 'quantity_scale' => 6,
            'created_by' => User::factory(),
            'recorded_at' => fn (): string => now('UTC')->format('Y-m-d H:i:s.u'),
            'reason' => 'Synthetic foundation reason',
            'is_active' => true, 'version' => 1,
            'updated_by' => fn (array $data): int => $data['created_by'],
            'updated_at' => fn (array $data): string => $data['recorded_at'],
            'change_reason' => 'Synthetic creation reason',
        ];
    }
}
