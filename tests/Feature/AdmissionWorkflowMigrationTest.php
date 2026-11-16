<?php

namespace Tests\Feature;

use App\Models\Admission;
use App\Models\AdmissionRevision;
use App\Models\BedAllocation;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AdmissionWorkflowMigrationTest extends TestCase
{
    use DatabaseMigrations;

    public function test_workflow_history_upgrade_rollback_and_reapply_preserve_populated_admissions_and_grants(): void
    {
        $this->seed(DatabaseSeeder::class);
        $admission = Admission::factory()->create();
        BedAllocation::factory()->create(['admission_id' => $admission->id]);
        $migration = require database_path('migrations/2026_11_15_015445_create_admission_revision_records.php');
        $tables = ['users', 'patients', 'encounters', 'doctor_assignments', 'wards', 'beds', 'admissions',
            'bed_allocations', 'permissions', 'role_permissions', 'permission_baselines', 'audit_events', 'idempotency_requests'];
        $this->assertTablesUnchanged($tables, function () use ($migration, $admission): void {
            $migration->down();
            $this->assertFalse(Schema::hasTable('admission_revisions'));
            $migration->up();
            $this->assertDatabaseCount('admission_revisions', 0);
            $revision = AdmissionRevision::factory()->create(['admission_id' => $admission->id]);
            $this->assertSame($admission->id, $revision->admission->id);
            $this->assertSame($admission->admitted_by, $revision->actor->id);
            $this->assertSame(1, $revision->snapshot['workflow_version']);
            $migration->down();
            $migration->up();
        });
        $this->assertDatabaseCount('admission_revisions', 0);
        $this->assertDatabaseCount('current_bed_occupancies', 1);
        $this->assertDatabaseCount('permissions', 59);
        $this->assertDatabaseCount('role_permissions', 21);
        $this->assertDatabaseCount('permission_baselines', 4);
    }

    public function test_revision_history_is_append_only_and_rejects_mismatched_snapshots_and_sequences(): void
    {
        $this->seed(DatabaseSeeder::class);
        $revision = AdmissionRevision::factory()->create();
        $this->assertRejected(fn () => DB::table('admission_revisions')->where('id', $revision->id)->update(['reason' => 'Rewritten reason']));
        $this->assertRejected(fn () => DB::table('admission_revisions')->where('id', $revision->id)->delete());
        $this->assertRejected(fn () => AdmissionRevision::factory()->create(['admission_id' => $revision->admission_id, 'workflow_version' => 3]));
        $this->assertRejected(fn () => AdmissionRevision::factory()->create(['admission_id' => $revision->admission_id,
            'workflow_version' => 2, 'snapshot' => ['id' => $revision->admission_id + 1, 'workflow_version' => 2]]));
        $this->assertDatabaseCount('admission_revisions', 1);
    }

    private function assertRejected(\Closure $operation): void
    {
        try {
            DB::transaction($operation);
            $this->fail('Invalid history write unexpectedly succeeded.');
        } catch (QueryException) {
            $this->assertTrue(true);
        }
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        RefreshDatabaseState::$migrated = false;
    }
}
