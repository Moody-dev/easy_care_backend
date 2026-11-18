<?php

namespace Tests\Feature;

use App\Models\StockStoreAccess;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class StockCatalogApiTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->manager = $this->createStaffWithRole('admin');
        $this->actingAs($this->manager);
    }

    private function enableCatalog(): void
    {
        $this->grantRolePermissions('admin', ['stock.catalog.view','stock.catalog.manage','stock.access.manage','stock.view','stock.review']);
    }

    private function catalog(string $name, array $data, array $headers = []): array
    {
        $defaults = ['reason' => 'Private synthetic catalog approval', 'current_password' => self::STAFF_TEST_PASSWORD];
        if (in_array($name, ['drugs','packs','prices'], true)) {
            $defaults['approval_reference'] = 'Synthetic hospital approval reference';
        }

        return $this->postCommand('/api/v1/stock/catalog/'.$name, $data + $defaults, $headers)->assertCreated()->json('data');
    }

    private function drug(): array
    {
        $unit = $this->catalog('units', ['code' => 'SYNTHETIC-UNIT', 'name' => 'Synthetic base unit']);

        return $this->catalog('drugs', ['code' => 'SYNTHETIC-DRUG', 'name' => 'Synthetic approved medication', 'base_unit_id' => $unit['id'], 'quantity_scale' => 0]);
    }

    public function test_no_stock_permission_store_grant_or_catalog_record_is_seeded_for_any_role(): void
    {
        $this->getJson('/api/v1/stock/catalog/drugs')->assertForbidden();
        $this->getJson('/api/v1/stock/balances')->assertForbidden();
        foreach (['drugs','stock_units','drug_prices','stock_operations','stock_store_accesses'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->assertDatabaseCount('permissions', 118);
        $this->assertDatabaseCount('role_permissions', 21);
        $this->assertSame(0, DB::table('role_permissions')->join('permissions', 'permissions.id', '=', 'role_permissions.permission_id')->where('permissions.code', 'like', 'stock.%')->count());
    }

    public function test_catalog_publication_exact_retry_and_private_revision_preserve_original_drug_identity(): void
    {
        $this->enableCatalog();
        $drug = $this->drug();
        $headers = ['Idempotency-Key' => 'synthetic-unit-confirmed-create'];
        $unit = $this->catalog('units', ['code' => 'OTHER-UNIT', 'name' => 'Synthetic other unit'], $headers);
        $this->postCommand('/api/v1/stock/catalog/units', ['code' => 'OTHER-UNIT', 'name' => 'Synthetic other unit',
            'reason' => 'Private synthetic catalog approval', 'current_password' => self::STAFF_TEST_PASSWORD], $headers)
            ->assertOk()->assertHeader('Idempotency-Replayed', 'true')->assertJsonPath('data.id', $unit['id']);
        $this->patchCommand('/api/v1/stock/catalog/drugs/'.$drug['id'], ['version' => 1, 'is_active' => false,
            'reason' => 'Retire synthetic identity', 'approval_reference' => 'Synthetic retirement approval', 'current_password' => self::STAFF_TEST_PASSWORD])
            ->assertOk()->assertJsonPath('data.version', 2)->assertJsonPath('data.is_active', false);
        $history = $this->getJson('/api/v1/stock/catalog/drugs/'.$drug['id'].'/history')->assertOk();
        $this->assertFalse($history->json('data.0.snapshot.is_active'));
        $this->assertTrue($history->json('data.1.snapshot.is_active'));
        $this->patchCommand('/api/v1/stock/catalog/drugs/'.$drug['id'], ['version' => 2, 'name' => 'Rebound medication',
            'reason' => 'Attempt identity rewrite', 'approval_reference' => 'Synthetic approval', 'current_password' => self::STAFF_TEST_PASSWORD])->assertUnprocessable();
        $this->assertDatabaseCount('drugs', 1);
    }

    public function test_pack_versions_and_currency_price_streams_preserve_exact_values_without_rounding(): void
    {
        $this->enableCatalog();
        $drug = $this->drug();
        $pack = $this->catalog('packs', ['drug_id' => $drug['id'], 'code' => 'PACK', 'name' => 'Synthetic pack', 'expected_version' => 0, 'base_quantity' => '10', 'is_active' => true]);
        $this->catalog('packs', ['drug_id' => $drug['id'], 'code' => 'PACK', 'name' => 'Synthetic changed pack', 'expected_version' => 1, 'base_quantity' => '20', 'is_active' => true]);
        $this->getJson('/api/v1/stock/catalog/packs/'.$pack['id'])->assertOk()->assertJsonPath('data.base_quantity', '10.000000');
        $this->catalog('prices', ['drug_id' => $drug['id'], 'kind' => 'selling', 'currency' => 'NGN', 'expected_version' => 0, 'amount' => '0.100001']);
        $this->catalog('prices', ['drug_id' => $drug['id'], 'kind' => 'selling', 'currency' => 'USD', 'expected_version' => 0, 'amount' => '0']);
        $this->getJson('/api/v1/stock/catalog/prices?currency=NGN')->assertOk()->assertJsonPath('data.0.amount', '0.100001');
        $this->assertDatabaseCount('drug_prices', 2);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_supplier_updates_store_identity_and_explicit_grant_revoke_are_versioned_and_password_protected(): void
    {
        $this->enableCatalog();
        $supplier = $this->catalog('suppliers', ['code' => 'SUPPLIER', 'name' => 'Synthetic supplier', 'phone' => '+23400000000']);
        $this->patchCommand('/api/v1/stock/catalog/suppliers/'.$supplier['id'], ['version' => 1, 'phone' => '+23400000001',
            'reason' => 'Update contact details', 'current_password' => self::STAFF_TEST_PASSWORD])->assertOk()->assertJsonPath('data.version', 2);
        $store = $this->catalog('stores', ['code' => 'STORE', 'name' => 'Synthetic main store', 'kind' => 'main']);
        $staff = $this->createStaffWithRole('pharmacy');
        $path = '/api/v1/stock/stores/'.$store['id'].'/access/'.$staff->id;
        $data = ['version' => 0, 'is_active' => true, 'reason' => 'Synthetic explicit access', 'current_password' => self::STAFF_TEST_PASSWORD];
        $this->postCommand($path, array_replace($data, ['current_password' => 'wrong']))->assertUnprocessable();
        $this->postCommand($path, $data)->assertOk()->assertJsonPath('data.version', 1);
        $this->assertDatabaseCount('stock_store_accesses', 1);
        $this->postCommand($path, array_replace($data, ['version' => 1, 'is_active' => false]))->assertOk()->assertJsonPath('data.version', 2)->assertJsonPath('data.is_active', false);
        $this->postCommand($path, $data)->assertConflict();
        $this->assertFalse(StockStoreAccess::sole()->is_active);
        $history = $this->getJson('/api/v1/stock/stores/'.$store['id'].'/access/'.$staff->id.'/history')->assertOk();
        $this->assertFalse($history->json('data.0.snapshot.is_active'));
        $this->assertTrue($history->json('data.1.snapshot.is_active'));
        $this->patchCommand('/api/v1/stock/catalog/stores/'.$store['id'], ['version' => 1, 'kind' => 'department',
            'reason' => 'Attempt to rebind store', 'current_password' => self::STAFF_TEST_PASSWORD])->assertUnprocessable();
    }

    public function test_unknown_fields_stale_versions_unapproved_prices_and_fractional_whole_units_fail_closed(): void
    {
        $this->enableCatalog();
        $drug = $this->drug();
        $this->postCommand('/api/v1/stock/catalog/packs', ['drug_id' => $drug['id'], 'code' => 'BAD-PACK', 'name' => 'Synthetic fractional pack',
            'expected_version' => 0, 'base_quantity' => '1.5', 'is_active' => true, 'reason' => 'Synthetic approval',
            'approval_reference' => 'Synthetic hospital approval', 'current_password' => self::STAFF_TEST_PASSWORD])->assertUnprocessable();
        $this->postCommand('/api/v1/stock/catalog/prices', ['drug_id' => $drug['id'], 'kind' => 'cost', 'currency' => 'NGN', 'amount' => '1',
            'expected_version' => 0, 'reason' => 'Missing approval reference', 'current_password' => self::STAFF_TEST_PASSWORD])->assertUnprocessable();
        $this->postCommand('/api/v1/stock/catalog/units', ['code' => 'OTHER', 'name' => 'Synthetic unit', 'quantity_micros' => 100,
            'reason' => 'Synthetic approval', 'current_password' => self::STAFF_TEST_PASSWORD])->assertUnprocessable();
        $this->assertDatabaseCount('drug_packs', 0);
        $this->assertDatabaseCount('drug_prices', 0);
    }

    public function test_batch_registration_retains_manufacturer_dates_and_detects_duplicate_identity(): void
    {
        $this->enableCatalog();
        $drug = $this->drug();
        $values = ['drug_id' => $drug['id'], 'manufacturer' => 'Synthetic manufacturer', 'batch_number' => 'BATCH-001',
            'expires_on' => '2030-12-31'];
        $batch = $this->catalog('batches', $values);
        $this->assertNull($batch['manufactured_on']);
        $this->assertSame('2030-12-31', $batch['expires_on']);
        $this->postCommand('/api/v1/stock/catalog/batches', $values + ['reason' => 'Duplicate batch attempt', 'current_password' => self::STAFF_TEST_PASSWORD])
            ->assertConflict()->assertJsonPath('code', 'stock_batch_conflict');
        $this->assertDatabaseCount('drug_batches', 1);
    }
}
