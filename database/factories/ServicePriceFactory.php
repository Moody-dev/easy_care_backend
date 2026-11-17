<?php

namespace Database\Factories;

use App\Models\ServicePrice;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ServicePrice> */
class ServicePriceFactory extends Factory
{
    public function definition(): array
    {
        return ['service_id' => \App\Models\Service::factory(), 'version' => 1, 'amount_micros' => 100000000,
            'currency' => 'NGN', 'is_active' => true,
            'approval_reference' => 'Synthetic approved tariff reference',
            'actor_id' => \App\Models\User::factory(), 'recorded_at' => fn (): string => now('UTC')->format('Y-m-d H:i:s.u'),
            'effective_at' => fn (array $data): string => $data['recorded_at'], 'reason' => 'Synthetic billing foundation reason'];
    }
}
