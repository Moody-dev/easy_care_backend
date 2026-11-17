<?php

namespace Database\Factories;

use App\Models\BillingAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<BillingAccount> */
class BillingAccountFactory extends Factory
{
    public function definition(): array
    {
        return ['patient_id' => \App\Models\Patient::factory(), 'currency' => 'NGN',
            'actor_id' => \App\Models\User::factory(), 'recorded_at' => fn (): string => now('UTC')->format('Y-m-d H:i:s.u'),
            'reason' => 'Synthetic billing foundation reason'];
    }
}
