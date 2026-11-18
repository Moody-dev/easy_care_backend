<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\HmoTestCase;

class HmoApiTest extends HmoTestCase
{
    use RefreshDatabase;

    public function test_one_outside_remittance_can_settle_separate_patients_without_sharing_their_claim_balances(): void
    {
        $firstClaim=$this->approvedClaim();
        $firstPatient=$this->visit->patient_id;
        $receipt=$this->remittance();
        $this->visit=\App\Models\Encounter::factory()->create(['opened_at'=>now('UTC')->subHour()]);
        $secondClaim=$this->approvedClaim(false);
        $first=$this->command('/patients/'.$firstPatient.'/allocations',['account_version'=>$firstClaim['version']]+$this->allocationFields($firstClaim,$receipt))
            ->assertCreated()->json('data');
        $receipt['version']=3;
        $fields=$this->allocationFields($secondClaim,$receipt,'30.00');
        $fields['reference']='Synthetic second patient allocation';
        $this->patientCommand($secondClaim['version'],'/allocations',$fields)->assertConflict()->assertJsonPath('code','hmo_receipt_exceeded');
        $fields['lines'][0]['amount']='20.00';
        $second=$this->patientCommand($secondClaim['version'],'/allocations',$fields)->assertCreated()->json('data');
        $this->command('/patients/'.$firstPatient.'/allocations/'.$first['last_record_id'].'/review',
            ['account_version'=>$first['version'],'remittance_version'=>4]+$this->reviewFields(),$this->reviewer)->assertCreated()
            ->assertJsonPath('data.totals.insurer_settled','80.00')->assertJsonPath('data.totals.outstanding','30.00');
        $this->patientCommand($second['version'],'/allocations/'.$second['last_record_id'].'/review',
            ['remittance_version'=>5]+$this->reviewFields(),$this->reviewer)->assertCreated()->assertJsonPath('data.totals.insurer_settled','20.00')
            ->assertJsonPath('data.totals.outstanding','30.00');
        $this->getJson('/api/v1/hmo/remittances/'.$receipt['id'])->assertOk()->assertJsonPath('data.available','0.00')->assertJsonPath('data.allocated','100.00');
    }

    public function test_reconciliation_reports_an_incomplete_imported_allocation_instead_of_trusting_its_header(): void
    {
        $claim=$this->approvedClaim();
        $receipt=$this->remittance();
        $allocation=DB::table('hmo_allocations')->insertGetId(['account_id'=>$claim['id'],'claim_id'=>$claim['claim_id'],'remittance_id'=>$receipt['id'],
            'amount_kobo'=>10000,'reference'=>'Synthetic incomplete import','reference_key'=>hash('sha256','Synthetic incomplete import'),
            'actor_id'=>$this->cashier->id,'recorded_at'=>now('UTC')->format('Y-m-d H:i:s.u'),'reason'=>'Synthetic malformed imported allocation']);
        $this->getJson('/api/v1/hmo/patients/'.$this->visit->patient_id.'/reconciliation')->assertOk()
            ->assertJsonPath('data.balanced',false)->assertJsonPath('data.issues.0.kind','allocation_lines');
        $this->patientCommand($claim['version'],'/allocations/'.$allocation.'/review',$this->reviewFields()+['remittance_version'=>3],$this->reviewer)
            ->assertConflict()->assertJsonPath('code','hmo_allocation_integrity');
    }

    public function test_incomplete_imported_payer_response_cannot_replace_a_complete_reviewed_approval(): void
    {
        $claim=$this->approvedClaim();
        $decision=DB::table('hmo_decisions')->insertGetId(['claim_id'=>$claim['claim_id'],'submission_id'=>$claim['submission_id'],
            'reference'=>'Synthetic incomplete response','occurred_at'=>now('UTC')->format('Y-m-d H:i:s.u'),
            'actor_id'=>$this->cashier->id,'recorded_at'=>now('UTC')->format('Y-m-d H:i:s.u'),'reason'=>'Synthetic incomplete import']);
        $this->patientCommand($claim['version'],'/decisions/'.$decision.'/review',$this->reviewFields(),$this->reviewer)
            ->assertConflict()->assertJsonPath('code','hmo_decision_integrity');
        $this->patientCommand($claim['version'],'/decisions/'.$decision.'/review',$this->reviewFields('rejected'),$this->reviewer)
            ->assertCreated()->assertJsonPath('data.totals.insurer_approved','100.00');
    }

    public function test_hmo_database_failures_do_not_log_private_membership_or_reference_values(): void
    {
        \Illuminate\Support\Facades\Log::spy();
        $this->mock(\App\Services\AuditTrail::class)->shouldReceive('record')->andThrow(new \Illuminate\Database\QueryException(
            'mysql','private membership sentinel',[],new \Exception('private insurer reference sentinel')));
        $response=$this->command('/providers',['code'=>'synthetic-provider','name'=>'Synthetic provider','is_active'=>true,'preauth_required'=>false])->assertStatus(500);
        $this->assertStringNotContainsString('sentinel',$response->getContent());
        \Illuminate\Support\Facades\Log::shouldHaveReceived('error')->with('An HMO database operation failed.');
        $this->assertDatabaseCount('hmo_providers',0);
    }

    public function test_missing_subject_in_a_new_full_bundle_cannot_fall_back_to_an_old_tariff(): void
    {
        $this->setupCatalog();
        $coverage=$this->coverage();
        $other=\App\Models\Service::factory()->create(['category'=>'consultation']);
        $csv="item_type,item_code,currency,unit_price,preauth_required\nservice,".$other->code.",NGN,80,0\n";
        $import=$this->command('/imports',$this->importFields($csv,1))->assertCreated()->json('data.id');
        $this->command('/imports/'.$import.'/review',$this->reviewFields(),$this->reviewer)->assertCreated();
        $source=$this->performed($coverage['version']);
        $this->patientCommand($source['version'],'/invoices',$this->splitFields($source))->assertConflict()->assertJsonPath('code','hmo_tariff_missing');
        $this->assertDatabaseCount('billing_invoices',0);
    }

    public function test_unpublished_csv_is_hidden_from_catalog_only_staff_and_read_access_does_not_allow_download(): void
    {
        $this->setupCatalog();
        $pending=$this->command('/imports',$this->importFields($this->csv(),1))->assertCreated()->json('data.id');
        $this->grantRolePermissions('hmo',['hmo.catalog.view']);
        $viewer=$this->createStaffWithRole('hmo');
        $this->loginCashier($viewer);
        $this->getJson('/api/v1/hmo/imports/'.$pending)->assertNotFound();
        $this->getJson('/api/v1/hmo/imports/'.$pending.'/rows')->assertNotFound();
        $this->getJson('/api/v1/hmo/imports/'.$pending.'/source')->assertNotFound();
        $this->getJson('/api/v1/hmo/imports')->assertOk()->assertJsonPath('total',1);
        $this->getJson('/api/v1/hmo/imports/'.$this->importId.'/rows')->assertOk();
        $this->getJson('/api/v1/hmo/imports/'.$this->importId.'/source')->assertNotFound();
    }

    public function test_pending_allocations_reserve_both_remittance_and_approved_claim_credit(): void
    {
        $claim = $this->approvedClaim();
        $receipt = $this->remittance('90.00');
        $pending = $this->patientCommand($claim['version'], '/allocations', $this->allocationFields($claim, $receipt))->assertCreated()->json('data');
        $this->getJson('/api/v1/hmo/remittances/'.$receipt['id'])->assertOk()->assertJsonPath('data.available', '10.00');
        $receipt['version'] = 3;
        $fields = $this->allocationFields($claim, $receipt, '21.00');
        $fields['reference'] = 'Synthetic allocation 2';
        $this->patientCommand($pending['version'], '/allocations', $fields)->assertConflict()->assertJsonPath('code', 'hmo_allocation_exceeded');
        $fields['lines'][0]['amount'] = '15.00';
        $this->patientCommand($pending['version'], '/allocations', $fields)->assertConflict()->assertJsonPath('code', 'hmo_receipt_exceeded');
        $rejected = $this->patientCommand($pending['version'], '/allocations/'.$pending['last_record_id'].'/review',
            $this->reviewFields('rejected') + ['remittance_version' => 3], $this->reviewer)->assertCreated()->assertJsonPath('data.totals.insurer_reserved', '0.00')->json('data');
        $receipt['version'] = 4;
        $this->patientCommand($rejected['version'], '/allocations', $this->allocationFields($claim, $receipt, '90.00'))->assertCreated();
        $this->assertDatabaseCount('hmo_allocations', 2);
    }

    public function test_settled_claim_cannot_be_reduced_below_retained_payment(): void
    {
        $claim = $this->approvedClaim();
        $receipt = $this->remittance();
        $pending = $this->patientCommand($claim['version'], '/allocations', $this->allocationFields($claim, $receipt))->assertCreated()->json('data');
        $posted = $this->patientCommand($pending['version'], '/allocations/'.$pending['last_record_id'].'/review',
            $this->reviewFields() + ['remittance_version' => 3], $this->reviewer)->assertCreated()->json('data');
        $response = $this->patientCommand($posted['version'], '/decisions', $this->decision($claim, '50.00'))->assertCreated()->json('data');
        $this->patientCommand($response['version'], '/decisions/'.$response['last_record_id'].'/review', $this->reviewFields(), $this->reviewer)
            ->assertConflict()->assertJsonPath('code', 'hmo_decision_below_settlement');
        $this->patientCommand($response['version'], '/decisions/'.$response['last_record_id'].'/review', $this->reviewFields('rejected'), $this->reviewer)
            ->assertCreated()->assertJsonPath('data.totals.insurer_approved', '100.00')->assertJsonPath('data.totals.insurer_settled', '80.00');
    }

    public function test_accepted_remittance_reference_cannot_be_recorded_again_and_review_is_immutable(): void
    {
        $this->setupCatalog();
        $receipt = $this->remittance();
        $this->command('/remittances', ['provider_id' => $this->providerId, 'amount' => '100.00', 'reference' => 'synthetic REMITTANCE 1',
            'method' => 'cash', 'payer_name' => 'Synthetic payer', 'received_at' => now('UTC')->toISOString(),
            'externally_confirmed' => true, 'transaction_details' => ['receipt_number' => 'Synthetic receipt']])->assertConflict();
        $this->command('/remittances/'.$receipt['id'].'/review', $this->reviewFields(), $this->reviewer)->assertConflict();
        $this->assertDatabaseCount('hmo_remittances', 1);
    }

    public function test_failed_audit_rolls_back_all_hmo_money_records(): void
    {
        $this->setupCatalog();
        $coverage = $this->coverage();
        $source = $this->performed($coverage['version']);
        $this->mock(\App\Services\AuditTrail::class, function (\Mockery\MockInterface $mock): void {
            $mock->shouldReceive('record')->withArgs(fn (string $action, mixed ...$rest): bool => $action === 'hmo.invoice')
                ->once()->andThrow(new \RuntimeException('Synthetic audit failure'));
            $mock->shouldReceive('record')->andReturnNull();
        });
        $this->patientCommand($source['version'], '/invoices', $this->splitFields($source))->assertStatus(500);
        $this->assertDatabaseCount('billing_invoices', 0);
        $this->assertDatabaseCount('hmo_invoice_links', 0);
        $this->assertDatabaseCount('hmo_invoice_shares', 0);
        $this->assertDatabaseCount('billing_events', 2);
    }

    public function test_csv_validation_is_atomic_and_retains_encrypted_original_source(): void
    {
        $this->setupCatalog();
        $source = DB::table('hmo_tariff_imports')->find($this->importId);
        $this->assertNotSame($this->csv(), $source->csv_ciphertext);
        $this->assertSame($this->csv(), \Illuminate\Support\Facades\Crypt::decryptString($source->csv_ciphertext));
        $this->assertSame(hash('sha256', $this->csv()), $source->sha256);
        foreach ([$this->csv()."service,missing,NGN,1,0\n", $this->csv().explode("\n", $this->csv())[1]."\n",
            str_replace('NGN', 'USD', $this->csv()), str_replace('75.000000', '75.0000001', $this->csv()), "bad,header\n"] as $bad) {
            $this->command('/imports', $this->importFields($bad, 1))->assertUnprocessable();
        }
        $this->assertDatabaseCount('hmo_tariff_imports', 1);
        $this->assertDatabaseCount('hmo_tariffs', 1);
        $this->getJson('/api/v1/hmo/imports/'.$this->importId)->assertOk()->assertJsonMissingPath('data.csv_ciphertext');
        $this->get('/api/v1/hmo/imports/'.$this->importId.'/source')->assertOk()->assertContent($this->csv());
    }

    public function test_publication_requires_different_reviewer_and_current_bundle_version(): void
    {
        $this->setupCatalog();
        $first = $this->command('/imports', $this->importFields($this->csv(), 1))->assertCreated()->json('data.id');
        $second = $this->command('/imports', $this->importFields($this->csv(), 1))->assertCreated()->json('data.id');
        $this->command('/imports/'.$first.'/review', $this->reviewFields())->assertForbidden();
        $this->command('/imports/'.$first.'/review', $this->reviewFields(), $this->reviewer)->assertCreated()->assertJsonPath('data.review.published_version', 2);
        $this->command('/imports/'.$second.'/review', $this->reviewFields(), $this->reviewer)->assertConflict();
        $this->command('/imports/'.$second.'/review', $this->reviewFields('rejected'), $this->reviewer)->assertCreated();
        $this->assertDatabaseCount('hmo_reviews', 3);
    }

    public function test_coverage_verification_overlap_revocation_and_patient_isolation(): void
    {
        $this->setupCatalog();
        $coverage = $this->coverage();
        $fields = ['plan_id' => $this->planId, 'member_number' => 'Synthetic member 2', 'valid_from' => now()->toDateString(),
            'valid_until' => now()->addMonth()->toDateString(), 'verified' => true, 'verification_reference' => 'Synthetic verified membership'];
        $this->patientCommand($coverage['version'], '/coverages', $fields)->assertConflict();
        $revoked = $this->patientCommand($coverage['version'], '/coverages/'.$this->coverageId.'/revoke')->assertCreated()->json('data');
        $this->patientCommand($revoked['version'], '/coverages', $fields)->assertCreated();
        $other = \App\Models\Patient::factory()->create();
        $this->command('/patients/'.$other->id.'/coverages/'.$this->coverageId.'/revoke', ['account_version' => 0])->assertNotFound();
        $this->assertDatabaseCount('hmo_revocations', 1);
    }

    public function test_invalid_split_rolls_back_and_retry_cannot_duplicate_a_source(): void
    {
        $this->setupCatalog();
        $coverage = $this->coverage();
        $source = $this->performed($coverage['version']);
        $fields = $this->splitFields($source);
        $fields['sources'][0]['patient_amount'] = '30.01';
        $this->patientCommand($source['version'], '/invoices', $fields)->assertUnprocessable();
        $this->assertDatabaseCount('billing_invoices', 0);
        $key = ['Idempotency-Key' => 'synthetic-hmo-invoice-001'];
        $fields = $this->splitFields($source);
        $invoice = $this->patientCommand($source['version'], '/invoices', $fields, headers: $key)->assertCreated()->json('data');
        $this->patientCommand($source['version'], '/invoices', $fields, headers: $key)->assertOk()->assertHeader('Idempotency-Replayed', 'true');
        $this->patientCommand($invoice['version'], '/invoices', $fields)->assertConflict();
        $source['version'] = $invoice['version'];
        $this->invoice($source)->assertConflict();
        $this->assertDatabaseCount('billing_invoices', 1);
    }

    public function test_preauthorization_is_required_and_its_cumulative_allowance_cannot_be_exceeded(): void
    {
        $this->setupCatalog(true);
        $coverage = $this->coverage();
        $source = $this->performed($coverage['version']);
        $fields = $this->splitFields($source);
        $this->patientCommand($source['version'], '/invoices', $fields)->assertConflict();
        $preauth = $this->patientCommand($source['version'], '/preauthorizations', ['coverage_id' => $this->coverageId,
            'encounter_id' => $this->visit->id, 'encounter_version' => 1, 'subject_type' => 'service', 'subject_id' => $this->price->service_id,
            'status' => 'approved', 'limit' => '150.00', 'reference' => 'SYN-PREAUTH-1', 'approved_at' => now('UTC')->toISOString(),
            'valid_from' => now('UTC')->subDay()->toISOString(), 'valid_until' => now('UTC')->addDay()->toISOString(), 'externally_verified' => true])->assertCreated()->json('data');
        $fields['sources'][0]['preauth_id'] = $preauth['last_record_id'];
        $this->getJson('/api/v1/hmo/patients/'.$this->visit->patient_id.'/preauthorizations')->assertOk()
            ->assertJsonPath('data.0.valid_from',now('UTC')->subDay()->toISOString())->assertJsonPath('data.0.valid_until',now('UTC')->addDay()->toISOString());
        $invoice = $this->patientCommand($preauth['version'], '/invoices', $fields)->assertCreated()->json('data');
        $second = $this->performed($invoice['version'], 'Synthetic second source');
        $fields['sources'][0]['id'] = $second['last_record_id'];
        $this->patientCommand($second['version'], '/invoices', $fields)->assertConflict()->assertJsonPath('code', 'hmo_preauth_exceeded');
        $this->assertDatabaseCount('billing_invoices', 1);
    }

    public function test_revoked_coverage_or_inactive_plan_cannot_create_a_new_charge(): void
    {
        $this->setupCatalog();
        $coverage = $this->coverage();
        $source = $this->performed($coverage['version']);
        $this->command('/plans/'.$this->planId.'/revisions', ['version' => 1, 'is_active' => false, 'preauth_required' => false])->assertCreated();
        $this->patientCommand($source['version'], '/invoices', $this->splitFields($source))->assertConflict();
        $this->command('/plans/'.$this->planId.'/revisions', ['version' => 2, 'is_active' => true, 'preauth_required' => false])->assertCreated();
        $revoked = $this->patientCommand($source['version'], '/coverages/'.$this->coverageId.'/revoke')->assertCreated()->json('data');
        $this->patientCommand($revoked['version'], '/invoices', $this->splitFields($source))->assertConflict();
        $this->assertDatabaseCount('billing_invoices', 0);
    }

    public function test_rejected_payer_decision_remains_disputed_and_can_be_resubmitted_with_history(): void
    {
        $claim = $this->submittedClaim();
        $decision = $this->patientCommand($claim['version'], '/decisions', $this->decision($claim, '0.00'))->assertCreated()->json('data');
        $review = $this->patientCommand($decision['version'], '/decisions/'.$decision['last_record_id'].'/review', $this->reviewFields(), $this->reviewer)
            ->assertCreated()->assertJsonPath('data.totals.insurer_disputed', '120.00')->assertJsonPath('data.totals.outstanding', '30.00')->json('data');
        $next = $this->patientCommand($review['version'], '/claims/'.$claim['claim_id'].'/resubmit', ['reference' => 'Synthetic resubmission 2',
            'submitted_at' => now('UTC')->toISOString(), 'externally_submitted' => true])->assertCreated()->json('data');
        $this->patientCommand($next['version'], '/claims/'.$claim['claim_id'].'/resubmit', ['reference' => 'Synthetic resubmission 3',
            'submitted_at' => now('UTC')->toISOString(), 'externally_submitted' => true])->assertConflict();
        $this->assertDatabaseCount('hmo_claims', 1);
        $this->assertDatabaseCount('hmo_submissions', 2);
        $this->getJson('/api/v1/hmo/patients/'.$this->visit->patient_id.'/claims/'.$claim['claim_id'])->assertOk()->assertJsonPath('data.submission.sequence', 2);
    }

    public function test_payer_response_must_cover_exact_invoice_items_and_cannot_exceed_insurer_share(): void
    {
        $claim = $this->submittedClaim();
        $fields = $this->decision($claim, '120.01');
        $this->patientCommand($claim['version'], '/decisions', $fields)->assertUnprocessable();
        $fields['lines'][0]['item_id'] = 999999;
        $this->patientCommand($claim['version'], '/decisions', $fields)->assertUnprocessable();
        $this->assertDatabaseCount('hmo_decisions', 0);
        $other = \App\Models\Patient::factory()->create();
        $this->command('/patients/'.$other->id.'/decisions', ['account_version' => 0] + $this->decision($claim))->assertNotFound();
    }

    public function test_patient_payments_only_allocate_to_patient_share_and_generic_credit_cannot_alter_hmo_splits(): void
    {
        $invoice = $this->hmoInvoice();
        $receipt = $this->receipt($invoice['version'], '200.00')->assertCreated()->json('data');
        $this->writeBill($receipt['version'], '/allocations', ['receipt_id' => $receipt['last_record_id'], 'invoice_id' => $invoice['last_record_id'], 'amount' => '31.00'])->assertConflict();
        $paid = $this->writeBill($receipt['version'], '/allocations', ['receipt_id' => $receipt['last_record_id'], 'invoice_id' => $invoice['last_record_id'], 'amount' => '30.00'])
            ->assertCreated()->assertJsonPath('data.totals.outstanding', '0.00')->assertJsonPath('data.totals.available_credit', '170.00')->json('data');
        $this->writeBill($paid['version'], '/credits', ['item_id' => DB::table('billing_invoice_items')->value('id'), 'amount' => '1.00'])
            ->assertConflict()->assertJsonPath('code', 'hmo_payer_correction_required');
    }

    public function test_patient_visibility_and_permissions_are_rechecked_even_on_retry(): void
    {
        $this->setupCatalog();
        $coverage = $this->coverage();
        $source = $this->performed($coverage['version']);
        $key = ['Idempotency-Key' => 'synthetic-hmo-replay-auth'];
        $fields = $this->splitFields($source);
        $this->patientCommand($source['version'], '/invoices', $fields, headers: $key)->assertCreated();
        $permission = \App\Models\Permission::where('code', 'hmo.invoices.post')->sole();
        \App\Models\Role::where('code', 'frontdesk')->sole()->permissions()->detach($permission->id);
        $this->patientCommand($source['version'], '/invoices', $fields, headers: $key)->assertForbidden();
        $this->visit->patient->forceFill(['access_status' => 'restricted', 'version' => 2])->save();
        $this->getJson('/api/v1/hmo/patients/'.$this->visit->patient_id.'/coverages')->assertNotFound();
    }

    public function test_no_hmo_permissions_or_operational_records_are_seeded_by_default(): void
    {
        $this->assertDatabaseCount('hmo_providers', 0);
        $this->assertDatabaseCount('hmo_coverages', 0);
        $this->assertDatabaseCount('hmo_tariff_imports', 0);
        $this->assertDatabaseCount('permissions', 118);
        $other = $this->createStaffWithRole('hmo');
        $this->loginCashier($other);
        $this->getJson('/api/v1/hmo/providers')->assertForbidden();
        $this->getJson('/api/v1/hmo/options')->assertForbidden();
    }

    public function test_frozen_payer_split_uses_published_tariff_and_separates_patient_debt(): void
    {
        $invoice = $this->hmoInvoice();
        $this->assertSame('150.00', $invoice['totals']['charges']);
        $this->assertSame('30.00', $invoice['totals']['outstanding']);
        $this->assertSame('120.00', $invoice['totals']['insurer_liability']);
        $this->assertSame('120.00', $invoice['totals']['insurer_unreviewed']);
        $this->assertSame('0.00', $invoice['totals']['insurer_disputed']);
        $this->assertDatabaseCount('hmo_invoice_shares', 1);
        $this->assertSame($this->tariffId, DB::table('billing_invoice_items')->value('tariff_id'));
        $this->getJson($this->path('/reconciliation'))->assertOk()->assertJsonPath('data.balanced', true);
    }

    public function test_external_claim_decision_and_remittance_require_independent_review(): void
    {
        $claim = $this->submittedClaim();
        $decision = $this->patientCommand($claim['version'], '/decisions', $this->decision($claim))->assertCreated()->json('data');
        $this->patientCommand($decision['version'], '/decisions/'.$decision['last_record_id'].'/review', $this->reviewFields())->assertForbidden();
        $reviewed = $this->patientCommand($decision['version'], '/decisions/'.$decision['last_record_id'].'/review', $this->reviewFields(), $this->reviewer)
            ->assertCreated()->assertJsonPath('data.totals.insurer_approved', '100.00')
            ->assertJsonPath('data.totals.insurer_disputed', '20.00')->assertJsonPath('data.totals.outstanding', '30.00')->json('data');
        $receipt = $this->command('/remittances', ['provider_id' => $this->providerId, 'amount' => '110.00', 'reference' => 'Synthetic-remittance-1',
            'method' => 'bank_transfer', 'payer_name' => 'Synthetic insurer', 'received_at' => now('UTC')->toISOString(),
            'externally_confirmed' => true, 'transaction_details' => ['provider' => 'Synthetic external bank']])->assertCreated()->json('data');
        $this->command('/remittances/'.$receipt['id'].'/review', $this->reviewFields())->assertForbidden();
        $receipt = $this->command('/remittances/'.$receipt['id'].'/review', $this->reviewFields(), $this->reviewer)->assertCreated()->json('data');
        $fields = ['claim_id' => $claim['claim_id'], 'remittance_id' => $receipt['id'], 'remittance_version' => $receipt['version'],
            'reference' => 'Synthetic-remittance-line-1', 'lines' => [['item_id' => $claim['item_id'], 'amount' => '80.00']]];
        $pending = $this->patientCommand($reviewed['version'], '/allocations', $fields)->assertCreated()->assertJsonPath('data.totals.insurer_reserved', '80.00')->json('data');
        $this->patientCommand($pending['version'], '/allocations/'.$pending['last_record_id'].'/review', $this->reviewFields()+['remittance_version' => 3])->assertForbidden();
        $this->patientCommand($pending['version'], '/allocations/'.$pending['last_record_id'].'/review', $this->reviewFields()+['remittance_version' => 3], $this->reviewer)
            ->assertCreated()->assertJsonPath('data.totals.insurer_settled', '80.00')->assertJsonPath('data.totals.insurer_reserved', '0.00');
        $this->getJson('/api/v1/hmo/remittances/'.$receipt['id'])->assertOk()->assertJsonPath('data.available', '30.00');
        $this->getJson('/api/v1/hmo/patients/'.$this->visit->patient_id.'/reconciliation')->assertOk()->assertJsonPath('data.balanced', true);
        $this->assertDatabaseCount('billing_receipts', 0);
    }
}
