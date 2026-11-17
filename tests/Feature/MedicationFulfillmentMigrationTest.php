<?php

namespace Tests\Feature;

use App\Models\StockMovement;
use App\Models\Prescription;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MedicationFulfillmentMigrationTest extends TestCase
{
    use DatabaseMigrations;

    public function test_upgrade_rollback_and_reapplication_preserve_existing_stock_prescriptions_and_grants(): void
    {
        $this->seed(DatabaseSeeder::class);
        $prescription = Prescription::factory()->withItems()->create();
        $movement = StockMovement::factory()->create();
        $migration = require database_path('migrations/2026_11_17_110715_create_medication_fulfillment_records.php');
        $this->assertTablesUnchanged(['stock_movements', 'stock_position_heads', 'drugs', 'drug_batches', 'stock_stores',
            'prescriptions', 'prescription_items', 'clinical_records', 'encounters', 'patients', 'users', 'permissions', 'role_permissions', 'permission_baselines'],
            function () use ($migration): void {
                $migration->down();
                $this->assertFalse(Schema::hasTable('medication_authorizations'));
                $migration->up();
                foreach (['medication_authorizations', 'medication_dispenses', 'medication_returns', 'medication_return_reviews',
                    'medication_administrations', 'medication_events'] as $table) {
                    $this->assertDatabaseCount($table, 0);
                }
            });
        $this->assertNotNull($prescription->fresh());
        $this->assertNotNull($movement->fresh());
        $this->assertDatabaseCount('permissions', 113);
        $this->assertDatabaseCount('role_permissions', 21);
        $this->assertDatabaseCount('permission_baselines', 4);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        RefreshDatabaseState::$migrated = false;
    }
}
