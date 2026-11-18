<?php

namespace Tests\Feature;

use App\Models\StockMovement;
use App\Models\StockStoreAccess;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReportInventoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_stock_reports_include_only_granted_stores_and_never_sum_different_drug_units(): void
    {
        $this->freezeTime();
        $this->seed(DatabaseSeeder::class);
        $this->grantRolePermissions('pharmacy',['reports.inventory','stock.view']);
        $actor=$this->createStaffWithRole('pharmacy');
        $visible=StockMovement::factory()->create(['quantity_micros'=>12500000]);
        StockStoreAccess::factory()->create(['user_id'=>$actor->id,'store_id'=>$visible->store_id]);
        StockMovement::factory()->create(['quantity_micros'=>99000000]);
        StockMovement::factory()->create(['batch_id'=>$visible->batch_id,'store_id'=>$visible->store_id,'kind'=>'dispense','quantity_micros'=>-2500000]);
        $this->actingAs($actor)->getJson('/api/v1/reports/inventory')->assertOk()
            ->assertJsonPath('data.current.positions',1)->assertJsonPath('data.period.movement_entries',2)
            ->assertJsonPath('data.quantity_totals_across_drugs',null)->assertJsonPath('data.valuation_configured',false);
        $this->getJson('/api/v1/reports/positions')->assertOk()->assertJsonPath('data.total',1)->assertJsonPath('data.data.0.quantity','10.000000');
        $this->getJson('/api/v1/reports/inventory?drug_id='.$visible->drug_id)->assertOk()
            ->assertJsonPath('data.period.selected_drug_quantities.incoming','12.500000')
            ->assertJsonPath('data.period.selected_drug_quantities.outgoing','2.500000')->assertJsonPath('data.period.selected_drug_quantities.net_change','10.000000');
        $tomorrow=now('Africa/Lagos')->addDay()->toDateString();
        $this->getJson('/api/v1/reports/inventory?from_date='.$tomorrow.'&to_date='.$tomorrow)->assertOk()
            ->assertJsonPath('data.period.movement_entries',0)->assertJsonPath('data.current.positions',1);
    }

    public function test_known_store_ids_do_not_bypass_store_access_and_movement_rows_do_not_leak_other_stores(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->grantRolePermissions('pharmacy',['reports.inventory','stock.view']);
        $actor=$this->createStaffWithRole('pharmacy');
        $movement=StockMovement::factory()->create();
        $this->actingAs($actor)->getJson('/api/v1/reports/positions')->assertOk()->assertJsonPath('data.total',0);
        $this->getJson('/api/v1/reports/inventory?store_id='.$movement->store_id)->assertNotFound();
        $this->getJson('/api/v1/reports/movements')->assertOk()->assertJsonPath('data.total',0);
    }
}
