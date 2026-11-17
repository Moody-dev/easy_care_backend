<?php

namespace Tests\Feature;

use App\Models\ClinicalEntry;
use App\Models\StockMovement;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class StockMigrationTest extends TestCase
{
    use DatabaseMigrations;

    public function test_stock_upgrade_and_rollback_preserve_existing_records_and_default_grants(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->assertDatabaseCount('patient_categories', 7);
        $entry = ClinicalEntry::factory()->create();
        StockMovement::factory()->create();
        $operations = require database_path('migrations/2026_11_17_030741_create_stock_operation_records.php');
        $billing = require database_path('migrations/2026_11_17_115716_create_billing_foundations.php');
        $medications = require database_path('migrations/2026_11_17_110715_create_medication_fulfillment_records.php');
        $migration = require database_path('migrations/2026_11_16_192621_create_medication_stock_foundations.php');
        $this->assertTablesUnchanged(['users', 'roles', 'departments', 'permissions', 'role_permissions', 'permission_baselines', 'patient_categories',
            'patients', 'appointments', 'encounters', 'clinical_records', 'clinical_entries', 'clinical_templates', 'investigation_items', 'investigation_results'],
            function () use ($migration, $operations, $medications, $billing): void {
                $billingWorkflow = require database_path('migrations/2026_11_17_121111_add_billing_workflow_guards.php');
                $hmoMigration = require database_path('migrations/2026_11_17_152355_create_hmo_workflow_records.php');
                $hmoMigration->down();
                $billingWorkflow->down();
                $billing->down();
                $medications->down();
                $operations->down();
                $migration->down();
                $this->assertFalse(Schema::hasView('stock_balances'));
                $this->assertFalse(Schema::hasTable('drugs'));
                $migration->up();
                foreach (['stock_units', 'drugs', 'drug_packs', 'suppliers', 'stock_stores', 'drug_batches', 'drug_prices', 'stock_movements', 'stock_balances', 'stock_position_heads', 'stock_series_heads'] as $table) {
                    $this->assertDatabaseCount($table, 0);
                }
                $operations->up();
                $medications->up();
                $billing->up();
                $billingWorkflow->up();
                $hmoMigration->up();
            });
        $this->seed(DatabaseSeeder::class);
        $this->assertDatabaseCount('drugs', 0);
        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertDatabaseCount('permissions', 113);
        $this->assertDatabaseCount('role_permissions', 21);
        $this->assertDatabaseCount('permission_baselines', 4);
        $this->assertNotNull($entry->fresh());
        $this->assertSame(10000000, StockMovement::factory()->create()->quantity_micros);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        RefreshDatabaseState::$migrated = false;
    }
}
