<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Jobs\DeliverDueReminders;
use App\Models\Permission;
use App\Models\Reminder;
use App\Models\Role;
use App\Services\ReminderDelivery;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\ReminderTestCase;

class ReminderDeliveryTest extends ReminderTestCase
{
    use RefreshDatabase;

    public function test_failed_notification_insert_restores_cursor_occurrence_and_every_recipient_before_safe_retry(): void
    {
        $root=$this->createReminder(['recipient_ids'=>[$this->author->id,$this->recipient->id]])->assertCreated()->json('data');
        $triggered=$this->failNextWriteTo('staff_notifications','insert');
        try { app(ReminderDelivery::class)->run(); $this->fail('The injected delivery failure must propagate.'); }
        catch (\RuntimeException $exception) { $this->assertStringContainsString('Synthetic',$exception->getMessage()); }
        $this->assertTrue($triggered());
        $this->assertDatabaseCount('staff_notifications',0);
        $this->assertDatabaseCount('reminder_occurrences',0);
        $this->assertDatabaseCount('reminder_deliveries',0);
        $this->assertSame(1,Reminder::findOrFail($root['id'])->next_sequence);
        $this->assertSame(1,app(ReminderDelivery::class)->run());
        $this->assertSame(0,app(ReminderDelivery::class)->run());
        $this->assertDatabaseCount('staff_notifications',2);
    }

    public function test_revoked_recipient_access_records_a_suppressed_occurrence_without_exposing_a_message(): void
    {
        $this->grantRolePermissions('doctor',['notifications.view']);
        $other=$this->createStaffWithRole('doctor');
        $this->createReminder(['recipient_ids'=>[$other->id]])->assertCreated();
        Role::where('code','doctor')->sole()->permissions()->detach(Permission::where('code','notifications.view')->value('id'));
        $this->assertSame(1,app(ReminderDelivery::class)->run());
        $this->assertDatabaseCount('staff_notifications',0);
        $this->assertDatabaseHas('reminder_deliveries',['outcome'=>'suppressed','suppression_code'=>'recipient_permission_missing']);
        $this->grantRolePermissions('doctor',['notifications.view']);
        $this->assertSame(0,app(ReminderDelivery::class)->run());
    }

    public function test_inactive_author_is_rechecked_at_delivery(): void
    {
        $this->createReminder()->assertCreated();
        $this->author->forceFill(['is_active'=>false,'version'=>2])->save();
        $this->assertSame(1,app(ReminderDelivery::class)->run());
        $this->assertDatabaseHas('reminder_deliveries',['suppression_code'=>'author_unavailable']);
        $this->assertDatabaseCount('staff_notifications',0);
    }

    public function test_dispatch_uses_local_database_queue_and_payload_contains_no_reminder_text(): void
    {
        Queue::fake();
        $this->createReminder()->assertCreated();
        $this->artisan('reminders:dispatch')->assertSuccessful();
        Queue::assertPushed(DeliverDueReminders::class,function (DeliverDueReminders $job): bool {
            $this->assertSame('reminders',$job->connection);
            $this->assertSame('reminders',$job->queue);
            $this->assertLessThan(config('queue.connections.reminders.retry_after'),$job->timeout);
            $this->assertStringNotContainsString('Synthetic',serialize($job));
            return true;
        });
        $this->assertDatabaseCount('staff_notifications',0);
        app(DeliverDueReminders::class)->handle(app(ReminderDelivery::class));
        $this->assertDatabaseCount('staff_notifications',1);
    }

    public function test_disabling_delivery_preserves_due_work_and_does_not_dispatch(): void
    {
        Queue::fake();
        $this->createReminder()->assertCreated();
        config(['reminders.enabled'=>false]);
        $this->artisan('reminders:dispatch')->assertSuccessful();
        Queue::assertNothingPushed();
        app(DeliverDueReminders::class)->handle(app(ReminderDelivery::class));
        $this->assertDatabaseCount('reminder_occurrences',0);
        config(['reminders.enabled'=>true]);
        app(DeliverDueReminders::class)->handle(app(ReminderDelivery::class));
        $this->assertDatabaseCount('staff_notifications',1);
    }

    public function test_original_revision_and_delivery_records_are_immutable(): void
    {
        $this->createReminder()->assertCreated();
        app(ReminderDelivery::class)->run();
        $this->expectException(QueryException::class);
        DB::table('reminder_occurrences')->update(['sequence'=>2]);
    }

    public function test_factories_preserve_real_relationships_and_encrypted_content(): void
    {
        $notification=\App\Models\StaffNotification::factory()->create();
        $delivery=\App\Models\ReminderDelivery::findOrFail($notification->delivery_id);
        $this->assertSame($notification->recipient_id,$delivery->occurrence->reminder->owner_id);
        $this->assertSame('Synthetic reminder',$delivery->occurrence->revision->content['title']);
        $this->assertNull($delivery->occurrence->reminder->next_due_at);
        $this->assertStringNotContainsString('Synthetic',DB::table('reminder_revisions')->where('id',$delivery->occurrence->revision_id)->value('content'));
    }
}
