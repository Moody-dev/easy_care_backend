<?php

namespace Database\Factories;

use App\Models\BillingInvoice;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<BillingInvoice> */
class BillingInvoiceFactory extends Factory
{
    public function definition(): array
    {
        return ['account_id' => \App\Models\BillingAccount::factory(),
            'encounter_id' => fn (array $data): int => \App\Models\Encounter::factory()->create(['patient_id' => \App\Models\BillingAccount::findOrFail($data['account_id'])->patient_id])->id,
            'number' => 'INV-'.fake()->uuid(), 'item_count' => 1, 'total_kobo' => 10000, 'currency' => 'NGN',
            'actor_id' => \App\Models\User::factory(), 'recorded_at' => fn (): string => now('UTC')->format('Y-m-d H:i:s.u'),
            'reason' => 'Synthetic billing foundation reason'];
    }
}
