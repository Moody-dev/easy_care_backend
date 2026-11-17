<?php

namespace Database\Factories;

use App\Models\BillingReceipt;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<BillingReceipt> */
class BillingReceiptFactory extends Factory
{
    public function definition(): array
    {
        return ['account_id' => \App\Models\BillingAccount::factory(), 'number' => 'REC-'.fake()->uuid(), 'kind' => 'deposit',
            'amount_kobo' => 10000, 'currency' => 'NGN', 'method' => 'cash', 'reference' => null, 'reference_key' => null,
            'payer_name' => 'Synthetic payer', 'externally_confirmed' => false,
            'actor_id' => \App\Models\User::factory(), 'recorded_at' => fn (): string => now('UTC')->format('Y-m-d H:i:s.u'),
            'paid_at' => fn (array $data): string => $data['recorded_at'], 'transaction_details' => [],
            'reason' => 'Synthetic billing foundation reason'];
    }
}
