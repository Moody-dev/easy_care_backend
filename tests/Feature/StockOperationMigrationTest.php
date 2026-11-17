<?php

namespace Tests\Feature;

use App\Models\ClinicalEntry;
use App\Models\StockMovement;
use App\Models\StockOperationLine;
use App\Models\StockOperationRevision;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class StockOperationMigrationTest extends TestCase
{
    use DatabaseMigrations;

    public function test_upgrade_rollback_and_reapplication_preserve_previous_stock_clinical_records_and_grants(): void
    {
        $this->seed(DatabaseSeeder::class);
        $clinical = ClinicalEntry::factory()->create();
        $existing = StockMovement::factory()->create();
        $revision = StockOperationRevision::factory()->create();
        $line = StockOperationLine::factory()->create(['operation_id' => $revision->operation_id]);
        $this->assertTrue($revision->operation->lines->sole()->is($line));
        $this->assertTrue($line->batch->drug->is($line->drug));
        $migration = require database_path('migrations/2026_11_17_030741_create_stock_operation_records.php');
        $this->assertTablesUnchanged(['stock_movements','stock_position_heads','stock_series_heads','drugs','drug_packs','drug_batches','drug_prices','stock_stores',
            'suppliers','users','patients','encounters','clinical_records','clinical_entries','permissions','role_permissions','permission_baselines'], function () use ($migration): void {
                $migration->down();
                $this->assertFalse(Schema::hasTable('stock_operations'));
                $migration->up();
                foreach (['stock_operations','stock_operation_lines','stock_operation_revisions','stock_catalog_revisions','stock_store_accesses','stock_invoice_receipts','stock_operation_legs'] as $table) {
                    $this->assertDatabaseCount($table, 0);
                }
            });
        $this->assertNotNull($clinical->fresh());
        $this->assertSame($existing->quantity_micros, $existing->fresh()->quantity_micros);
        try {
            DB::transaction(fn () => DB::table('stock_movements')->where('id', $existing->id)->update(['quantity_micros' => 1]));
            $this->fail('The older ledger immutability trigger was lost.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
        $this->assertDatabaseCount('permissions', 80);
        $this->assertDatabaseCount('role_permissions', 21);
        $this->assertDatabaseCount('permission_baselines', 4);
        $this->assertSame(1, StockOperationRevision::factory()->create()->version);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        RefreshDatabaseState::$migrated = false;
    }
}
