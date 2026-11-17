<?php

namespace Database\Factories;

use App\Models\DrugPrice;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<DrugPrice> */
class DrugPriceFactory extends Factory
{
    public function definition(): array
    {
        return ['drug_id' => \App\Models\Drug::factory(), 'kind' => 'selling', 'currency' => 'NGN', 'version' => 1, 'amount_micros' => 1250000,
            'created_by' => User::factory(),
            'recorded_at' => fn (): string => now('UTC')->format('Y-m-d H:i:s.u'),
            'reason' => 'Synthetic foundation reason',
            'effective_at' => fn (array $data): string => $data['recorded_at'],
        ];
    }
}
