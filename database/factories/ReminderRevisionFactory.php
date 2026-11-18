<?php

namespace Database\Factories;

use App\Models\Reminder;
use App\Models\ReminderRevision;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;

/** @extends Factory<ReminderRevision> */
class ReminderRevisionFactory extends Factory
{
    public function definition(): array
    {
        return ['reminder_id'=>Reminder::factory(),'version'=>2,'content'=>['title'=>'Synthetic replacement','body'=>'Synthetic replacement details'],
            'starts_at'=>fn (): string=>now('UTC')->format('Y-m-d H:i:s.u'),'timezone'=>'Africa/Lagos','frequency'=>'once','interval'=>1,
            'occurrence_count'=>1,'ends_at'=>null,'actor_id'=>fn (array $data): int=>Reminder::findOrFail($data['reminder_id'])->owner_id,
            'recorded_at'=>fn (): string=>now('UTC')->format('Y-m-d H:i:s.u')];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (ReminderRevision $revision): void {
            DB::table('reminder_recipients')->insert(['revision_id'=>$revision->id,'user_id'=>$revision->actor_id]);
            $revision->reminder->forceFill(['version'=>$revision->version,'next_sequence'=>1,'next_due_at'=>$revision->starts_at,'updated_at'=>$revision->recorded_at])->save();
        });
    }
}
