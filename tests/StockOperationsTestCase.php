<?php

namespace Tests;

use App\Models\Department;
use App\Models\DrugBatch;
use App\Models\DrugPack;
use App\Models\StockCatalogRevision;
use App\Models\StockStore;
use App\Models\StockStoreAccess;
use App\Models\Supplier;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\Eloquent\Model;

abstract class StockOperationsTestCase extends TestCase
{
    protected User $preparer;

    protected User $approver;

    protected User $outsider;

    protected StockStore $mainStore;

    protected StockStore $departmentStore;

    protected Supplier $supplier;

    protected DrugBatch $batch;

    protected DrugPack $pack;

    protected function stockFixtures(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->grantRolePermissions('pharmacy', array_values(array_filter(array_keys(config('authorization.permissions')), fn (string $code): bool => str_starts_with($code, 'stock.'))));
        $department = Department::where('code', 'nurse')->sole();
        $this->preparer = $this->createStaffWithRole('pharmacy', ['department_id' => $department->id]);
        $this->approver = $this->createStaffWithRole('pharmacy');
        $this->outsider = $this->createStaffWithRole('pharmacy');
        $this->mainStore = StockStore::factory()->create();
        $this->departmentStore = StockStore::factory()->create(['kind' => 'department', 'department_id' => $department->id]);
        $this->supplier = Supplier::factory()->create();
        $this->pack = DrugPack::factory()->create(['base_quantity_micros' => 10000000]);
        $this->batch = DrugBatch::factory()->create(['drug_id' => $this->pack->drug_id]);
        foreach ([['suppliers',$this->supplier], ['drugs',$this->pack->drug], ['packs',$this->pack], ['batches',$this->batch]] as [$catalog,$record]) {
            $this->publishFixture($catalog, $record);
        }
        foreach ([$this->preparer,$this->approver] as $staff) {
            foreach ([$this->mainStore,$this->departmentStore] as $store) {
                StockStoreAccess::factory()->create(['user_id' => $staff->id, 'store_id' => $store->id]);
            }
        }
    }

    protected function publishFixture(string $catalog, Model $record): void
    {
        StockCatalogRevision::factory()->create(['catalog' => $catalog, 'record_id' => $record->id, 'version' => $record->version ?? 1]);
    }

    /** @return array<string, mixed> */
    protected function receiptData(string $invoice = 'Synthetic-INV-1'): array
    {
        return ['kind' => 'receipt', 'destination_store_id' => $this->mainStore->id, 'destination_store_version' => 1,
            'supplier_id' => $this->supplier->id, 'supplier_version' => 1, 'invoice_number' => $invoice, 'currency' => 'NGN',
            'reference' => 'Synthetic goods receipt', 'reason' => 'Private synthetic purchasing reason',
            'items' => [['batch_id' => $this->batch->id, 'drug_version' => 1, 'quantity' => '10', 'unit_cost' => '1.250001']]];
    }

    /** @return array<string, mixed> */
    protected function outgoingData(string $kind = 'transfer', string $quantity = '4', int $balanceVersion = 1): array
    {
        $data = ['kind' => $kind, 'source_store_id' => $this->mainStore->id, 'source_store_version' => 1,
            'reference' => 'Synthetic stock reference', 'reason' => 'Private synthetic stock operation reason',
            'items' => [['batch_id' => $this->batch->id, 'drug_version' => 1, 'quantity' => $quantity, 'source_balance_version' => $balanceVersion]]];
        if (in_array($kind, ['transfer','requisition'], true)) {
            $data += ['destination_store_id' => $this->departmentStore->id, 'destination_store_version' => 1];
        }

        return $data;
    }

    protected function stockLogin(User $user): static
    {
        $this->flushSession();
        $this->actingAs($user);

        return $this;
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    protected function draftStock(array $data, array $headers = []): array
    {
        $this->stockLogin($this->preparer);

        return $this->postCommand('/api/v1/stock/operations', $data, $headers)->assertCreated()->json('data');
    }

    /** @param array<string, mixed> $record @return array<string, mixed> */
    protected function submitStock(array $record): array
    {
        $this->stockLogin($this->preparer);

        return $this->postCommand('/api/v1/stock/operations/'.$record['id'].'/submit',
            ['version' => $record['version'], 'reason' => 'Synthetic submitted for independent review'])->assertOk()->json('data');
    }

    /** @param array<string, mixed> $record @return array<string, mixed> */
    protected function approveStock(array $record, array $headers = []): array
    {
        $this->stockLogin($this->approver);

        return $this->postCommand('/api/v1/stock/operations/'.$record['id'].'/approve',
            ['version' => $record['version'], 'reason' => 'Private independent stock approval', 'current_password' => self::STAFF_TEST_PASSWORD], $headers)->assertOk()->json('data');
    }

    /** @return array<string, mixed> */
    protected function postReceipt(): array
    {
        return $this->approveStock($this->submitStock($this->draftStock($this->receiptData())));
    }
}
