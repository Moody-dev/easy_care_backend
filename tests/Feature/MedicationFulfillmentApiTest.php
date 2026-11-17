<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Models\MedicationAuthorization;
use App\Models\PrescriptionItem;
use App\Models\StockBalance;
use App\Models\StockStoreAccess;
use App\Services\AuditTrail;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\QueryException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\MedicationFulfillmentTestCase;

class MedicationFulfillmentApiTest extends MedicationFulfillmentTestCase
{
    use RefreshDatabase;

    public function test_billing_uses_the_original_dispense_and_approved_selling_price_without_deducting_stock_again(): void
    {
        $this->grantRolePermissions('pharmacy', ['billing.view', 'billing.invoices.post']);
        $price = \App\Models\DrugPrice::factory()->create(['drug_id' => $this->batch->drug_id, 'amount_micros' => 1005000]);
        $this->publishFixture('prices', $price);
        $issued = $this->issue($this->authorizeLine()->assertCreated()->json('data'))->assertCreated()->json('data');
        $path = '/api/v1/billing/patients/'.$this->visit->patient_id.'/invoices';
        $data = ['account_version' => 0, 'encounter_id' => $this->visit->id, 'encounter_version' => 1, 'self_pay_confirmed' => true,
            'sources' => [['type' => 'dispense', 'id' => $issued['last_record_id'], 'price_id' => $price->id]],
            'current_password' => self::STAFF_TEST_PASSWORD, 'reason' => 'Synthetic issued medication billing'];
        $this->postCommand($path, $data)->assertCreated()->assertJsonPath('data.totals.charges', '4.02');
        $data['account_version'] = 1;
        $this->postCommand($path, $data)->assertConflict()->assertJsonPath('code', 'billing_source_charged');
        $this->assertDatabaseCount('stock_movements', 2);
    }

    public function test_partial_issues_preserve_authorized_identity_and_never_exceed_the_cap(): void
    {
        $authorization = $this->authorizeLine()->assertCreated()->json('data');
        $issued = $this->issue($authorization)->assertCreated()->assertJsonPath('data.remaining_issue_quantity', '2.000000')->json('data');
        $this->issue($issued, '3', 2)->assertConflict();
        $this->issue($issued, '2', 2)->assertCreated()->assertJsonPath('data.remaining_issue_quantity', '0.000000');
        $this->assertSame(4000000, StockBalance::where('store_id', $this->mainStore->id)->sole()->quantity_micros);
        $this->assertDatabaseCount('medication_dispenses', 2);
        $this->assertDatabaseCount('medication_events', 3);
        $this->assertSame('Synthetic unlinked medication', $authorization['snapshot']['prescription']['medication_name']);
    }

    public function test_exact_retry_is_one_issue_and_a_stale_or_changed_submission_is_rejected(): void
    {
        $authorization = $this->authorizeLine()->assertCreated()->json('data');
        $headers = ['Idempotency-Key' => 'synthetic-medication-issue-00001'];
        $this->issue($authorization, headers: $headers)->assertCreated()->assertHeader('Idempotency-Replayed', 'false');
        $this->issue($authorization, headers: $headers)->assertOk()->assertHeader('Idempotency-Replayed', 'true');
        $this->issue($authorization, '3', headers: $headers)->assertConflict();
        $this->issue($authorization)->assertConflict();
        $this->assertDatabaseCount('medication_dispenses', 1);
        $this->assertDatabaseCount('stock_movements', 2);
    }

    public function test_returns_require_independent_approval_and_only_usable_returns_restore_stock(): void
    {
        $issued = $this->issue($this->authorizeLine()->assertCreated()->json('data'))->assertCreated()->json('data');
        $dispense = $issued['last_record_id'];
        $pending = $this->command($issued, 'return-request', ['dispense_id' => $dispense, 'quantity' => '2', 'disposition' => 'usable', 'usable_confirmed' => true])->assertCreated()->json('data');
        $review = ['dispense_id' => $dispense, 'return_id' => $pending['last_record_id']];
        $this->command($pending, 'return-approve', $review)->assertForbidden();
        $approved = $this->command($pending, 'return-approve', $review, $this->approver)->assertCreated()->json('data');
        $this->assertSame(8000000, StockBalance::where('store_id', $this->mainStore->id)->sole()->quantity_micros);
        $this->issue($approved, '3', 3)->assertConflict();
        $damaged = $this->command($approved, 'return-request', ['dispense_id' => $dispense, 'quantity' => '2', 'disposition' => 'damaged', 'usable_confirmed' => false])->assertCreated()->json('data');
        $this->command($damaged, 'return-approve', ['dispense_id' => $dispense, 'return_id' => $damaged['last_record_id']], $this->approver)->assertCreated();
        $this->assertDatabaseCount('stock_movements', 3);
        $this->assertDatabaseCount('medication_return_reviews', 2);
    }

    public function test_pending_returns_reserve_quantity_and_rejection_releases_it_for_administration(): void
    {
        $issued = $this->issue($this->authorizeLine()->assertCreated()->json('data'))->assertCreated()->json('data');
        $dispense = $issued['last_record_id'];
        $pending = $this->command($issued, 'return-request', ['dispense_id' => $dispense, 'quantity' => '3', 'disposition' => 'usable', 'usable_confirmed' => true])->assertCreated()->json('data');
        $this->command($pending, 'administer', ['dispense_id' => $dispense, 'quantity' => '2', 'observed_at' => now('UTC')->toISOString()], $this->nurse)->assertConflict();
        $rejected = $this->command($pending, 'return-reject', ['dispense_id' => $dispense, 'return_id' => $pending['last_record_id']], $this->approver)->assertCreated()->json('data');
        $this->command($rejected, 'administer', ['dispense_id' => $dispense, 'quantity' => '4', 'observed_at' => now('UTC')->toISOString()], $this->nurse)->assertCreated();
        $this->assertDatabaseCount('stock_movements', 2);
    }

    public function test_signed_administration_corrections_retain_originals_and_do_not_deduct_stock_again(): void
    {
        $issued = $this->issue($this->authorizeLine()->assertCreated()->json('data'))->assertCreated()->json('data');
        $dispense = $issued['last_record_id'];
        $data = ['dispense_id' => $dispense, 'quantity' => '3', 'observed_at' => now('UTC')->toISOString()];
        $administered = $this->command($issued, 'administer', $data, $this->nurse)->assertCreated()->json('data');
        $data['administration_id'] = $administered['last_record_id'];
        $data['quantity'] = '1';
        $corrected = $this->command($administered, 'correct', $data, $this->nurse)->assertCreated()->json('data');
        $this->command($corrected, 'correct', $data, $this->nurse)->assertConflict();
        $this->assertDatabaseHas('medication_administrations', ['id' => $data['administration_id'], 'quantity_micros' => 3000000]);
        $this->assertDatabaseCount('medication_administrations', 2);
        $this->assertDatabaseCount('stock_movements', 2);
        $this->command($corrected, 'return-request', ['dispense_id' => $dispense, 'quantity' => '4', 'disposition' => 'usable', 'usable_confirmed' => true])->assertConflict();
    }

    public function test_store_access_is_rechecked_even_for_a_completed_issue_retry(): void
    {
        $authorization = $this->authorizeLine()->assertCreated()->json('data');
        $headers = ['Idempotency-Key' => 'synthetic-revoked-store-0001'];
        $this->issue($authorization, headers: $headers)->assertCreated();
        StockStoreAccess::where('user_id', $this->preparer->id)->where('store_id', $this->mainStore->id)
            ->update(['is_active' => false, 'version' => 2, 'changed_by' => $this->approver->id, 'changed_at' => now('UTC')->format('Y-m-d H:i:s.u'), 'reason' => 'Synthetic revocation']);
        $this->issue($authorization, headers: $headers)->assertNotFound();
    }

    public function test_an_unassigned_doctor_cannot_authorize_a_line(): void
    {
        $this->doctor = $this->createStaffWithRole('doctor');
        $this->authorizeLine()->assertForbidden();
        $this->assertDatabaseCount('medication_authorizations', 0);
    }

    #[DataProvider('invalidQuantities')]
    public function test_authorization_rejects_nonexact_quantities(mixed $quantity): void
    {
        $this->authorizeLine(['maximum_quantity' => $quantity])->assertUnprocessable();
        $this->assertDatabaseCount('medication_authorizations', 0);
    }

    public static function invalidQuantities(): array
    {
        return [['0'], ['-1'], ['1.0000001'], ['1e2'], [1.5], ['1000000000'], ['1,000']];
    }

    public function test_duplicate_authorizations_and_wrong_passwords_do_not_commit(): void
    {
        $this->authorizeLine(['current_password' => 'incorrect'])->assertUnprocessable();
        $this->authorizeLine()->assertCreated();
        $this->authorizeLine()->assertConflict();
        $this->assertDatabaseCount('medication_authorizations', 1);
    }

    public function test_stopped_prescriptions_reject_new_issues_but_retain_return_history(): void
    {
        $issued = $this->issue($this->authorizeLine()->assertCreated()->json('data'))->assertCreated()->json('data');
        $this->stockLogin($this->doctor);
        $this->postCommand('/api/v1/encounters/'.$this->visit->id.'/prescriptions/'.$this->item->prescription_id.'/stop',
            ['encounter_version' => 1, 'version' => 2, 'reason' => 'Synthetic order stopped', 'current_password' => self::STAFF_TEST_PASSWORD])->assertOk();
        $this->issue($issued, '1', 2)->assertConflict();
        $this->command($issued, 'return-request', ['dispense_id' => $issued['last_record_id'], 'quantity' => '1', 'disposition' => 'damaged', 'usable_confirmed' => false])->assertCreated();
    }

    public function test_audit_failure_rolls_back_issue_stock_event_and_command_receipt(): void
    {
        $authorization = $this->authorizeLine()->assertCreated()->json('data');
        $count = DB::table('idempotency_requests')->count();
        $this->mock(AuditTrail::class)->shouldReceive('record')->atLeast()->once()->andThrow(new \RuntimeException('Synthetic audit storage failure'));
        $this->issue($authorization)->assertStatus(500);
        $this->assertDatabaseCount('medication_dispenses', 0);
        $this->assertDatabaseCount('stock_movements', 1);
        $this->assertDatabaseCount('medication_events', 1);
        $this->assertDatabaseCount('idempotency_requests', $count);
    }

    public function test_restricted_patients_are_hidden_and_general_audit_has_no_clinical_details(): void
    {
        $authorization = $this->authorizeLine()->assertCreated()->json('data');
        $this->stockLogin($this->preparer);
        $this->getJson('/api/v1/medications/'.$authorization['id'])->assertOk();
        $summaries = DB::table('audit_events')->where('subject_type', 'medication')->pluck('safe_summary')->implode('');
        $this->assertStringNotContainsString('Synthetic unlinked medication', $summaries);
        $this->assertStringNotContainsString('Explicit synthetic', $summaries);
        $this->visit->patient->forceFill(['access_status' => 'restricted', 'version' => 2])->save();
        $this->getJson('/api/v1/medications/'.$authorization['id'])->assertNotFound();
        $this->issue($authorization)->assertNotFound();
    }

    public function test_database_rejects_rewriting_a_signed_medication_authorization(): void
    {
        $authorization = $this->authorizeLine()->assertCreated()->json('data');
        $this->expectException(QueryException::class);
        MedicationAuthorization::whereKey($authorization['id'])->update(['maximum_quantity_micros' => 100000000]);
    }

    public function test_medication_reads_are_paginated_and_do_not_expose_stock_costs(): void
    {
        $issued = $this->issue($this->authorizeLine()->assertCreated()->json('data'))->assertCreated()->json('data');
        $this->getJson('/api/v1/medications?per_page=1&encounter_id='.$this->visit->id)->assertOk()->assertJsonPath('total', 1);
        $response = $this->getJson('/api/v1/medications/'.$issued['id'].'/dispenses')->assertOk()->assertJsonPath('data.0.quantity', '4.000000');
        $this->assertStringNotContainsString('unit_cost', $response->getContent());
        $this->getJson('/api/v1/medications/'.$issued['id'].'/history')->assertOk()->assertJsonPath('total', 2);
        $this->getJson('/api/v1/medications?per_page=101')->assertUnprocessable();
    }

    public function test_expired_stock_cannot_be_issued_or_restored_as_usable(): void
    {
        $authorization = $this->authorizeLine()->assertCreated()->json('data');
        $this->travelTo($this->batch->expires_on->startOfDay()->setTimezone('Africa/Lagos')->addDay());
        $this->issue($authorization)->assertConflict()->assertJsonPath('code', 'medication_batch_unavailable');
        $this->assertDatabaseCount('medication_dispenses', 0);
    }

    public function test_expired_returns_remain_unavailable_but_are_retained_as_approved_returns(): void
    {
        $issued = $this->issue($this->authorizeLine()->assertCreated()->json('data'))->assertCreated()->json('data');
        $this->travelTo($this->batch->expires_on->startOfDay()->addDay());
        $data = ['dispense_id' => $issued['last_record_id'], 'quantity' => '2', 'disposition' => 'usable', 'usable_confirmed' => true];
        $this->command($issued, 'return-request', $data)->assertConflict();
        $data['disposition'] = 'expired';
        $data['usable_confirmed'] = false;
        $pending = $this->command($issued, 'return-request', $data)->assertCreated()->json('data');
        $this->command($pending, 'return-approve', ['dispense_id' => $issued['last_record_id'], 'return_id' => $pending['last_record_id']], $this->approver)->assertCreated();
        $this->assertDatabaseCount('stock_movements', 2);
        $this->assertDatabaseHas('medication_return_reviews', ['decision' => 'approved', 'movement_id' => null]);
    }

    public function test_cross_authorization_issue_ids_and_future_observations_are_rejected(): void
    {
        $issued = $this->issue($this->authorizeLine()->assertCreated()->json('data'))->assertCreated()->json('data');
        $this->command($issued, 'administer', ['dispense_id' => 999999, 'quantity' => '1', 'observed_at' => now('UTC')->toISOString()], $this->nurse)->assertNotFound();
        $this->command($issued, 'administer', ['dispense_id' => $issued['last_record_id'], 'quantity' => '1',
            'observed_at' => now('UTC')->addMinute()->toISOString()], $this->nurse)->assertUnprocessable();
        $this->assertDatabaseCount('medication_administrations', 0);
    }

    public function test_closed_visits_reject_issues_returns_and_administration(): void
    {
        $issued = $this->issue($this->authorizeLine()->assertCreated()->json('data'))->assertCreated()->json('data');
        $this->assignment->forceFill(['status' => 'cancelled', 'version' => 2, 'ended_at' => now('UTC'),
            'ended_by' => $this->doctor->id, 'end_reason' => 'Synthetic visit closure'])->save();
        $this->visit->forceFill(['status' => 'in_progress', 'version' => 2])->save();
        $this->visit->forceFill(['status' => 'closed', 'version' => 3, 'closed_at' => now('UTC')])->save();
        $this->command($issued, 'dispense', ['encounter_version' => 3, 'quantity' => '1', 'batch_id' => $this->batch->id,
            'store_id' => $this->mainStore->id, 'store_version' => 1, 'drug_version' => 1, 'balance_version' => 2])->assertConflict()->assertJsonPath('code', 'medication_visit_closed');
        $this->command($issued, 'return-request', ['encounter_version' => 3, 'dispense_id' => $issued['last_record_id'], 'quantity' => '1',
            'disposition' => 'damaged', 'usable_confirmed' => false])->assertConflict();
        $this->command($issued, 'administer', ['encounter_version' => 3, 'dispense_id' => $issued['last_record_id'], 'quantity' => '1',
            'observed_at' => now('UTC')->toISOString()], $this->nurse)->assertConflict();
        $this->assertDatabaseCount('stock_movements', 2);
    }

    public function test_a_prescription_correction_does_not_reset_an_existing_dispensing_allowance(): void
    {
        $issued = $this->issue($this->authorizeLine()->assertCreated()->json('data'))->assertCreated()->json('data');
        $this->stockLogin($this->doctor);
        $path = '/api/v1/encounters/'.$this->visit->id.'/prescriptions';
        $replacement = $this->postCommand($path.'/'.$this->item->prescription_id.'/amend', ['encounter_version' => 1,
            'version' => 2, 'payload' => ['instructions' => 'Synthetic corrected instructions'],
            'reason' => 'Synthetic corrected instructions', 'current_password' => self::STAFF_TEST_PASSWORD])->assertCreated()->json('data');
        if ($replacement['status'] === 'draft') {
            $replacement = $this->postCommand($path.'/'.$replacement['id'].'/sign', ['encounter_version' => 1,
                'version' => $replacement['version'], 'current_password' => self::STAFF_TEST_PASSWORD])->assertOk()->json('data');
        }
        $this->item = PrescriptionItem::where('prescription_id', $replacement['id'])->sole();
        $this->authorizeLine(['prescription_version' => $replacement['version']])->assertConflict()->assertJsonPath('code', 'prescription_already_fulfilled');
        $this->issue($issued, '1', 2)->assertConflict();
    }

    public function test_only_the_original_nurse_can_correct_an_administration(): void
    {
        $issued = $this->issue($this->authorizeLine()->assertCreated()->json('data'))->assertCreated()->json('data');
        $data = ['dispense_id' => $issued['last_record_id'], 'quantity' => '1', 'observed_at' => now('UTC')->toISOString()];
        $entry = $this->command($issued, 'administer', $data, $this->nurse)->assertCreated()->json('data');
        $data['administration_id'] = $entry['last_record_id'];
        $other = $this->createStaffWithRole('nurse');
        $this->command($entry, 'correct', $data, $other)->assertForbidden();
        $data['quantity'] = '0';
        $this->command($entry, 'correct', $data, $this->nurse)->assertCreated();
        $this->assertDatabaseCount('stock_movements', 2);
    }

    public function test_pending_returns_block_visit_completion_until_independently_reviewed(): void
    {
        $issued = $this->issue($this->authorizeLine()->assertCreated()->json('data'))->assertCreated()->json('data');
        $pending = $this->command($issued, 'return-request', ['dispense_id' => $issued['last_record_id'], 'quantity' => '1',
            'disposition' => 'damaged', 'usable_confirmed' => false])->assertCreated()->json('data');
        $this->grantRolePermissions('doctor', ['encounters.progress']);
        $this->stockLogin($this->doctor);
        $path = '/api/v1/encounters/'.$this->visit->id;
        $data = ['version' => 1, 'assignment_id' => $this->assignment->id, 'assignment_version' => 1, 'reason' => 'Synthetic visit completion'];
        $this->postCommand($path.'/accept', $data)->assertOk();
        $data['version'] = 2;
        $data['assignment_version'] = 2;
        $this->postCommand($path.'/complete', $data)->assertConflict()->assertJsonPath('code', 'encounter_pending_medication_returns');
        $this->command($pending, 'return-approve', ['encounter_version' => 2, 'dispense_id' => $issued['last_record_id'],
            'return_id' => $pending['last_record_id']], $this->approver)->assertCreated();
        $this->stockLogin($this->doctor);
        $this->postCommand($path.'/complete', $data)->assertOk();
        $this->assertSame('closed', $this->visit->fresh()->status);
    }

    public function test_private_storage_failures_are_sanitized_before_logging(): void
    {
        $authorization = $this->authorizeLine()->assertCreated()->json('data');
        \Illuminate\Support\Facades\Log::spy();
        $this->mock(AuditTrail::class)->shouldReceive('record')->andThrow(new QueryException('mysql', 'private medication binding sentinel', [], new \Exception('private medication value sentinel')));
        $response = $this->issue($authorization)->assertStatus(500);
        $this->assertStringNotContainsString('sentinel', $response->getContent());
        \Illuminate\Support\Facades\Log::shouldHaveReceived('error')->with('A medication workflow database operation failed.');
    }

    public function test_malformed_body_id_shapes_return_validation_errors_before_database_lookup(): void
    {
        $this->authorizeLine(['prescription_item_id' => []])->assertUnprocessable()->assertJsonValidationErrors('prescription_item_id');
        $authorization = $this->authorizeLine()->assertCreated()->json('data');
        $this->command($authorization, 'dispense', ['store_id' => []])->assertUnprocessable()->assertJsonValidationErrors('store_id');
        $this->command($authorization, 'administer', ['dispense_id' => ['unexpected' => 'value']], $this->nurse)
            ->assertUnprocessable()->assertJsonValidationErrors('dispense_id');
        $this->assertDatabaseCount('medication_dispenses', 0);
    }
}
