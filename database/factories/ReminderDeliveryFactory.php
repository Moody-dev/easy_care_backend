<?php

namespace Database\Factories;

use App\Models\ReminderDelivery;
use App\Models\ReminderOccurrence;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ReminderDelivery> */
class ReminderDeliveryFactory extends Factory
{
    public function definition(): array
    {
        return ['occurrence_id'=>ReminderOccurrence::factory(),
            'recipient_id'=>fn (array $data): int=>ReminderOccurrence::findOrFail($data['occurrence_id'])->reminder->owner_id,
            'outcome'=>'delivered','suppression_code'=>null,'recorded_at'=>fn (): string=>now('UTC')->format('Y-m-d H:i:s.u')];
    }
}
