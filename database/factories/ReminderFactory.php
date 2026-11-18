<?php

namespace Database\Factories;

use App\Models\Reminder;
use App\Models\ReminderRevision;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;

/** @extends Factory<Reminder> */
class ReminderFactory extends Factory
{
    public function definition(): array
    {
        return ['owner_id'=>User::factory(),'patient_id'=>null,'encounter_id'=>null,'version'=>1,'is_active'=>true,'next_sequence'=>1,
            'next_due_at'=>fn (): string=>now('UTC')->subMinute()->format('Y-m-d H:i:s.u'),
            'created_at'=>fn (): string=>now('UTC')->format('Y-m-d H:i:s.u'),'updated_at'=>fn (): string=>now('UTC')->format('Y-m-d H:i:s.u')];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (Reminder $root): void {
            $revision=new ReminderRevision;
            $revision->forceFill(['reminder_id'=>$root->id,'version'=>1,'content'=>['title'=>'Synthetic reminder','body'=>'Synthetic task details'],
                'starts_at'=>$root->next_due_at,'timezone'=>'Africa/Lagos','frequency'=>'once','interval'=>1,'occurrence_count'=>1,'ends_at'=>null,
                'actor_id'=>$root->owner_id,'recorded_at'=>$root->created_at])->save();
            DB::table('reminder_recipients')->insert(['revision_id'=>$revision->id,'user_id'=>$root->owner_id]);
        });
    }
}
