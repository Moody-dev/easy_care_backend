<?php

namespace Database\Factories;

use App\Models\DrugBatch;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<DrugBatch> */
class DrugBatchFactory extends Factory
{
    public function definition(): array
    {
        return ['drug_id' => \App\Models\Drug::factory(), 'batch_number' => 'synthetic-'.fake()->unique()->uuid(),
            'manufacturer' => 'Synthetic manufacturer', 'manufactured_on' => '2026-01-01', 'expires_on' => '2030-12-31',
            'created_by' => User::factory(),
            'recorded_at' => fn (): string => now('UTC')->format('Y-m-d H:i:s.u'),
            'reason' => 'Synthetic foundation reason',
        ];
    }
}
