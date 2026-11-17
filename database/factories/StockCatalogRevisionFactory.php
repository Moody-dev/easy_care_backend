<?php

namespace Database\Factories;

use App\Models\StockCatalogRevision;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<StockCatalogRevision> */
class StockCatalogRevisionFactory extends Factory
{
    public function definition(): array
    {
        return ['record_id' => \App\Models\Drug::factory(), 'catalog' => 'drugs', 'version' => 1,
            'actor_id' => \App\Models\User::factory(), 'recorded_at' => fn (): string => now('UTC')->format('Y-m-d H:i:s.u'),
            'reason' => 'Synthetic approved catalog', 'snapshot' => ['approval_reference' => 'Synthetic hospital approval'],
        ];
    }
}
