<?php

namespace Tests\Feature;

use App\Models\BillingAccount;
use App\Models\BillingInvoice;
use App\Models\BillingReceipt;
use App\Models\Service;
use App\Models\ServicePrice;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class BillingFoundationTest extends TestCase
{
    use DatabaseMigrations;

    private function signature(): array
    {
        return ['actor_id' => \App\Models\User::factory()->create()->id, 'recorded_at' => now('UTC')->format('Y-m-d H:i:s.u'), 'reason' => 'Synthetic billing signature'];
    }

    public function test_foundation_relationships_currency_and_immutable_receipts(): void
    {
        $receipt = BillingReceipt::factory()->create();
        $this->assertTrue($receipt->account->patient->exists);
        $this->assertSame('NGN', $receipt->currency);
        $this->expectException(QueryException::class);
        $receipt->forceFill(['amount_kobo' => 1])->save();
    }

    public function test_prices_keep_their_original_service_identity_and_effective_version(): void
    {
        $service = Service::factory()->create(['category' => 'consultation']);
        $price = ServicePrice::factory()->create(['service_id' => $service->id]);
        $this->assertTrue($price->service->is($service));
        $this->expectException(QueryException::class);
        $price->forceFill(['amount_micros' => 2])->save();
    }

    public function test_cross_patient_allocations_are_rejected(): void
    {
        $invoice = BillingInvoice::factory()->create();
        $price = ServicePrice::factory()->create();
        $signature = $this->signature();
        DB::table('billing_invoice_items')->insert(['invoice_id' => $invoice->id, 'source_type' => 'performed', 'source_id' => 1,
            'source_key' => hash('sha256', 'synthetic-performed'), 'service_price_id' => $price->id, 'drug_price_id' => null,
            'corrects_item_id' => null, 'quantity_micros' => 1000000, 'unit_price_micros' => 100000000,
            'amount_kobo' => 10000, 'description' => 'Synthetic service', 'snapshot' => '{}'] + $signature);
        DB::table('billing_invoice_posts')->insert(['invoice_id' => $invoice->id, 'actor_id' => $invoice->actor_id,
            'recorded_at' => now('UTC')->format('Y-m-d H:i:s.u'), 'reason' => 'Synthetic invoice posting']);
        $receipt = BillingReceipt::factory()->create();
        $this->expectException(QueryException::class);
        DB::table('billing_allocations')->insert(['invoice_id' => $invoice->id, 'receipt_id' => $receipt->id, 'amount_kobo' => 100] + $signature);
    }

    public function test_new_tables_rollback_and_reapply_without_changing_existing_records_or_grants(): void
    {
        $this->seed(DatabaseSeeder::class);
        $service = Service::factory()->create();
        $migration = require database_path('migrations/2026_11_17_115716_create_billing_foundations.php');
        $this->assertTablesUnchanged(['services','users','patients','permissions','role_permissions','permission_baselines','stock_movements','clinical_records'], function () use ($migration): void {
            $migration->down();
            $this->assertFalse(Schema::hasTable('billing_accounts'));
            $migration->up();
            $this->assertDatabaseCount('billing_accounts', 0);
        });
        $this->assertNotNull($service->fresh());
        $this->assertDatabaseCount('permissions', 88);
        $this->assertDatabaseCount('role_permissions', 21);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        RefreshDatabaseState::$migrated = false;
    }
}
