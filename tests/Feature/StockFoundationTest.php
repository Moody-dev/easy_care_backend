<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Drug;
use App\Models\DrugBatch;
use App\Models\DrugPack;
use App\Models\DrugPrice;
use App\Models\StockBalance;
use App\Models\StockMovement;
use App\Models\StockStore;
use App\Models\StockUnit;
use App\Models\Supplier;
use App\Models\User;
use App\Support\FixedPoint;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class StockFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_catalog_and_ledger_relationships_keep_exact_quantities_and_provenance(): void
    {
        $pack = DrugPack::factory()->create(['base_quantity_micros' => FixedPoint::parse('12.5')]);
        $drug = $pack->drug;
        $price = DrugPrice::factory()->create(['drug_id' => $drug->id, 'amount_micros' => FixedPoint::parse('10.100001')]);
        $batch = DrugBatch::factory()->create(['drug_id' => $drug->id]);
        $store = StockStore::factory()->create(['department_id' => Department::factory()->create()->id]);
        $supplier = Supplier::factory()->create();
        $entry = StockMovement::factory()->create(['batch_id' => $batch->id, 'store_id' => $store->id, 'pack_id' => $pack->id,
            'supplier_id' => $supplier->id, 'quantity_micros' => FixedPoint::parse($pack->convertToBase('2.5')),
            'unit_cost_micros' => FixedPoint::parse('0.100001'), 'currency' => 'NGN']);
        $balance = StockBalance::sole();
        $this->assertSame('31.250000', FixedPoint::format($entry->fresh()->quantity_micros));
        $this->assertSame(100001, $entry->fresh()->unit_cost_micros);
        $this->assertSame(10100001, $price->fresh()->amount_micros);
        $this->assertTrue($balance->batch->is($batch));
        $this->assertTrue($balance->drug->is($drug));
        $this->assertTrue($balance->store->is($store));
        $this->assertTrue($balance->lastMovement->is($entry));
        $this->assertTrue($entry->batch->is($batch));
        $this->assertTrue($entry->pack->is($pack));
        $this->assertTrue($entry->supplier->is($supplier));
        $this->assertTrue($entry->createdBy->stockMovements->contains($entry));
        $this->assertTrue($drug->createdBy->createdDrugs->contains($drug));
        $this->assertTrue($drug->baseUnit->drugs->contains($drug));
        $this->assertTrue($store->department->stockStores->contains($store));
        $this->assertTrue($drug->batches->contains($batch));
        $this->assertTrue($drug->packs->contains($pack));
        $this->assertTrue($drug->prices->contains($price));
        $this->assertTrue($store->balances->sole()->batch->is($batch));
        $this->assertTrue($batch->movements->sole()->is($entry));
        $this->assertSame(1, $balance->movement_count);
    }

    public function test_append_only_pack_versions_preserve_conversion_used_by_earlier_movement(): void
    {
        $pack = DrugPack::factory()->create();
        $entry = StockMovement::factory()->create(['batch_id' => DrugBatch::factory()->create(['drug_id' => $pack->drug_id])->id, 'pack_id' => $pack->id]);
        $next = DrugPack::factory()->create(['drug_id' => $pack->drug_id, 'code' => $pack->code, 'version' => 2, 'base_quantity_micros' => 20000000]);
        $retired = DrugPack::factory()->create(['drug_id' => $pack->drug_id, 'code' => $pack->code, 'version' => 3, 'is_active' => false]);
        $this->assertSame('10.000000', $entry->fresh()->pack->convertToBase('1'));
        $this->assertSame('20.000000', $next->convertToBase('1'));
        $this->assertFalse($retired->fresh()->is_active);
        $this->reject(fn () => DB::table('drug_packs')->where('id', $pack->id)->update(['base_quantity_micros' => 999]));
        $this->reject(fn () => DrugPack::factory()->create(['drug_id' => $pack->drug_id, 'code' => $pack->code, 'version' => 5]));
        $this->reject(fn () => DrugPack::factory()->create(['drug_id' => $pack->drug_id, 'code' => $pack->code, 'version' => 4, 'recorded_at' => $pack->recorded_at->subSecond()]));
        $this->assertDatabaseCount('drug_packs', 3);
    }

    public function test_prices_preserve_separate_currency_kind_and_effective_dated_history(): void
    {
        $price = DrugPrice::factory()->create();
        $future = now('UTC')->addMonth();
        $next = DrugPrice::factory()->create(['drug_id' => $price->drug_id, 'version' => 2, 'effective_at' => $future, 'amount_micros' => 5000000]);
        DrugPrice::factory()->create(['drug_id' => $price->drug_id, 'currency' => 'USD']);
        DrugPrice::factory()->create(['drug_id' => $price->drug_id, 'kind' => 'cost', 'amount_micros' => 0]);
        $this->assertSame(1250000, $price->fresh()->amount_micros);
        $this->assertSame(5000000, $next->fresh()->amount_micros);
        $this->reject(fn () => DrugPrice::factory()->create(['drug_id' => $price->drug_id, 'version' => 3, 'effective_at' => now('UTC')]));
        $this->reject(fn () => DB::table('drug_prices')->where('id', $price->id)->update(['amount_micros' => 1]));
        $this->assertDatabaseCount('drug_prices', 4);
    }

    public function test_drug_identity_and_store_department_cannot_be_rebound_and_retirement_is_versioned(): void
    {
        $drug = Drug::factory()->create();
        $store = StockStore::factory()->create();
        $supplier = Supplier::factory()->create();
        $actor = User::factory()->create();
        foreach ([$drug, $store, $supplier] as $model) {
            $this->reject(fn () => DB::table($model->getTable())->where('id', $model->id)->update(['is_active' => false]));
            $model->forceFill(['is_active' => false, 'version' => 2, 'updated_by' => $actor->id, 'updated_at' => now('UTC'), 'change_reason' => 'Synthetic retirement'])->save();
            $this->assertFalse($model->fresh()->is_active);
            $this->assertTrue($model->fresh()->updatedBy->is($actor));
            $this->reject(fn () => DB::table($model->getTable())->where('id', $model->id)->update(['code' => 'changed', 'version' => 3]));
        }
        $this->reject(fn () => DB::table('drugs')->where('id', $drug->id)->update(['strength' => 'Different strength', 'version' => 3]));
        $this->reject(fn () => DB::table('drugs')->where('id', $drug->id)->update(['base_unit_id' => StockUnit::factory()->create()->id, 'version' => 3]));
        $this->reject(fn () => DB::table('stock_stores')->where('id', $store->id)->update(['department_id' => Department::factory()->create()->id, 'version' => 3]));
    }

    public function test_balances_reconcile_by_store_and_batch_and_retain_zero_history(): void
    {
        $opening = StockMovement::factory()->create();
        $otherStore = StockStore::factory()->create();
        $this->movement($opening, ['kind' => 'transfer', 'quantity_micros' => -3000000]);
        $this->movement($opening, ['store_id' => $otherStore->id, 'kind' => 'transfer', 'quantity_micros' => 3000000]);
        $this->movement($opening, ['kind' => 'dispense', 'quantity_micros' => -7000000]);
        $this->assertSame(0, StockBalance::where('store_id', $opening->store_id)->sole()->quantity_micros);
        $this->assertSame(3, StockBalance::where('store_id', $opening->store_id)->sole()->movement_count);
        $this->assertSame(3000000, StockBalance::where('store_id', $otherStore->id)->sole()->quantity_micros);
        $this->assertSame(3000000, (int) DB::table('stock_movements')->sum('quantity_micros'));
        $this->reject(fn () => $this->movement($opening, ['kind' => 'dispense', 'quantity_micros' => -1]));
        $this->assertDatabaseCount('stock_movements', 4);
    }

    public function test_reversal_is_exact_once_attributed_and_never_erases_original(): void
    {
        $opening = StockMovement::factory()->create(['quantity_micros' => 12000000, 'unit_cost_micros' => 500001, 'currency' => 'NGN', 'supplier_id' => Supplier::factory()->create()->id]);
        $out = $this->movement($opening, ['kind' => 'dispense', 'quantity_micros' => -2000000, 'unit_cost_micros' => 500001, 'currency' => 'NGN']);
        $reversal = $this->movement($out, ['kind' => 'reversal', 'quantity_micros' => 2000000, 'reversal_of' => $out->id]);
        $this->assertTrue($out->fresh()->reversal->is($reversal));
        $this->assertTrue($reversal->reverses->is($out));
        $this->assertSame(12000000, StockBalance::sole()->quantity_micros);
        $this->reject(fn () => $this->movement($out, ['kind' => 'reversal', 'quantity_micros' => 2000000, 'reversal_of' => $out->id]));
        $this->reject(fn () => $this->movement($reversal, ['kind' => 'reversal', 'quantity_micros' => -2000000, 'reversal_of' => $reversal->id]));
        $this->reject(fn () => $this->movement($opening, ['kind' => 'reversal', 'quantity_micros' => -12000000, 'unit_cost_micros' => 1, 'reversal_of' => $opening->id]));
        $this->assertDatabaseCount('stock_movements', 3);
    }

    public function test_reversing_receipt_cannot_remove_stock_already_consumed(): void
    {
        $opening = StockMovement::factory()->create();
        $this->movement($opening, ['kind' => 'dispense', 'quantity_micros' => -1]);
        $this->reject(fn () => $this->movement($opening, ['kind' => 'reversal', 'quantity_micros' => -10000000, 'reversal_of' => $opening->id]));
        $this->assertSame(9999999, StockBalance::sole()->quantity_micros);
    }

    public function test_storage_rejects_cross_drug_batch_pack_and_reversal_links(): void
    {
        $opening = StockMovement::factory()->create();
        $other = DrugPack::factory()->create();
        $this->reject(fn () => $this->movement($opening, ['drug_id' => $other->drug_id]));
        $this->reject(fn () => $this->movement($opening, ['pack_id' => $other->id]));
        $this->reject(fn () => $this->movement($opening, ['kind' => 'reversal', 'quantity_micros' => -10000000, 'reversal_of' => $opening->id, 'store_id' => StockStore::factory()->create()->id]));
        $this->assertDatabaseCount('stock_movements', 1);
    }

    public function test_ledger_batch_and_unit_history_cannot_be_updated_or_deleted_even_through_raw_sql(): void
    {
        $entry = StockMovement::factory()->create();
        foreach ([$entry, $entry->batch, $entry->drug, $entry->drug->baseUnit, $entry->store] as $model) {
            $this->reject(fn () => DB::table($model->getTable())->where('id', $model->id)->delete());
        }
        $this->reject(fn () => DB::table('stock_movements')->where('id', $entry->id)->update(['quantity_micros' => 1]));
        $this->reject(fn () => DB::table('drug_batches')->where('id', $entry->batch_id)->update(['expires_on' => '2040-01-01']));
        $this->reject(fn () => DB::table('stock_units')->where('id', $entry->drug->base_unit_id)->update(['name' => 'Reinterpreted unit']));
        $this->assertSame(10000000, StockBalance::sole()->quantity_micros);
    }

    public function test_read_only_balance_view_rejects_model_and_sql_writes(): void
    {
        StockMovement::factory()->create();
        $balance = StockBalance::sole();
        foreach ([fn () => $balance->save(), fn () => $balance->delete()] as $operation) {
            try {
                $operation();
                $this->fail('A balance model write succeeded.');
            } catch (LogicException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->reject(fn () => DB::table('stock_balances')->update(['quantity_micros' => 1]));
        $this->assertSame(10000000, $balance->fresh()->quantity_micros);
    }

    public function test_duplicate_movement_keys_and_failed_multi_leg_transaction_leave_ledger_unchanged(): void
    {
        $entry = StockMovement::factory()->create();
        $this->assertTablesUnchanged(['stock_movements', 'stock_position_heads'], function () use ($entry): void {
            $this->reject(fn () => $this->movement($entry, ['movement_key' => $entry->movement_key]));
            $this->reject(function () use ($entry): void {
                $this->movement($entry, ['kind' => 'adjustment', 'quantity_micros' => -1000000]);
                $this->movement($entry, ['kind' => 'adjustment', 'quantity_micros' => -10000000]);
            });
        });
        $this->assertSame(10000000, StockBalance::sole()->quantity_micros);
    }

    public function test_drug_specific_precision_and_bounded_balances_are_enforced(): void
    {
        $drug = Drug::factory()->create(['quantity_scale' => 0]);
        $batch = DrugBatch::factory()->create(['drug_id' => $drug->id]);
        $entry = StockMovement::factory()->create(['batch_id' => $batch->id]);
        $this->reject(fn () => $this->movement($entry, ['quantity_micros' => 1]));
        $this->reject(fn () => DrugPack::factory()->create(['drug_id' => $drug->id, 'base_quantity_micros' => 1500000]));
        $maximum = StockMovement::factory()->create(['quantity_micros' => FixedPoint::MAX]);
        $this->reject(fn () => $this->movement($maximum, ['quantity_micros' => 1]));
        $this->assertSame(FixedPoint::MAX, StockBalance::where('batch_id', $maximum->batch_id)->sole()->quantity_micros);
    }

    public function test_batch_dates_identities_and_ledger_clock_are_retained(): void
    {
        $entry = StockMovement::factory()->create();
        $batch = $entry->batch;
        $this->reject(fn () => DrugBatch::factory()->create(['drug_id' => $batch->drug_id, 'batch_number' => $batch->batch_number, 'manufacturer' => $batch->manufacturer]));
        $this->reject(fn () => DrugBatch::factory()->create(['manufactured_on' => '2031-01-01', 'expires_on' => '2030-01-01']));
        $this->reject(fn () => $this->movement($entry, ['recorded_at' => $entry->recorded_at->subSecond()]));
        $this->assertSame('2030-12-31', $batch->expires_on->toDateString());
    }

    public function test_fractional_values_cannot_be_silently_stored_in_sqlite_integer_fields(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            $this->markTestSkipped('SQLite storage types need an explicit integer check; MySQL callers must normalize decimal strings before writing BIGINT columns.');
        }
        $entry = StockMovement::factory()->create();
        $this->reject(fn () => $this->movement($entry, ['quantity_micros' => 1.5]));
        $this->reject(fn () => $this->movement($entry, ['unit_cost_micros' => 1.5, 'currency' => 'NGN']));
        $this->reject(fn () => DrugPack::factory()->create(['base_quantity_micros' => 1.5]));
        $this->reject(fn () => DrugPrice::factory()->create(['amount_micros' => 1.5]));
        $this->reject(fn () => Drug::factory()->create(['quantity_scale' => 1.5]));
    }

    public function test_unicode_catalog_labels_and_invalid_dates_are_checked_at_the_storage_boundary(): void
    {
        $drug = Drug::factory()->create(['name' => 'Synthetic Àbẹ́ unit']);
        $this->assertSame('Synthetic Àbẹ́ unit', $drug->fresh()->name);
        $this->reject(fn () => Drug::factory()->create(['name' => str_repeat('À', 192)]));
        $this->reject(fn () => DrugBatch::factory()->create(['expires_on' => '2030-02-30']));
        $unit = StockUnit::factory()->create();
        $before = $unit->recorded_at->subSecond()->format('Y-m-d H:i:s.u');
        $this->reject(fn () => Drug::factory()->create(['base_unit_id' => $unit->id, 'recorded_at' => $before, 'updated_at' => $before]));
    }

    public function test_internal_counters_cannot_be_forged_or_reset_and_always_match_immutable_history(): void
    {
        $pack = DrugPack::factory()->create();
        DrugPrice::factory()->create(['drug_id' => $pack->drug_id]);
        $entry = StockMovement::factory()->create(['batch_id' => DrugBatch::factory()->create(['drug_id' => $pack->drug_id])->id]);
        $this->movement($entry, ['kind' => 'dispense', 'quantity_micros' => -1500000]);
        $head = DB::table('stock_position_heads')->sole();
        $this->assertSame(8500000, (int) $head->quantity_micros);
        $this->assertSame(StockBalance::sole()->quantity_micros, (int) $head->quantity_micros);
        $this->assertSame(2, (int) $head->movement_count);
        $this->assertSame(2, DB::table('stock_series_heads')->count());
        $this->assertSame([1, 1], DB::table('stock_series_heads')->orderBy('kind')->pluck('version')->map(fn ($value): int => (int) $value)->all());
        foreach ([
            fn () => DB::table('stock_position_heads')->update(['quantity_micros' => 50000000]),
            fn () => DB::table('stock_position_heads')->update(['quantity_micros' => 9500000, 'movement_count' => 3]),
            fn () => DB::table('stock_position_heads')->delete(),
            fn () => DB::table('stock_series_heads')->update(['version' => 0]),
            fn () => DB::table('stock_series_heads')->update(['version' => 2]),
            fn () => DB::table('stock_series_heads')->delete(),
        ] as $operation) {
            $this->assertTablesUnchanged(['stock_position_heads', 'stock_series_heads', 'stock_movements', 'drug_packs', 'drug_prices'], fn () => $this->reject($operation));
        }
    }

    #[DataProvider('invalidMovementData')]
    public function test_invalid_ledger_values_are_rejected(array $changes): void
    {
        $entry = StockMovement::factory()->create();
        $this->reject(fn () => $this->movement($entry, $changes));
        $this->assertDatabaseCount('stock_movements', 1);
    }

    public static function invalidMovementData(): array
    {
        return ['zero' => [['quantity_micros' => 0]], 'negative receipt' => [['kind' => 'receipt', 'quantity_micros' => -1]],
            'positive issue' => [['kind' => 'dispense', 'quantity_micros' => 1]], 'unknown kind' => [['kind' => 'sale']],
            'case variant' => [['kind' => 'RECEIPT']], 'cost without currency' => [['unit_cost_micros' => 1]],
            'currency without cost' => [['currency' => 'NGN']], 'lowercase currency' => [['unit_cost_micros' => 1, 'currency' => 'ngn']],
            'negative cost' => [['unit_cost_micros' => -1, 'currency' => 'NGN']], 'empty reference' => [['reference_id' => ' ']],
            'empty reason' => [['reason' => ' ']], 'orphan reversal' => [['kind' => 'reversal']],
            'overflow' => [['quantity_micros' => FixedPoint::MAX + 1]], 'missing actor' => [['created_by' => 9999999]]];
    }

    #[DataProvider('invalidCatalogData')]
    public function test_invalid_catalog_values_are_rejected(string $class, array $changes): void
    {
        $this->reject(fn () => $class::factory()->create($changes));
    }

    public static function invalidCatalogData(): array
    {
        return [[Drug::class, ['quantity_scale' => 7]], [Drug::class, ['name' => ' ']], [Drug::class, ['version' => 2]],
            [StockUnit::class, ['code' => ' ']], [StockStore::class, ['kind' => 'wardrobe']], [Supplier::class, ['name' => ' ']],
            [DrugPack::class, ['base_quantity_micros' => 0]], [DrugPack::class, ['base_quantity_micros' => -1]],
            [DrugPrice::class, ['amount_micros' => -1]], [DrugPrice::class, ['currency' => 'ngn']], [DrugPrice::class, ['kind' => 'retail']],
            [DrugBatch::class, ['batch_number' => ' ']], [DrugBatch::class, ['manufacturer' => ' ']]];
    }

    /** @param array<string, mixed> $changes */
    private function movement(StockMovement $source, array $changes = []): StockMovement
    {
        return StockMovement::factory()->create($changes + ['batch_id' => $source->batch_id, 'store_id' => $source->store_id,
            'unit_cost_micros' => $source->unit_cost_micros, 'currency' => $source->currency, 'pack_id' => $source->pack_id, 'supplier_id' => $source->supplier_id]);
    }

    private function reject(callable $operation): void
    {
        try {
            DB::transaction($operation);
            $this->fail('Invalid stock storage mutation succeeded.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
    }
}
