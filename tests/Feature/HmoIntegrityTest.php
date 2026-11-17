<?php

namespace Tests\Feature;

use App\Models\HmoCoverage;
use App\Models\HmoPlan;
use App\Models\HmoProvider;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class HmoIntegrityTest extends TestCase
{
    use RefreshDatabase;

    public function test_empty_migration_round_trip_preserves_existing_records_and_opt_in_grants(): void
    {
        $this->seed(DatabaseSeeder::class);
        $migration=require database_path('migrations/2026_11_17_152355_create_hmo_workflow_records.php');
        $this->assertTablesUnchanged(['permissions','role_permissions','users','services','patients','billing_accounts'],function () use ($migration): void {
            $migration->down();
            $this->assertFalse(Schema::hasTable('hmo_providers'));
            $this->assertFalse(Schema::hasColumn('billing_invoice_items','tariff_id'));
            $migration->up();
        });
        $this->assertDatabaseCount('hmo_providers',0);
        $this->assertDatabaseCount('permissions',113);
        $this->assertDatabaseCount('role_permissions',21);
    }

    public function test_models_keep_patient_plan_provider_relationships_and_immutable_coverage(): void
    {
        $coverage=HmoCoverage::factory()->create();
        $this->assertTrue($coverage->account->patient->exists);
        $this->assertInstanceOf(HmoPlan::class,$coverage->plan);
        $this->assertInstanceOf(HmoProvider::class,$coverage->plan->provider);
        $this->expectException(QueryException::class);
        $coverage->forceFill(['member_number'=>'Changed retained member'])->save();
    }

    public function test_rollback_refuses_to_discard_even_nonfinancial_membership_or_catalog_records(): void
    {
        HmoProvider::factory()->create();
        $migration=require database_path('migrations/2026_11_17_152355_create_hmo_workflow_records.php');
        try { $migration->down(); $this->fail('Retained identities must prevent rollback.'); }
        catch (\RuntimeException $exception) { $this->assertStringContainsString('Preserve',$exception->getMessage()); }
        $this->assertTrue(Schema::hasTable('hmo_providers'));
        $this->assertDatabaseCount('hmo_providers',1);
        $this->assertTrue(Schema::hasColumn('billing_invoice_items','tariff_id'));
    }

    public function test_invalid_polymorphic_revocation_cannot_reference_another_patient_account(): void
    {
        $coverage=HmoCoverage::factory()->create();
        $other=\App\Models\BillingAccount::factory()->create();
        $this->expectException(QueryException::class);
        DB::table('hmo_revocations')->insert(['account_id'=>$other->id,'kind'=>'coverage','record_id'=>$coverage->id,
            'actor_id'=>$coverage->actor_id,'recorded_at'=>now('UTC')->format('Y-m-d H:i:s.u'),'reason'=>'Synthetic invalid foreign coverage']);
    }
}
