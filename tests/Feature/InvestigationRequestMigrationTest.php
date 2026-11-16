<?php

namespace Tests\Feature;

use App\Models\InvestigationItem;
use App\Models\InvestigationRevision;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class InvestigationRequestMigrationTest extends TestCase
{
    use DatabaseMigrations;

    public function test_upgrade_and_rollback_retain_existing_catalog_patients_visits_and_grants(): void
    {
        $this->seed(DatabaseSeeder::class);
        $revision = InvestigationRevision::factory()->create();
        $order = $revision->order;
        $item = $order->items->sole();
        $this->assertSame($order->encounter_id, $item->encounter->id);
        $this->assertSame($order->patient_id, $order->patient->id);
        $this->assertTrue($order->patient->investigationOrders->contains($order));
        $this->assertTrue($order->encounter->investigationOrders->contains($order));
        $this->assertTrue($item->test->requestedItems->contains($item));
        $this->assertTrue($item->schema->requestedItems->contains($item));
        $this->assertSame($order->ordered_by, $order->orderedBy->id);
        $this->assertSame($order->assignment_id, $order->assignment->id);
        $migration = require database_path('migrations/2026_11_16_023634_create_investigation_request_records.php');
        $this->assertTablesUnchanged(['patients', 'users', 'encounters', 'doctor_assignments', 'investigation_tests', 'investigation_schemas',
            'services', 'permissions', 'role_permissions', 'permission_baselines', 'audit_events'], function () use ($migration): void {
                $migration->down();
                $migration->up();
            });
        foreach (['investigation_orders', 'investigation_items', 'investigation_revisions'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->assertDatabaseCount('permissions', 65);
        $this->assertDatabaseCount('role_permissions', 21);
    }

    public function test_database_guards_prevent_rebinding_history_mutations_invalid_transitions_and_late_item_insertion(): void
    {
        $revision = InvestigationRevision::factory()->create();
        $item = $revision->order->items->sole();
        $other = InvestigationItem::factory()->create();
        foreach ([
            fn () => DB::table('investigation_orders')->where('id', $revision->order_id)->delete(),
            fn () => DB::table('investigation_revisions')->where('id', $revision->id)->delete(),
            fn () => DB::table('investigation_revisions')->where('id', $revision->id)->update(['reason' => 'Changed private history']),
            fn () => DB::table('investigation_orders')->where('id', $revision->order_id)->update(['clinical_context' => 'Changed context', 'version' => 2]),
            fn () => DB::table('investigation_items')->where('id', $item->id)->delete(),
            fn () => DB::table('investigation_items')->where('id', $item->id)->update(['schema_id' => $other->schema_id]),
            fn () => DB::table('investigation_items')->where('id', $item->id)->update(['status' => 'processing', 'version' => 2,
                'processing_by' => $revision->actor_id, 'processing_at' => $item->last_event_at]),
            fn () => InvestigationItem::factory()->create(['order_id' => $revision->order_id]),
        ] as $operation) {
            try {
                DB::transaction($operation);
                $this->fail('Invalid request history mutation succeeded.');
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame('requested', $item->fresh()->status);
        $this->assertSame($item->schema_id, $item->fresh()->schema_id);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        RefreshDatabaseState::$migrated = false;
    }
}
