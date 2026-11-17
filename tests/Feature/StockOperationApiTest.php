<?php

namespace Tests\Feature;

use App\Models\DrugBatch;
use App\Models\StockBalance;
use App\Models\StockMovement;
use App\Models\StockOperation;
use App\Models\StockStoreAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\StockOperationsTestCase;

class StockOperationApiTest extends StockOperationsTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(\Carbon\CarbonImmutable::parse('2026-11-16T12:00:00Z'));
        $this->stockFixtures();
        $this->stockLogin($this->preparer);
    }

    public function test_receipt_requires_independent_approval_and_retains_invoice_cost_and_one_confirmed_post(): void
    {
        $draft = $this->draftStock($this->receiptData());
        $this->assertDatabaseCount('stock_movements', 0);
        $submitted = $this->submitStock($draft);
        $data = ['version' => $submitted['version'], 'reason' => 'Private independent stock approval', 'current_password' => self::STAFF_TEST_PASSWORD];
        $path = '/api/v1/stock/operations/'.$draft['id'].'/approve';
        $this->postCommand($path, $data)->assertForbidden();
        $this->stockLogin($this->approver);
        $this->postCommand($path, $data + [])->assertOk()->assertJsonPath('data.status', 'posted');
        $saved = StockOperation::findOrFail($draft['id']);
        $this->assertSame(4, $saved->version);
        $this->assertSame($this->approver->id, $saved->reviewed_by);
        $this->assertSame(10000000, StockBalance::sole()->quantity_micros);
        $movement = StockMovement::sole();
        $this->assertSame(1250001, $movement->unit_cost_micros);
        $this->assertSame('NGN', $movement->currency);
        $this->assertSame($this->supplier->id, $movement->supplier_id);
        $this->assertSame('Synthetic-INV-1', $saved->invoice_number);
        $this->assertDatabaseCount('stock_operation_legs', 1);
        $this->assertDatabaseCount('stock_invoice_receipts', 1);
        $this->assertDatabaseCount('stock_operation_revisions', 4);
        $this->getJson('/api/v1/stock/reconciliation')->assertOk()->assertJsonPath('data.0.consistent', true);
    }

    public function test_confirmed_retry_keeps_one_receipt_and_changed_data_or_lost_access_cannot_replay(): void
    {
        $submitted = $this->submitStock($this->draftStock($this->receiptData()));
        $headers = ['Idempotency-Key' => 'stock-post-confirmed-retry'];
        $posted = $this->approveStock($submitted, $headers);
        $path = '/api/v1/stock/operations/'.$posted['id'].'/approve';
        $data = ['version' => $submitted['version'], 'reason' => 'Private independent stock approval', 'current_password' => self::STAFF_TEST_PASSWORD];
        $this->postCommand($path, $data, $headers)->assertOk()->assertHeader('Idempotency-Replayed', 'true')->assertJsonPath('data.id', $posted['id']);
        $this->postCommand($path, array_replace($data, ['reason' => 'Different intended approval']), $headers)->assertConflict()->assertJsonPath('code', 'idempotency_conflict');
        StockStoreAccess::query()->where('user_id', $this->approver->id)->where('store_id', $this->mainStore->id)
            ->update(['is_active' => false, 'version' => 2, 'changed_by' => $this->preparer->id, 'changed_at' => now('UTC')->format('Y-m-d H:i:s.u'), 'reason' => 'Synthetic access withdrawal']);
        $this->postCommand($path, $data, $headers)->assertNotFound();
        $this->assertDatabaseCount('stock_movements', 1);
    }

    public function test_transfer_posts_equal_opposing_legs_and_keeps_global_quantity_unchanged(): void
    {
        $this->postReceipt();
        $posted = $this->approveStock($this->submitStock($this->draftStock($this->outgoingData())));
        $this->assertSame('posted', $posted['status']);
        $this->assertCount(2, $posted['items'][0]['movement_ids']);
        $this->assertSame(6000000, StockBalance::where('store_id', $this->mainStore->id)->sole()->quantity_micros);
        $this->assertSame(4000000, StockBalance::where('store_id', $this->departmentStore->id)->sole()->quantity_micros);
        $this->assertSame(10000000, (int) DB::table('stock_movements')->sum('quantity_micros'));
        $this->getJson('/api/v1/stock/reconciliation')->assertOk()->assertJsonPath('data.0.consistent', true)->assertJsonPath('data.1.consistent', true);
    }

    public function test_multi_batch_receipt_and_transfer_preserve_each_line_and_reconcile_all_positions(): void
    {
        $other = DrugBatch::factory()->create(['drug_id' => $this->batch->drug_id]);
        $this->publishFixture('batches', $other);
        $data = $this->receiptData();
        $data['items'] = [['batch_id' => $other->id, 'drug_version' => 1, 'quantity' => '3', 'unit_cost' => '0.100001'],
            ['batch_id' => $this->batch->id, 'drug_version' => 1, 'pack_id' => $this->pack->id, 'pack_count' => '2.5', 'unit_cost' => '1.250001']];
        $receipt = $this->approveStock($this->submitStock($this->draftStock($data)));
        $this->assertCount(2, $receipt['items']);
        $this->assertSame(28000000, (int) DB::table('stock_movements')->sum('quantity_micros'));
        $transfer = $this->outgoingData('transfer', '2');
        $transfer['items'][] = ['batch_id' => $other->id, 'drug_version' => 1, 'quantity' => '2', 'source_balance_version' => 1];
        $this->approveStock($this->submitStock($this->draftStock($transfer)));
        $this->assertDatabaseCount('stock_movements', 6);
        $this->assertSame(28000000, (int) DB::table('stock_movements')->sum('quantity_micros'));
        $positions = $this->getJson('/api/v1/stock/reconciliation')->assertOk()->json('data');
        $this->assertCount(4, $positions);
        foreach ($positions as $position) {
            $this->assertTrue($position['consistent']);
        }
    }

    public function test_department_requisition_posts_only_for_requesters_own_department_and_independent_approval(): void
    {
        $this->postReceipt();
        $posted = $this->approveStock($this->submitStock($this->draftStock($this->outgoingData('requisition'))));
        $this->assertSame('requisition', $posted['kind']);
        $this->assertSame(4000000, StockBalance::where('store_id', $this->departmentStore->id)->sole()->quantity_micros);
        $this->preparer->forceFill(['department_id' => $this->approver->department_id])->save();
        $this->stockLogin($this->preparer);
        $this->postCommand('/api/v1/stock/operations', $this->outgoingData('requisition', '1', 2))->assertForbidden();
    }

    public function test_scope_hides_documents_movements_balances_history_and_reconciliation_without_store_grants(): void
    {
        $record = $this->postReceipt();
        $this->stockLogin($this->outsider);
        foreach (['operations','balances','movements','reconciliation','my-stores'] as $path) {
            $response = $this->getJson('/api/v1/stock/'.$path)->assertOk();
            $this->assertSame([], $response->json('data'));
        }
        $this->getJson('/api/v1/stock/operations/'.$record['id'])->assertNotFound();
        $this->getJson('/api/v1/stock/operations/'.$record['id'].'/history')->assertNotFound();
        $this->postCommand('/api/v1/stock/operations', $this->receiptData('OTHER'))->assertNotFound();
    }

    public function test_bad_password_stale_version_foreign_author_and_submitted_edits_do_not_write_stock(): void
    {
        $draft = $this->draftStock($this->receiptData());
        $this->stockLogin($this->approver);
        $this->patchCommand('/api/v1/stock/operations/'.$draft['id'], $this->receiptData() + ['version' => 1])->assertForbidden();
        $submitted = $this->submitStock($draft);
        $this->patchCommand('/api/v1/stock/operations/'.$draft['id'], $this->receiptData() + ['version' => 2])->assertConflict();
        $this->stockLogin($this->approver);
        $path = '/api/v1/stock/operations/'.$draft['id'].'/approve';
        $this->postCommand($path, ['version' => 2, 'reason' => 'Synthetic review', 'current_password' => 'wrong-password'])->assertUnprocessable();
        $this->postCommand($path, ['version' => 1, 'reason' => 'Synthetic review', 'current_password' => self::STAFF_TEST_PASSWORD])->assertConflict();
        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertDatabaseCount('stock_invoice_receipts', 0);
    }

    public function test_duplicate_supplier_invoice_cannot_post_again_with_another_key_or_letter_case(): void
    {
        $this->postReceipt();
        $submitted = $this->submitStock($this->draftStock($this->receiptData('synthetic-inv-1')));
        $this->stockLogin($this->approver);
        $this->postCommand('/api/v1/stock/operations/'.$submitted['id'].'/approve',
            ['version' => 2, 'reason' => 'Independent review', 'current_password' => self::STAFF_TEST_PASSWORD])->assertConflict()->assertJsonPath('code', 'stock_invoice_posted');
        $this->assertSame('submitted', StockOperation::findOrFail($submitted['id'])->status);
        $this->assertDatabaseCount('stock_movements', 1);
        $this->assertDatabaseCount('stock_invoice_receipts', 1);
    }

    public function test_changed_source_balance_or_insufficient_stock_does_not_post_any_transfer_leg(): void
    {
        $this->postReceipt();
        $first = $this->submitStock($this->draftStock($this->outgoingData('transfer', '7')));
        $second = $this->submitStock($this->draftStock($this->outgoingData('transfer', '7')));
        $this->approveStock($first);
        $this->stockLogin($this->approver);
        $this->postCommand('/api/v1/stock/operations/'.$second['id'].'/approve',
            ['version' => 2, 'reason' => 'Independent review', 'current_password' => self::STAFF_TEST_PASSWORD])->assertConflict()->assertJsonPath('code', 'stock_balance_changed');
        $insufficient = $this->submitStock($this->draftStock($this->outgoingData('transfer', '7', 2)));
        $this->stockLogin($this->approver);
        $this->postCommand('/api/v1/stock/operations/'.$insufficient['id'].'/approve',
            ['version' => 2, 'reason' => 'Independent review', 'current_password' => self::STAFF_TEST_PASSWORD])->assertConflict()->assertJsonPath('code', 'stock_insufficient');
        $this->assertDatabaseCount('stock_movements', 3);
    }

    public function test_editing_own_draft_replaces_lines_and_retains_original_private_revision(): void
    {
        $draft = $this->draftStock($this->receiptData());
        $data = $this->receiptData();
        $data['items'][0]['quantity'] = '12';
        $data['version'] = 1;
        $updated = $this->patchCommand('/api/v1/stock/operations/'.$draft['id'], $data)->assertOk()->json('data');
        $this->assertSame('12.000000', $updated['items'][0]['quantity']);
        $this->assertSame('10.000000', StockOperation::findOrFail($draft['id'])->revisions()->where('version', 1)->sole()->snapshot['items'][0]['quantity']);
        $posted = $this->approveStock($this->submitStock($updated));
        $this->assertSame('12.000000', $posted['items'][0]['quantity']);
    }

    public function test_pack_conversion_is_exact_and_later_retirement_prevents_submission(): void
    {
        $data = $this->receiptData();
        unset($data['items'][0]['quantity']);
        $data['items'][0] += ['pack_id' => $this->pack->id, 'pack_count' => '2.5'];
        $draft = $this->draftStock($data);
        $this->assertSame('25.000000', $draft['items'][0]['quantity']);
        $this->assertSame('2.500000', $draft['items'][0]['pack_count']);
        $this->pack->replicate()->forceFill(['version' => 2, 'is_active' => false, 'recorded_at' => now('UTC')])->save();
        $this->postCommand('/api/v1/stock/operations/'.$draft['id'].'/submit', ['version' => 1, 'reason' => 'Submit stock document'])
            ->assertConflict()->assertJsonPath('code', 'stock_pack_unavailable');
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_inconsistent_draft_pack_quantity_or_catalog_snapshot_cannot_be_submitted(): void
    {
        $data = $this->receiptData();
        unset($data['items'][0]['quantity']);
        $data['items'][0] += ['pack_id' => $this->pack->id, 'pack_count' => '2.5'];
        $draft = $this->draftStock($data);
        DB::table('stock_operation_lines')->where('operation_id', $draft['id'])->update(['quantity_micros' => 35000000]);
        $this->postCommand('/api/v1/stock/operations/'.$draft['id'].'/submit', ['version' => 1, 'reason' => 'Submit inconsistent quantity'])
            ->assertConflict()->assertJsonPath('code', 'stock_document_mismatch');
        DB::table('stock_operation_lines')->where('operation_id', $draft['id'])->update(['quantity_micros' => 25000000, 'catalog_snapshot' => '{"drug":{"name":"Different identity"}}']);
        $this->postCommand('/api/v1/stock/operations/'.$draft['id'].'/submit', ['version' => 1, 'reason' => 'Submit inconsistent snapshot'])
            ->assertConflict()->assertJsonPath('code', 'stock_document_mismatch');
        $this->assertSame('draft', StockOperation::findOrFail($draft['id'])->status);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_rejection_and_author_cancellation_retain_history_without_posting(): void
    {
        $first = $this->submitStock($this->draftStock($this->receiptData()));
        $this->stockLogin($this->approver);
        $this->postCommand('/api/v1/stock/operations/'.$first['id'].'/reject', ['version' => 2, 'reason' => 'Synthetic invoice mismatch', 'current_password' => self::STAFF_TEST_PASSWORD])
            ->assertOk()->assertJsonPath('data.status', 'rejected');
        $second = $this->draftStock($this->receiptData('another-invoice'));
        $this->postCommand('/api/v1/stock/operations/'.$second['id'].'/cancel', ['version' => 1, 'reason' => 'Synthetic withdrawal', 'current_password' => self::STAFF_TEST_PASSWORD])
            ->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_general_audit_and_command_receipts_exclude_invoice_costs_reasons_and_passwords(): void
    {
        $record = $this->postReceipt();
        $events = DB::table('audit_events')->where('action', 'like', 'stock.%')->get();
        $this->assertNotEmpty($events);
        foreach ($events as $event) {
            $summary = json_decode($event->safe_summary, true, flags: JSON_THROW_ON_ERROR);
            $this->assertEmpty(array_diff(array_keys($summary), ['version','record_id']));
            $this->assertStringNotContainsString('Synthetic-INV', $event->safe_summary);
        }
        $serialized = json_encode(DB::table('idempotency_requests')->get(), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString(self::STAFF_TEST_PASSWORD, $serialized);
        $this->assertStringNotContainsString('Private', $serialized);
        $this->assertSame('Synthetic-INV-1', $this->getJson('/api/v1/stock/operations/'.$record['id'].'/history')->assertOk()->json('data.0.snapshot.invoice_number'));
    }

    public function test_damage_adjustments_and_expiry_write_off_remain_attributed_ledger_entries(): void
    {
        $this->postReceipt();
        $this->approveStock($this->submitStock($this->draftStock($this->outgoingData('damage', '2'))));
        $addition = ['kind' => 'adjustment_in', 'destination_store_id' => $this->mainStore->id, 'destination_store_version' => 1,
            'reference' => 'Synthetic counted stock correction', 'reason' => 'Synthetic correction reason',
            'items' => [['batch_id' => $this->batch->id, 'drug_version' => 1, 'quantity' => '3']]];
        $this->approveStock($this->submitStock($this->draftStock($addition)));
        $this->approveStock($this->submitStock($this->draftStock($this->outgoingData('adjustment_out', '4', 3))));
        $this->assertSame(7000000, StockBalance::sole()->quantity_micros);
        $this->travelTo(\Carbon\CarbonImmutable::parse('2031-01-01T12:00:00Z'));
        $this->approveStock($this->submitStock($this->draftStock($this->outgoingData('expiry', '7', 4))));
        $this->assertSame(0, StockBalance::sole()->quantity_micros);
        $this->assertSame(['receipt','damage','adjustment','adjustment','expiry'], StockMovement::orderBy('id')->pluck('kind')->all());
        $this->assertSame(5, StockBalance::sole()->movement_count);
    }

    public function test_opening_stock_is_explicit_and_can_only_precede_the_first_position_movement(): void
    {
        $data = $this->receiptData();
        unset($data['supplier_id'], $data['supplier_version'], $data['invoice_number']);
        $data['kind'] = 'opening';
        $this->approveStock($this->submitStock($this->draftStock($data)));
        $this->stockLogin($this->preparer);
        $this->postCommand('/api/v1/stock/operations', $data)->assertConflict()->assertJsonPath('code', 'stock_opening_exists');
        $this->assertDatabaseCount('stock_movements', 1);
        $this->assertDatabaseCount('stock_invoice_receipts', 0);
    }

    public function test_expiry_uses_lagos_calendar_day_and_cannot_be_bypassed_by_drafting_earlier(): void
    {
        $this->travelTo(\Carbon\CarbonImmutable::parse('2026-11-16T23:30:00Z'));
        $today = DrugBatch::factory()->create(['drug_id' => $this->batch->drug_id, 'expires_on' => '2026-11-17']);
        $this->publishFixture('batches', $today);
        $data = $this->receiptData();
        $data['items'][0]['batch_id'] = $today->id;
        $this->postCommand('/api/v1/stock/operations', $data)->assertConflict()->assertJsonPath('code', 'stock_batch_expired');
        $draft = $this->draftStock($this->receiptData());
        $submitted = $this->submitStock($draft);
        $this->travelTo(\Carbon\CarbonImmutable::parse('2031-01-01T00:00:00Z'));
        $this->stockLogin($this->approver);
        $this->postCommand('/api/v1/stock/operations/'.$submitted['id'].'/approve', ['version' => 2,
            'reason' => 'Independent expiry review', 'current_password' => self::STAFF_TEST_PASSWORD])
            ->assertConflict()->assertJsonPath('code', 'stock_batch_expired');
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_expiry_write_off_before_expiry_and_backward_server_time_are_rejected(): void
    {
        $this->postReceipt();
        $this->stockLogin($this->preparer);
        $this->postCommand('/api/v1/stock/operations', $this->outgoingData('expiry'))
            ->assertConflict()->assertJsonPath('code', 'stock_batch_not_expired');
        $submitted = $this->submitStock($this->draftStock($this->outgoingData()));
        $this->travelTo(\Carbon\CarbonImmutable::parse('2026-11-16T11:59:59Z'));
        $this->stockLogin($this->approver);
        $this->postCommand('/api/v1/stock/operations/'.$submitted['id'].'/approve', ['version' => 2,
            'reason' => 'Independent stock review', 'current_password' => self::STAFF_TEST_PASSWORD])
            ->assertConflict()->assertJsonPath('code', 'stock_clock_conflict');
        $this->assertDatabaseCount('stock_movements', 1);
    }

    #[DataProvider('postingFailures')]
    public function test_failure_after_a_transfer_leg_or_receipt_completion_rolls_back_every_domain_write(string $table, string $verb): void
    {
        $this->postReceipt();
        $submitted = $this->submitStock($this->draftStock($this->outgoingData()));
        $this->stockLogin($this->approver);
        $triggered = $this->failNextWriteTo($table, $verb);
        $response = $this->assertTablesUnchanged(['stock_movements','stock_operation_legs','stock_position_heads','stock_operations',
            'stock_operation_lines','stock_operation_revisions','stock_invoice_receipts','idempotency_requests'], fn () =>
                $this->postCommand('/api/v1/stock/operations/'.$submitted['id'].'/approve', ['version' => 2,
                    'reason' => 'Independent stock review', 'current_password' => self::STAFF_TEST_PASSWORD]));
        $this->assertTrue($triggered());
        $this->assertApiFailure($response, 500, 'server_error');
        $this->assertSame('submitted', StockOperation::findOrFail($submitted['id'])->status);
        $this->assertSame(0, DB::table('audit_events')->where('action', 'stock.posted')->where('subject_id', $submitted['id'])->where('outcome', 'succeeded')->count());
        $this->assertSame(10000000, StockBalance::sole()->quantity_micros);
    }

    public static function postingFailures(): array
    {
        return [['stock_movements','insert'], ['idempotency_requests','update']];
    }

    public function test_database_guards_keep_posted_content_revisions_and_ledger_bindings_immutable(): void
    {
        $record = $this->postReceipt();
        $operation = StockOperation::findOrFail($record['id']);
        $line = $operation->lines()->sole();
        $revision = $operation->revisions()->where('version', 4)->sole();
        foreach ([
            fn () => DB::table('stock_operations')->where('id', $operation->id)->update(['version' => 5, 'reason' => 'Rewrite posted receipt']),
            fn () => DB::table('stock_operation_lines')->where('id', $line->id)->update(['quantity_micros' => 1]),
            fn () => DB::table('stock_operation_lines')->where('id', $line->id)->delete(),
            fn () => DB::table('stock_operation_revisions')->where('id', $revision->id)->update(['snapshot' => '{}']),
            fn () => DB::table('stock_operation_legs')->delete(),
            fn () => DB::table('stock_invoice_receipts')->delete(),
            fn () => DB::table('stock_store_accesses')->delete(),
        ] as $attempt) {
            $this->assertTablesUnchanged(['stock_operations','stock_operation_lines','stock_operation_revisions','stock_operation_legs','stock_invoice_receipts','stock_store_accesses'], function () use ($attempt): void {
                try {
                    DB::transaction($attempt);
                    $this->fail('An immutable stock record was rewritten.');
                } catch (\Illuminate\Database\QueryException) {
                    $this->addToAssertionCount(1);
                }
            });
        }
        $this->assertSame(10000000, StockBalance::sole()->quantity_micros);
    }

    #[DataProvider('invalidInputs')]
    public function test_invalid_stock_values_do_not_create_documents(string $field, mixed $value): void
    {
        $data = $this->receiptData();
        data_set($data, $field, $value);
        $this->postCommand('/api/v1/stock/operations', $data)->assertUnprocessable();
        $this->assertDatabaseCount('stock_operations', 0);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public static function invalidInputs(): array
    {
        return [['items.0.quantity', 1.25], ['items.0.quantity', '0'], ['items.0.quantity', '0.0000001'],
            ['items.0.quantity', '1e3'], ['items.0.quantity', '1000000000'], ['items.0.unit_cost', '-1'],
            ['currency', 'ngn'], ['reason', ' '], ['patient_id', 1], ['items.0.unknown', 'value']];
    }
}
