<?php

namespace Tests\Feature;

use App\Models\DrugPrice;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MedicationPricingTest extends TestCase
{
    use RefreshDatabase;

    private array $drug;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now('UTC')->setMicroseconds(123456));
        $this->seed(DatabaseSeeder::class);
        $this->grantRolePermissions('pharmacy', ['stock.catalog.view', 'stock.catalog.manage']);
        $this->actingAs($this->createStaffWithRole('pharmacy'));
        $unit = $this->publish('units', ['code' => 'PIECE', 'name' => 'One sellable piece']);
        $this->drug = $this->publish('drugs', ['code' => 'SYN-VIT-C', 'name' => 'Synthetic vitamin C',
            'base_unit_id' => $unit['id'], 'quantity_scale' => 0]);
    }

    private function publish(string $catalog, array $data): array
    {
        if (in_array($catalog, ['drugs', 'prices'], true)) {
            $data['approval_reference'] = 'Synthetic hospital approval';
        }

        return $this->postCommand('/api/v1/stock/catalog/'.$catalog, $data + ['reason' => 'Synthetic approved price change',
            'current_password' => self::STAFF_TEST_PASSWORD])
            ->assertCreated()->json('data');
    }

    public function test_new_ngn_selling_price_is_shared_by_list_and_detail_without_rewriting_history(): void
    {
        $this->publish('prices', ['drug_id' => $this->drug['id'], 'kind' => 'selling', 'currency' => 'NGN', 'expected_version' => 0, 'amount' => '15']);
        $this->getJson('/api/v1/stock/catalog/drugs/'.$this->drug['id'])->assertOk()->assertJsonPath('data.current_selling_price.amount', '15.000000')
            ->assertJsonPath('data.current_selling_price.quantity', '1.000000');
        $this->publish('prices', ['drug_id' => $this->drug['id'], 'kind' => 'selling', 'currency' => 'NGN', 'expected_version' => 1, 'amount' => '30']);
        $this->publish('prices', ['drug_id' => $this->drug['id'], 'kind' => 'cost', 'currency' => 'NGN', 'expected_version' => 0, 'amount' => '10']);
        $this->publish('prices', ['drug_id' => $this->drug['id'], 'kind' => 'selling', 'currency' => 'USD', 'expected_version' => 0, 'amount' => '999']);
        $this->publish('prices', ['drug_id' => $this->drug['id'], 'kind' => 'selling', 'currency' => 'NGN', 'expected_version' => 2,
            'amount' => '40', 'effective_at' => now('UTC')->addDay()->toISOString()]);
        foreach (['/api/v1/stock/catalog/drugs', '/api/v1/stock/catalog/drugs/'.$this->drug['id']] as $path) {
            $key = str_ends_with($path, '/drugs') ? 'data.0.' : 'data.';
            $this->getJson($path)->assertOk()->assertJsonPath($key.'current_selling_price.amount', '30.000000');
        }
        $this->travel(1)->days();
        $this->getJson('/api/v1/stock/catalog/drugs')->assertOk()->assertJsonPath('data.0.current_selling_price.amount', '40.000000');
        $this->assertSame(15000000, DrugPrice::query()->where('kind', 'selling')->where('currency', 'NGN')->where('version', 1)->sole()->amount_micros);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_unapproved_latest_price_is_withheld_instead_of_silently_reusing_an_old_price(): void
    {
        $this->publish('prices', ['drug_id' => $this->drug['id'], 'kind' => 'selling', 'currency' => 'NGN', 'expected_version' => 0, 'amount' => '15']);
        DrugPrice::factory()->create(['drug_id' => $this->drug['id'], 'version' => 2, 'amount_micros' => 30000000]);
        $this->getJson('/api/v1/stock/catalog/drugs')->assertOk()->assertJsonPath('data.0.current_selling_price', null);
    }

    public function test_other_roles_cannot_publish_selling_prices_even_with_catalog_permission(): void
    {
        $this->grantRolePermissions('frontdesk', ['stock.catalog.view', 'stock.catalog.manage']);
        $this->flushSession();
        $this->actingAs($this->createStaffWithRole('frontdesk'));
        $this->postCommand('/api/v1/stock/catalog/prices', ['drug_id' => $this->drug['id'], 'kind' => 'selling', 'currency' => 'NGN',
            'expected_version' => 0, 'amount' => '30', 'reason' => 'Synthetic reason', 'approval_reference' => 'Synthetic approval',
            'current_password' => self::STAFF_TEST_PASSWORD])->assertForbidden();
        $this->assertDatabaseCount('drug_prices', 0);
    }
}
