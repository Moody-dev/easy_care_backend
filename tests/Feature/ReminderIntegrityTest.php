<?php

namespace Tests\Feature;

use App\Models\Reminder;
use App\Models\ReminderRevision;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ReminderIntegrityTest extends TestCase
{
    use RefreshDatabase;

    public function test_empty_migration_round_trip_preserves_existing_records_and_grants(): void
    {
        $this->seed(DatabaseSeeder::class);
        $migration=require database_path('migrations/2026_11_18_020441_create_staff_reminder_records.php');
        $this->assertTablesUnchanged(['users','patients','encounters','permissions','role_permissions','billing_invoices','hmo_providers'],function () use ($migration): void {
            $migration->down();
            $this->assertFalse(Schema::hasTable('staff_reminders'));
            $migration->up();
        });
        $this->assertDatabaseCount('staff_reminders',0);
        $this->assertDatabaseCount('permissions',118);
        $this->assertDatabaseCount('role_permissions',21);
        $this->assertSame(0,DB::table('role_permissions as rp')->join('permissions as p','p.id','=','rp.permission_id')
            ->where(fn (\Illuminate\Database\Query\Builder $q): \Illuminate\Database\Query\Builder=>$q->where('p.code','like','reminders.%')->orWhere('p.code','like','notifications.%'))->count());
    }

    public function test_populated_reminders_cannot_be_discarded_by_rollback(): void
    {
        Reminder::factory()->create();
        $migration=require database_path('migrations/2026_11_18_020441_create_staff_reminder_records.php');
        try { $migration->down(); $this->fail('Retained reminders must prevent rollback.'); }
        catch (\RuntimeException $exception) { $this->assertStringContainsString('Preserve',$exception->getMessage()); }
        $this->assertDatabaseCount('staff_reminders',1);
    }

    public function test_root_cursor_cannot_skip_a_due_occurrence_without_a_retained_processing_record(): void
    {
        $root=Reminder::factory()->create();
        $this->expectException(QueryException::class);
        $root->forceFill(['next_sequence'=>2,'next_due_at'=>null])->save();
    }

    public function test_context_cannot_link_another_patients_encounter(): void
    {
        $patient=\App\Models\Patient::factory()->create();
        $visit=\App\Models\Encounter::factory()->create();
        $this->expectException(QueryException::class);
        Reminder::factory()->create(['patient_id'=>$patient->id,'encounter_id'=>$visit->id]);
    }

    public function test_replacement_factory_retains_original_revision_and_current_projection(): void
    {
        $revision=ReminderRevision::factory()->create();
        $this->assertSame(2,$revision->reminder->version);
        $this->assertSame(2,$revision->reminder->revisions()->count());
        $this->assertSame('Synthetic replacement',$revision->content['title']);
    }
}
