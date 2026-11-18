<?php

namespace Tests\Feature;

use App\Models\InvestigationResult;
use App\Models\InvestigationResultAttachment;
use App\Models\InvestigationResultRevision;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class InvestigationResultMigrationTest extends TestCase
{
    use DatabaseMigrations;

    public function test_upgrade_and_rollback_preserve_requests_catalogs_patients_files_and_default_grants(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->fakeProtectedStorage();
        $revision = InvestigationResultRevision::factory()->create();
        $result = $revision->result;
        $attachment = InvestigationResultAttachment::factory()->create(['result_id' => $result->id]);
        $this->assertSame($result->item->schema_id, $result->schema->id);
        $this->assertSame($result->encounter_id, $result->encounter->id);
        $this->assertSame($result->author_id, $result->author->id);
        $this->assertTrue($result->item->results->contains($result));
        $this->assertSame($result->id, $attachment->result->id);
        $migration = require database_path('migrations/2026_11_16_034753_create_investigation_result_records.php');
        $this->assertTablesUnchanged(['investigation_orders', 'investigation_items', 'investigation_revisions', 'investigation_tests', 'investigation_schemas',
            'patients', 'users', 'files', 'permissions', 'role_permissions', 'permission_baselines'], function () use ($migration): void {
                $migration->down();
                $migration->up();
            });
        foreach (['investigation_results', 'investigation_result_attachments', 'investigation_result_file_bindings', 'investigation_result_revisions'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->assertDatabaseCount('permissions', 123);
        $this->assertDatabaseCount('role_permissions', 21);
    }

    public function test_database_guards_preserve_requested_identity_independent_review_and_immutable_history(): void
    {
        $this->fakeProtectedStorage();
        $revision = InvestigationResultRevision::factory()->create();
        $result = $revision->result;
        $link = InvestigationResultAttachment::factory()->create(['result_id' => $result->id]);
        $snapshot = $revision->fresh()->snapshot;
        $other = InvestigationResult::factory()->create();
        foreach ([
            fn () => DB::table('investigation_results')->where('id', $result->id)->delete(),
            fn () => DB::table('investigation_result_revisions')->where('id', $revision->id)->delete(),
            fn () => DB::table('investigation_result_revisions')->where('id', $revision->id)->update(['reason' => 'Rewrite private history']),
            fn () => DB::table('investigation_results')->where('id', $result->id)->update(['item_id' => $other->item_id, 'version' => 2]),
            fn () => DB::table('investigation_results')->where('id', $result->id)->update(['status' => 'released', 'version' => 2]),
            fn () => InvestigationResult::factory()->create(['item_id' => $result->item_id]),
            fn () => DB::table('investigation_result_attachments')->where('id', $link->id)->update(['result_id' => $other->id]),
            fn () => DB::table('investigation_result_file_bindings')->where('file_id', $link->file_id)->delete(),
        ] as $operation) {
            $this->reject($operation);
        }
        $time = $result->last_changed_at;
        $result->forceFill(['status' => 'submitted', 'version' => 2, 'submitted_at' => $time])->save();
        $this->reject(fn () => DB::table('investigation_results')->where('id', $result->id)->update(['status' => 'verified',
            'version' => 3, 'verified_by' => $result->author_id, 'verified_at' => $time]));
        $this->reject(fn () => DB::table('investigation_result_attachments')->where('id', $link->id)->delete());
        $this->reject(fn () => DB::table('investigation_results')->where('id', $result->id)->update(['fields' => '{"changed":true}', 'version' => 3]));
        $result->forceFill(['status' => 'verified', 'version' => 3, 'verified_by' => $other->author_id, 'verified_at' => $time])->save();
        $this->reject(fn () => DB::table('investigation_results')->where('id', $result->id)->update(['status' => 'released',
            'version' => 4, 'released_by' => $result->author_id, 'released_at' => $time]));
        $result->forceFill(['status' => 'released', 'version' => 4, 'released_by' => $other->author_id, 'released_at' => $time])->save();
        $this->reject(fn () => DB::table('investigation_results')->where('id', $result->id)->update(['comment' => 'Rewritten release', 'version' => 5]));
        $this->assertSame('released', $result->fresh()->status);
        $this->assertSame($snapshot, $revision->fresh()->snapshot);
    }

    private function reject(callable $operation): void
    {
        try {
            DB::transaction($operation);
            $this->fail('Invalid result integrity mutation succeeded.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        RefreshDatabaseState::$migrated = false;
    }
}
