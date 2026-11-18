<?php

namespace Tests\Feature;

use App\Models\StaffNotification;
use App\Services\ReminderDelivery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\ReminderTestCase;

class ReminderApiTest extends ReminderTestCase
{
    use RefreshDatabase;

    public function test_database_failures_do_not_log_private_reminder_text_or_leave_partial_records(): void
    {
        \Illuminate\Support\Facades\Log::spy();
        $this->mock(\App\Services\AuditTrail::class)->shouldReceive('record')->andThrow(new \Illuminate\Database\QueryException(
            'mysql','Synthetic private reminder text sentinel',[],new \Exception('Synthetic private body sentinel')));
        $response=$this->createReminder()->assertStatus(500);
        $this->assertStringNotContainsString('sentinel',$response->getContent());
        \Illuminate\Support\Facades\Log::shouldHaveReceived('error')->with('A staff reminder database operation failed.');
        $this->assertDatabaseCount('staff_reminders',0);
        $this->assertDatabaseCount('reminder_revisions',0);
    }

    public function test_notification_confirmation_replays_only_while_version_and_current_access_still_match(): void
    {
        $this->createReminder()->assertCreated();
        app(ReminderDelivery::class)->run();
        $id=StaffNotification::sole()->id;
        $key=['Idempotency-Key'=>'synthetic-read-confirmation-001'];
        $this->postCommand('/api/v1/notifications/'.$id.'/read',['version'=>1],$key)->assertOk()->assertJsonPath('data.version',2);
        $this->postCommand('/api/v1/notifications/'.$id.'/read',['version'=>1],$key)->assertOk()->assertHeader('Idempotency-Replayed','true');
        $this->postCommand('/api/v1/notifications/'.$id.'/unread',['version'=>2])->assertOk()->assertJsonPath('data.version',3);
        $this->postCommand('/api/v1/notifications/'.$id.'/read',['version'=>1],$key)->assertConflict();
        $this->assertSame(3,DB::table('reminder_events')->where('notification_id',$id)->count());
    }

    public function test_retries_versions_and_owned_history_prevent_duplicate_or_cross_staff_changes(): void
    {
        $key=['Idempotency-Key'=>'synthetic-owned-reminder-001'];
        $root=$this->createReminder(headers:$key)->assertCreated()->json('data');
        $this->createReminder(headers:$key)->assertOk()->assertHeader('Idempotency-Replayed','true');
        $this->patchCommand('/api/v1/reminders/'.$root['id'],$this->fields(['version'=>1,'title'=>'Synthetic replacement']))
            ->assertOk()->assertJsonPath('data.version',2);
        $this->createReminder(headers:$key)->assertConflict();
        $this->patchCommand('/api/v1/reminders/'.$root['id'],$this->fields(['version'=>1]))->assertConflict();
        $this->getJson('/api/v1/reminders/'.$root['id'].'/revisions')->assertOk()->assertJsonPath('total',2);
        $this->flushSession(); $this->actingAs($this->recipient);
        $this->getJson('/api/v1/reminders/'.$root['id'])->assertNotFound();
        $this->postCommand('/api/v1/reminders/'.$root['id'].'/cancel',['version'=>2])->assertNotFound();
        $this->assertDatabaseCount('staff_reminders',1);
        $this->assertDatabaseCount('reminder_revisions',2);
    }

    public function test_cancelled_or_replaced_reminders_hide_old_inbox_items_and_keep_history(): void
    {
        $root=$this->createReminder()->assertCreated()->json('data');
        app(ReminderDelivery::class)->run();
        $id=StaffNotification::sole()->id;
        $this->postCommand('/api/v1/reminders/'.$root['id'].'/cancel',['version'=>1])->assertOk()->assertJsonPath('data.is_active',false);
        $this->assertSame(0,app(ReminderDelivery::class)->run());
        $this->getJson('/api/v1/notifications')->assertOk()->assertJsonCount(0,'data');
        $this->getJson('/api/v1/notifications?include_inactive=1')->assertOk()->assertJsonPath('data.0.is_current',false);
        $this->postCommand('/api/v1/notifications/'.$id.'/snooze',['version'=>1,'snooze_until'=>now('UTC')->addHour()->toISOString()])->assertConflict();
        $this->postCommand('/api/v1/notifications/'.$id.'/read',['version'=>1])->assertOk();
        $this->assertDatabaseCount('reminder_revisions',2);
        $this->assertDatabaseCount('staff_notifications',1);
    }

    public function test_patient_and_visit_access_is_rechecked_for_notification_details_counts_history_and_writes(): void
    {
        $visit=\App\Models\Encounter::factory()->create();
        $root=$this->createReminder(['patient_id'=>$visit->patient_id,'encounter_id'=>$visit->id])->assertCreated()->json('data');
        app(ReminderDelivery::class)->run();
        $id=StaffNotification::sole()->id;
        $visit->patient->forceFill(['access_status'=>'restricted','version'=>2])->save();
        $this->getJson('/api/v1/reminders/'.$root['id'])->assertNotFound();
        $this->getJson('/api/v1/notifications/'.$id)->assertNotFound();
        $this->getJson('/api/v1/notifications/'.$id.'/history')->assertNotFound();
        $this->getJson('/api/v1/notifications/summary')->assertOk()->assertJsonPath('data.unread_count',0);
        $this->postCommand('/api/v1/notifications/'.$id.'/read',['version'=>1])->assertNotFound();
        $this->getJson('/api/v1/notifications')->assertOk()->assertJsonCount(0,'data');
    }

    public function test_assigning_other_staff_needs_both_assignment_and_current_directory_visibility(): void
    {
        $role=\App\Models\Role::where('code','frontdesk')->sole();
        $permission=\App\Models\Permission::where('code','reminders.assign')->sole();
        $role->permissions()->detach($permission->id);
        $this->createReminder(['recipient_ids'=>[$this->recipient->id]])->assertForbidden();
        $role->permissions()->attach($permission->id);
        $role->permissions()->detach(\App\Models\Permission::where('code','staff.view_any')->value('id'));
        $this->createReminder(['recipient_ids'=>[$this->recipient->id]])->assertNotFound();
        $this->createReminder()->assertCreated();
    }

    public function test_notifications_are_recipient_owned_and_read_only_staff_cannot_acknowledge_them(): void
    {
        $this->createReminder(['recipient_ids'=>[$this->recipient->id]])->assertCreated();
        app(ReminderDelivery::class)->run();
        $id=StaffNotification::sole()->id;
        $this->getJson('/api/v1/notifications/'.$id)->assertNotFound();
        $this->flushSession(); $this->actingAs($this->recipient);
        $this->getJson('/api/v1/notifications/'.$id)->assertOk();
        \App\Models\Role::where('code','frontdesk')->sole()->permissions()->detach(\App\Models\Permission::where('code','notifications.update')->value('id'));
        $this->postCommand('/api/v1/notifications/'.$id.'/read',['version'=>1])->assertForbidden();
    }

    public function test_unknown_fields_unbounded_recurrence_and_ineligible_recipient_are_rejected_atomically(): void
    {
        foreach ([['occurrence_count'=>367],['recipient_ids'=>[$this->author->id,$this->author->id]],['recipient_ids'=>[$this->author->id,(string)$this->author->id]],['frequency'=>'hourly'],
            ['timezone'=>'UTC'],['is_active'=>false],['frequency'=>'once','occurrence_count'=>2],['starts_at'=>'2026-11-17 08:00:00']] as $data) {
            $this->createReminder($data)->assertUnprocessable();
        }
        $other=$this->createStaffWithRole('doctor');
        $this->createReminder(['recipient_ids'=>[$other->id]])->assertUnprocessable();
        $this->assertDatabaseCount('staff_reminders',0);
        $this->assertDatabaseCount('reminder_revisions',0);
    }

    public function test_persistent_encrypted_reminder_delivers_once_and_supports_read_status_and_snooze(): void
    {
        $root=$this->createReminder()->assertCreated()->assertJsonPath('data.delivery_pending',true)->json('data');
        $this->assertStringNotContainsString('Synthetic',DB::table('reminder_revisions')->value('content'));
        $this->assertDatabaseCount('staff_notifications',0);
        $this->assertSame(1,app(ReminderDelivery::class)->run());
        $this->assertSame(0,app(ReminderDelivery::class)->run());
        $this->assertDatabaseCount('staff_notifications',1);
        $this->getJson('/api/v1/notifications/summary')->assertOk()->assertJsonPath('data.unread_count',1);
        $id=StaffNotification::sole()->id;
        $this->postCommand('/api/v1/notifications/'.$id.'/read',['version'=>1])->assertOk()->assertJsonPath('data.version',2)->assertJsonPath('data.is_read',true);
        $until=now('UTC')->addHour()->toISOString();
        $this->postCommand('/api/v1/notifications/'.$id.'/snooze',['version'=>2,'snooze_until'=>$until])->assertOk()->assertJsonPath('data.is_snoozed',true);
        $this->getJson('/api/v1/notifications/summary')->assertOk()->assertJsonPath('data.unread_count',0)->assertJsonPath('data.snoozed_count',1);
        $this->getJson('/api/v1/notifications')->assertOk()->assertJsonCount(0,'data');
        $this->travel(1)->hours();
        $this->getJson('/api/v1/notifications/summary')->assertOk()->assertJsonPath('data.unread_count',1);
        $this->getJson('/api/v1/notifications')->assertOk()->assertJsonPath('data.0.title',$root['title']);
    }

    public function test_missed_recurring_occurrences_are_retained_oldest_first_in_bounded_batches_for_each_recipient(): void
    {
        $this->createReminder(['starts_at'=>now('UTC')->subDays(2)->toISOString(),'frequency'=>'daily','occurrence_count'=>3,
            'recipient_ids'=>[$this->author->id,$this->recipient->id]])->assertCreated();
        $this->assertSame(2,app(ReminderDelivery::class)->run(2));
        $this->assertDatabaseCount('reminder_occurrences',2);
        $this->assertDatabaseCount('staff_notifications',4);
        $this->assertSame(1,app(ReminderDelivery::class)->run(2));
        $this->assertSame(0,app(ReminderDelivery::class)->run());
        $this->assertDatabaseCount('staff_notifications',6);
        $this->getJson('/api/v1/notifications')->assertOk()->assertJsonCount(3,'data')->assertJsonPath('data.0.sequence',1)->assertJsonPath('data.2.sequence',3);
        $this->flushSession();
        $this->actingAs($this->recipient);
        $this->getJson('/api/v1/notifications')->assertOk()->assertJsonCount(3,'data');
        $this->getJson('/api/v1/reminders')->assertOk()->assertJsonCount(0,'data');
    }
}
