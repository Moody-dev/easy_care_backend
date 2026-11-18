<?php

namespace Database\Factories;

use App\Models\Reminder;
use App\Models\ReminderOccurrence;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ReminderOccurrence> */
class ReminderOccurrenceFactory extends Factory
{
    public function definition(): array
    {
        return ['reminder_id'=>Reminder::factory(),
            'revision_id'=>fn (array $data): int=>Reminder::findOrFail($data['reminder_id'])->revision->id,'sequence'=>1,
            'due_at'=>fn (array $data): string=>Reminder::findOrFail($data['reminder_id'])->next_due_at->format('Y-m-d H:i:s.u'),
            'processed_at'=>fn (): string=>now('UTC')->format('Y-m-d H:i:s.u')];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (ReminderOccurrence $occurrence): void {
            $next=$occurrence->sequence+1;
            $occurrence->reminder->forceFill(['next_sequence'=>$next,'next_due_at'=>app(\App\Services\ReminderSchedule::class)->due($occurrence->revision,$next),
                'updated_at'=>$occurrence->processed_at])->save();
        });
    }
}
