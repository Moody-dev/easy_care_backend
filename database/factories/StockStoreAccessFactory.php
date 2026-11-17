<?php

namespace Database\Factories;

use App\Models\StockStoreAccess;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<StockStoreAccess> */
class StockStoreAccessFactory extends Factory
{
    public function definition(): array
    {
        return ['user_id' => \App\Models\User::factory(), 'store_id' => \App\Models\StockStore::factory(),
            'is_active' => true, 'version' => 1, 'changed_by' => \App\Models\User::factory(),
            'changed_at' => fn (): string => now('UTC')->format('Y-m-d H:i:s.u'), 'reason' => 'Synthetic explicit store access',
        ];
    }
}
