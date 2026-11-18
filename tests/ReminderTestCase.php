<?php

namespace Tests;

use App\Models\StaffNotification;
use App\Models\User;
use App\Services\ReminderDelivery;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

abstract class ReminderTestCase extends TestCase
{


    protected User $author;
    protected User $recipient;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeTime();
        $this->seed(DatabaseSeeder::class);
        $this->grantRolePermissions('frontdesk',['reminders.view','reminders.manage','reminders.assign','notifications.view','notifications.update',
            'staff.view_any','patients.view','encounters.view','encounters.view_any']);
        $this->author=$this->createStaffWithRole('frontdesk');
        $this->recipient=$this->createStaffWithRole('frontdesk');
        $this->actingAs($this->author);
    }

    protected function fields(array $changes=[]): array
    {
        return array_replace(['title'=>'Synthetic private staff task','body'=>'Synthetic retained reminder details',
            'starts_at'=>now('UTC')->subMinute()->toISOString(),'timezone'=>'Africa/Lagos','frequency'=>'once','interval'=>1,'occurrence_count'=>1,
            'recipient_ids'=>[$this->author->id]],$changes);
    }

    protected function createReminder(array $changes=[],array $headers=[]): TestResponse
    {
        $this->flushSession();
        $this->actingAs($this->author);
        return $this->postCommand('/api/v1/reminders',$this->fields($changes),$headers);
    }

}
