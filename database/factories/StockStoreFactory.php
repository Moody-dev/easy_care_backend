<?php

namespace Database\Factories;

use App\Models\StockStore;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<StockStore> */
class StockStoreFactory extends Factory
{
    public function definition(): array
    {
        return ['code' => 'synthetic-store-'.fake()->unique()->uuid(), 'name' => 'Synthetic store', 'kind' => 'main', 'department_id' => null,
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
