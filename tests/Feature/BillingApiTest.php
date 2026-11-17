<?php

namespace Tests\Feature;

use App\Models\BillingReceipt;
use App\Models\Role;
use App\Models\Permission;
use App\Models\Encounter;
use App\Models\ServicePrice;
use App\Services\AuditTrail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\BillingTestCase;

class BillingApiTest extends BillingTestCase
{
    use RefreshDatabase;

    public function test_external_receipt_allocation_and_balances_are_recorded_without_payment_processing(): void
    {
        $source = $this->performed();
        $invoice = $this->invoice($source)->assertCreated()->assertJsonPath('data.totals.outstanding', '200.00')->json('data');
        $invoiceId = $invoice['last_record_id'];
        $receipt = $this->receipt($invoice['version'])->assertCreated()->assertJsonPath('data.payments_processed_by_platform', false)->json('data');
        $this->writeBill($receipt['version'], '/allocations', ['receipt_id' => $receipt['last_record_id'], 'invoice_id' => $invoiceId, 'amount' => '200.00'])
            ->assertCreated()->assertJsonPath('data.totals.outstanding', '0.00')->assertJsonPath('data.totals.available_credit', '50.00');
        $this->getJson($this->path('/reconciliation'))->assertOk()->assertJsonPath('data.balanced', true);
        $this->getJson($this->path('/receipts'))->assertOk()->assertJsonPath('data.0.payer_name', 'Synthetic external payer')
            ->assertJsonPath('data.0.transaction_details.receipt_number', 'Synthetic paper receipt');
        $this->assertDatabaseCount('billing_receipts', 1);
        $this->assertDatabaseCount('billing_allocations', 1);
    }

    public function test_deposits_and_partial_payments_never_over_allocate_receipt_or_invoice(): void
    {
        $invoice = $this->invoice($this->performed())->assertCreated()->json('data');
        $id = $invoice['last_record_id'];
        $receipt = $this->receipt($invoice['version'], '50.00')->assertCreated()->json('data');
        $this->writeBill($receipt['version'], '/allocations', ['receipt_id' => $receipt['last_record_id'], 'invoice_id' => $id, 'amount' => '60.00'])->assertConflict();
        $allocated = $this->writeBill($receipt['version'], '/allocations', ['receipt_id' => $receipt['last_record_id'], 'invoice_id' => $id, 'amount' => '50.00'])->assertCreated()->json('data');
        $second = $this->receipt($allocated['version'], '100.00', ['kind' => 'payment'])->assertCreated()->json('data');
        $this->writeBill($second['version'], '/allocations', ['receipt_id' => $second['last_record_id'], 'invoice_id' => $id, 'amount' => '100.00'])
            ->assertCreated()->assertJsonPath('data.totals.outstanding', '50.00')->assertJsonPath('data.totals.available_credit', '0.00');
    }

    public function test_a_source_is_charged_once_even_with_a_new_key_or_lost_confirmation(): void
    {
        $source = $this->performed();
        $key = ['Idempotency-Key' => 'synthetic-billing-invoice-001'];
        $invoice = $this->invoice($source, $key)->assertCreated()->json('data');
        $this->invoice($source, $key)->assertOk()->assertHeader('Idempotency-Replayed', 'true')->assertJsonPath('data.last_record_id', $invoice['last_record_id']);
        $source['version'] = $invoice['version'];
        $this->invoice($source)->assertConflict()->assertJsonPath('code', 'billing_source_charged');
        $this->assertDatabaseCount('billing_invoices', 1);
        $this->assertDatabaseCount('billing_invoice_items', 1);
        $this->assertDatabaseCount('billing_events', 2);
    }

    public function test_pos_details_are_manual_confirmations_and_duplicate_external_references_are_blocked(): void
    {
        $fields = ['method' => 'pos', 'reference' => 'Synthetic-POS-1', 'externally_confirmed' => true,
            'transaction_details' => ['provider' => 'Synthetic provider', 'terminal_id' => 'SYN-TERM', 'authorization_code' => 'SYN-AUTH']];
        $receipt = $this->receipt(0, '20.00', $fields)->assertCreated()->json('data');
        $this->receipt($receipt['version'], '20.00', $fields)->assertConflict()->assertJsonPath('code', 'billing_payment_recorded');
        $this->getJson($this->path('/receipts'))->assertOk()->assertJsonPath('data.0.transaction_details.terminal_id', 'SYN-TERM');
        $fields['reference'] = 'Synthetic-POS-2';
        $fields['externally_confirmed'] = false;
        $this->receipt($receipt['version'], '20.00', $fields)->assertUnprocessable();
    }

    public function test_refund_requests_reserve_credit_and_independent_review_records_an_actual_physical_refund(): void
    {
        $receipt = $this->receipt(0, '100.00')->assertCreated()->json('data');
        $receiptId = $receipt['last_record_id'];
        $pending = $this->writeBill($receipt['version'], '/refunds', ['receipt_id' => $receiptId, 'amount' => '80.00'])->assertCreated()
            ->assertJsonPath('data.totals.available_credit', '20.00')->assertJsonPath('data.totals.refund_reserved', '80.00')->json('data');
        $this->writeBill($pending['version'], '/refunds', ['receipt_id' => $receiptId, 'amount' => '30.00'])->assertConflict();
        $path = '/changes/'.$pending['last_record_id'].'/approve';
        $data = ['payout_confirmed' => true, 'payout_reference' => 'Synthetic physical refund voucher', 'payout_at' => now('UTC')->toISOString(),
            'payout_method' => 'cash', 'payee_name' => 'Synthetic refund recipient', 'payout_details' => []];
        $this->writeBill($pending['version'], $path, $data)->assertForbidden();
        $this->writeBill($pending['version'], $path, [], $this->reviewer)->assertUnprocessable();
        $this->writeBill($pending['version'], $path, $data, $this->reviewer)->assertCreated()
            ->assertJsonPath('data.totals.refunded', '80.00')->assertJsonPath('data.totals.available_credit', '20.00');
        $this->getJson($this->path('/changes'))->assertOk()->assertJsonPath('data.0.payout_method', 'cash')
            ->assertJsonPath('data.0.payee_name', 'Synthetic refund recipient')->assertJsonPath('data.0.payout_confirmed', true);
        $recorded = $this->getJson($this->path('/receipts'))->assertOk()->assertJsonPath('data.0.balance.refunded', '80.00')
            ->assertJsonPath('data.0.balance.available', '20.00')->assertJsonPath('data.0.externally_confirmed', false);
        $this->assertStringEndsWith('Z', $recorded->json('data.0.paid_at'));
        $this->assertDatabaseCount('billing_change_reviews', 1);
    }

    public function test_crediting_a_paid_line_releases_excess_allocations_without_rewriting_the_receipt(): void
    {
        $invoice = $this->invoice($this->performed())->assertCreated()->json('data');
        $invoiceId = $invoice['last_record_id'];
        $item = DB::table('billing_invoice_items')->sole();
        $receipt = $this->receipt($invoice['version'], '200.00')->assertCreated()->json('data');
        $allocated = $this->writeBill($receipt['version'], '/allocations', ['invoice_id' => $invoiceId, 'receipt_id' => $receipt['last_record_id'], 'amount' => '200.00'])->assertCreated()->json('data');
        $pending = $this->writeBill($allocated['version'], '/credits', ['item_id' => $item->id, 'amount' => '50.00'])->assertCreated()->json('data');
        $approved = $this->writeBill($pending['version'], '/changes/'.$pending['last_record_id'].'/approve', [], $this->reviewer)->assertCreated()
            ->assertJsonPath('data.totals.available_credit', '50.00')->assertJsonPath('data.totals.outstanding', '0.00')->json('data');
        $this->assertDatabaseHas('billing_receipts', ['id' => $receipt['last_record_id'], 'amount_kobo' => 20000]);
        $this->assertDatabaseHas('billing_allocation_releases', ['amount_kobo' => 5000]);
        $this->getJson($this->path('/allocations'))->assertOk()->assertJsonPath('data.0.released', '50.00');
        $this->getJson($this->path('/allocation-releases'))->assertOk()->assertJsonPath('data.0.amount', '50.00');
        $this->assertDatabaseHas('billing_invoice_items', ['id' => $item->id, 'amount_kobo' => 20000]);
        $this->getJson($this->path('/reconciliation'))->assertOk()->assertJsonPath('data.balanced', true);
    }

    public function test_full_credit_can_create_a_reviewed_replacement_invoice_and_retains_original_source(): void
    {
        $invoice = $this->invoice($this->performed())->assertCreated()->json('data');
        $item = DB::table('billing_invoice_items')->sole();
        $pending = $this->writeBill($invoice['version'], '/credits', ['item_id' => $item->id, 'amount' => '200.00', 'replacement_quantity' => '1'])->assertCreated()->json('data');
        $this->writeBill($pending['version'], '/changes/'.$pending['last_record_id'].'/approve', [], $this->reviewer)->assertCreated()
            ->assertJsonPath('data.totals.outstanding', '100.00');
        $this->assertDatabaseCount('billing_invoices', 2);
        $this->assertDatabaseCount('billing_invoice_items', 2);
        $this->assertDatabaseHas('billing_invoice_items', ['corrects_item_id' => $item->id, 'source_type' => 'correction', 'amount_kobo' => 10000]);
    }

    public function test_patient_isolation_and_restriction_apply_to_financial_reads_and_writes(): void
    {
        $receipt = $this->receipt(0, '10.00')->assertCreated()->json('data');
        $other = Encounter::factory()->create();
        $this->visit = $other;
        $this->writeBill(0, '/refunds', ['receipt_id' => $receipt['last_record_id'], 'amount' => '1.00'])->assertNotFound();
        $this->assertDatabaseCount('billing_accounts', 1);
        $this->visit->patient->forceFill(['access_status' => 'restricted', 'version' => 2])->save();
        $this->getJson($this->path())->assertNotFound();
        $this->receipt(0, '10.00')->assertNotFound();
    }

    #[DataProvider('invalidAmounts')]
    public function test_receipt_amounts_cannot_be_rounded_or_coerced_from_invalid_input(mixed $amount): void
    {
        $this->receipt(0, '10.00', ['amount' => $amount])->assertUnprocessable();
        $this->assertDatabaseCount('billing_accounts', 0);
        $this->assertDatabaseCount('billing_receipts', 0);
    }

    public static function invalidAmounts(): array
    {
        return [['1.001'], ['-1'], ['0'], ['1e3'], [1.2], ['1000000000000']];
    }

    public function test_catalog_publication_is_versioned_and_historical_prices_remain_frozen(): void
    {
        $this->loginCashier();
        $common = ['reason' => 'Synthetic approved publication', 'current_password' => self::STAFF_TEST_PASSWORD];
        $service = $this->postCommand('/api/v1/billing/catalog/services', ['code' => 'synthetic-clinic', 'name' => 'Synthetic clinic service', 'category' => 'consultation'] + $common)->assertCreated()->json('data');
        $path = '/api/v1/billing/catalog/services/'.$service['id'].'/prices';
        $first = $this->postCommand($path, ['expected_version' => 0, 'unit_price' => '1.005', 'is_active' => true, 'approval_reference' => 'Synthetic hospital tariff'] + $common)->assertCreated()->json('data');
        $this->postCommand($path, ['expected_version' => 0, 'unit_price' => '2.00', 'is_active' => true, 'approval_reference' => 'Synthetic hospital tariff'] + $common)->assertConflict();
        $this->postCommand($path, ['expected_version' => 1, 'unit_price' => '2.00', 'is_active' => true, 'approval_reference' => 'Synthetic hospital tariff'] + $common)->assertCreated();
        $this->assertDatabaseHas('service_prices', ['id' => $first['id'], 'amount_micros' => 1005000, 'version' => 1]);
    }

    public function test_later_price_changes_do_not_reprice_an_earlier_performed_service(): void
    {
        $source = $this->performed();
        $this->travel(1)->minutes();
        ServicePrice::factory()->create(['service_id' => $this->price->service_id, 'version' => 2, 'amount_micros' => 999000000]);
        $this->invoice($source)->assertCreated()->assertJsonPath('data.totals.charges', '200.00');
    }

    public function test_wrong_passwords_and_revoked_permissions_also_block_exact_retries(): void
    {
        $key = ['Idempotency-Key' => 'synthetic-cashier-receipt-retry'];
        $this->receipt(0, '10.00', headers: $key)->assertCreated();
        Role::where('code', 'frontdesk')->sole()->permissions()->detach(Permission::where('code', 'billing.receipts.record')->sole()->id);
        $this->receipt(0, '10.00', headers: $key)->assertForbidden();
        $this->assertDatabaseCount('billing_receipts', 1);
    }

    public function test_audit_storage_failure_rolls_back_the_cashier_record_and_receipt_confirmation(): void
    {
        $this->mock(AuditTrail::class)->shouldReceive('record')->atLeast()->once()->andThrow(new \RuntimeException('Synthetic audit failure'));
        $this->receipt(0, '10.00')->assertStatus(500);
        $this->assertDatabaseCount('billing_receipts', 0);
        $this->assertDatabaseCount('billing_accounts', 0);
        $this->assertDatabaseCount('billing_events', 0);
        $this->assertDatabaseCount('idempotency_requests', 0);
    }

    public function test_current_source_discovery_and_paid_invoice_details_are_bounded(): void
    {
        $source = $this->performed();
        $this->getJson($this->path('/sources/performed?encounter_id='.$this->visit->id))->assertOk()->assertJsonPath('total', 1);
        $invoice = $this->invoice($source)->assertCreated()->json('data');
        $this->getJson($this->path('/sources/performed?encounter_id='.$this->visit->id))->assertOk()->assertJsonPath('total', 0);
        $this->getJson($this->path('/invoices/'.$invoice['last_record_id']))->assertOk()->assertJsonPath('data.items.0.amount', '200.00');
        $this->getJson($this->path('/history?per_page=101'))->assertUnprocessable();
        $this->getJson($this->path('/history?per_page=1'))->assertOk()->assertJsonPath('total', 2);
    }

    public function test_cashier_records_reject_future_or_unstorable_transaction_times_and_card_secrets(): void
    {
        $this->receipt(0, changes: ['paid_at' => now('UTC')->addMinute()->toISOString()])->assertUnprocessable();
        $this->receipt(0, changes: ['paid_at' => '0001-01-01T00:00:00Z'])->assertUnprocessable();
        $this->receipt(0, changes: ['transaction_details' => ['card_number' => 'Synthetic forbidden detail']])->assertUnprocessable();
        $this->assertDatabaseCount('billing_receipts', 0);
    }

    public function test_duplicate_selected_sources_are_validation_errors_and_do_not_leave_an_unsealed_invoice(): void
    {
        $source = $this->performed();
        $line = ['type' => 'performed', 'id' => $source['last_record_id'], 'price_id' => $this->price->id];
        $this->writeBill($source['version'], '/invoices', ['encounter_id' => $this->visit->id, 'encounter_version' => 1,
            'self_pay_confirmed' => true, 'sources' => [$line,$line]])->assertUnprocessable();
        $this->assertDatabaseCount('billing_invoices', 0);
        $this->assertDatabaseCount('billing_invoice_items', 0);
        $this->invoice($source)->assertCreated();
    }

    public function test_billing_can_finish_after_a_visit_is_closed_without_changing_clinical_records(): void
    {
        $source = $this->performed();
        $this->visit->forceFill(['status' => 'in_progress', 'version' => 2])->save();
        $this->visit->forceFill(['status' => 'closed', 'version' => 3, 'closed_at' => now('UTC')])->save();
        $this->writeBill($source['version'], '/invoices', ['encounter_id' => $this->visit->id, 'encounter_version' => 3,
            'self_pay_confirmed' => true, 'sources' => [['type' => 'performed', 'id' => $source['last_record_id'], 'price_id' => $this->price->id]]])
            ->assertCreated()->assertJsonPath('data.totals.outstanding', '200.00');
        $this->assertSame('closed', $this->visit->fresh()->status);
        $this->assertSame(3, $this->visit->fresh()->version);
    }

    public function test_rejected_refund_releases_reserved_credit_without_recording_a_payout(): void
    {
        $receipt = $this->receipt(0, '100.00')->assertCreated()->json('data');
        $change = $this->writeBill($receipt['version'], '/refunds', ['receipt_id' => $receipt['last_record_id'], 'amount' => '60.00'])->assertCreated()->json('data');
        $this->writeBill($change['version'], '/changes/'.$change['last_record_id'].'/reject', [], $this->reviewer)->assertCreated()
            ->assertJsonPath('data.totals.available_credit', '100.00')->assertJsonPath('data.totals.refunded', '0.00');
        $this->assertDatabaseHas('billing_change_reviews', ['decision' => 'rejected', 'payout_confirmed' => false]);
    }

    public function test_an_existing_empty_foundation_account_has_a_zero_version_and_can_record_its_first_receipt(): void
    {
        \App\Models\BillingAccount::factory()->create(['patient_id' => $this->visit->patient_id]);
        $this->loginCashier();
        $this->getJson($this->path())->assertOk()->assertJsonPath('data.version', 0)->assertJsonPath('data.totals.available_credit', '0.00');
        $this->receipt(0, '10.00')->assertCreated()->assertJsonPath('data.version', 1);
    }

    public function test_reconciliation_recomputes_line_rounding_instead_of_trusting_a_stored_imported_total(): void
    {
        $account = \App\Models\BillingAccount::factory()->create(['patient_id' => $this->visit->patient_id]);
        $invoice = \App\Models\BillingInvoice::factory()->create(['account_id' => $account->id, 'encounter_id' => $this->visit->id, 'total_kobo' => 10000]);
        $signature = ['actor_id' => $invoice->actor_id, 'recorded_at' => now('UTC')->format('Y-m-d H:i:s.u'), 'reason' => 'Synthetic incorrect imported amount'];
        DB::table('billing_invoice_items')->insert(['invoice_id' => $invoice->id, 'source_type' => 'performed', 'source_id' => 99,
            'source_key' => hash('sha256', 'synthetic-import-line'), 'service_price_id' => $this->price->id, 'drug_price_id' => null,
            'corrects_item_id' => null, 'quantity_micros' => 2000000, 'unit_price_micros' => 100000000, 'amount_kobo' => 10000,
            'description' => 'Synthetic imported service', 'snapshot' => '{}'] + $signature);
        DB::table('billing_invoice_posts')->insert(['invoice_id' => $invoice->id] + $signature);
        $this->loginCashier();
        $this->getJson($this->path('/reconciliation'))->assertOk()->assertJsonPath('data.balanced', false)->assertJsonPath('data.issues.0.kind', 'invoice_item');
    }

    public function test_nested_quantity_corrections_keep_the_immediate_predecessor_and_original_source(): void
    {
        $invoice = $this->invoice($this->performed())->assertCreated()->json('data');
        $first = DB::table('billing_invoice_items')->sole();
        $pending = $this->writeBill($invoice['version'], '/credits', ['item_id' => $first->id, 'amount' => '200.00', 'replacement_quantity' => '1'])->assertCreated()->json('data');
        $approved = $this->writeBill($pending['version'], '/changes/'.$pending['last_record_id'].'/approve', [], $this->reviewer)->assertCreated()->json('data');
        $second = DB::table('billing_invoice_items')->where('corrects_item_id', $first->id)->sole();
        $pending = $this->writeBill($approved['version'], '/credits', ['item_id' => $second->id, 'amount' => '100.00', 'replacement_quantity' => '0.5'])->assertCreated()->json('data');
        $this->writeBill($pending['version'], '/changes/'.$pending['last_record_id'].'/approve', [], $this->reviewer)->assertCreated()->assertJsonPath('data.totals.outstanding', '50.00');
        $third = DB::table('billing_invoice_items')->where('corrects_item_id', $second->id)->sole();
        $snapshot = json_decode($third->snapshot, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame($second->id, $snapshot['corrects_item_id']);
        $this->assertSame($first->source_id, $snapshot['source_id']);
        $this->assertDatabaseCount('billing_invoice_items', 3);
    }

    public function test_financial_database_failures_do_not_log_private_payer_or_reference_bindings(): void
    {
        \Illuminate\Support\Facades\Log::spy();
        $this->mock(AuditTrail::class)->shouldReceive('record')->andThrow(new \Illuminate\Database\QueryException(
            'mysql', 'private payer binding sentinel', [], new \Exception('private bank reference sentinel')));
        $response = $this->receipt(0, '10.00')->assertStatus(500);
        $this->assertStringNotContainsString('sentinel', $response->getContent());
        \Illuminate\Support\Facades\Log::shouldHaveReceived('error')->with('A billing database operation failed.');
        $this->assertDatabaseCount('billing_receipts', 0);
    }
}
