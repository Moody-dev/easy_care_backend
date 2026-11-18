<?php

namespace Database\Factories;

use App\Models\ReminderDelivery;
use App\Models\StaffNotification;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<StaffNotification> */
class StaffNotificationFactory extends Factory
{
    public function definition(): array
    {
        return ['delivery_id'=>ReminderDelivery::factory(),
            'recipient_id'=>fn (array $data): int=>ReminderDelivery::findOrFail($data['delivery_id'])->recipient_id,'version'=>1,'read_at'=>null,
            'available_at'=>fn (): string=>now('UTC')->format('Y-m-d H:i:s.u'),'created_at'=>fn (): string=>now('UTC')->format('Y-m-d H:i:s.u'),
            'updated_at'=>fn (): string=>now('UTC')->format('Y-m-d H:i:s.u')];
    }
}
