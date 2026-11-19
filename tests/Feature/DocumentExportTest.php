<?php

namespace Tests\Feature;

use App\Models\DocumentExport;
use App\Models\Patient;
use App\Models\Permission;
use App\Models\Role;
use App\Services\ExportRendering;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\BillingTestCase;

class DocumentExportTest extends BillingTestCase
{
    use RefreshDatabase, \Tests\RejectsOutboundRequests;

    protected function setUp(): void
    {
        parent::setUp();
        $this->grantRolePermissions('frontdesk',['exports.generate','exports.download','reports.activity','reports.billing']);
        $this->loginCashier();
    }

    private function report(array $extra=[]): array
    {
        return array_replace(['kind'=>'report','format'=>'csv','report_key'=>'activity','filters'=>[]],$extra);
    }

    private function generate(array $data=[]): \Illuminate\Testing\TestResponse
    {
        return $this->postCommand('/api/v1/exports',$data ?: $this->report());
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('reportKinds')]
    public function test_every_supported_report_kind_can_be_generated_and_reauthorized(string $kind): void
    {
        $this->grantRolePermissions('frontdesk',array_keys(config('authorization.permissions')));
        $filters=$kind==='trend'?['module'=>'encounters']:[];
        $id=$this->generate($this->report(['report_key'=>$kind,'filters'=>$filters]))->assertCreated()->json('data.id');
        $csv=$this->get('/api/v1/exports/'.$id.'/download')->assertOk()->getContent();
        $this->assertStringContainsString('report_key,'.$kind,$csv);
    }

    public static function reportKinds(): array
    {
        return array_map(fn (string $kind): array=>[$kind],\App\Services\ExportReports::KINDS);
    }

    public function test_private_encrypted_report_can_be_read_downloaded_and_replayed_without_another_copy(): void
    {
        $key=['Idempotency-Key'=>fake()->uuid()]; $input=$this->report();
        $id=$this->postCommand('/api/v1/exports',$input,$key)->assertCreated()->assertJsonMissingPath('data.bundle')->json('data.id');
        $this->postCommand('/api/v1/exports',$input,$key)->assertOk()->assertHeader('Idempotency-Replayed','true')->assertJsonPath('data.id',$id);
        $this->assertDatabaseCount('document_exports',1);
        $this->getJson('/api/v1/exports/'.$id)->assertOk();
        $download=$this->get('/api/v1/exports/'.$id.'/download')->assertOk()->assertHeader('Content-Type','text/csv; charset=UTF-8');
        $this->assertStringContainsString('data.registry.count',$download->getContent());
        $this->assertStringNotContainsString('data.registry.count',DB::table('document_exports')->value('bundle'));
        $this->assertStringContainsString('no-store',$download->headers->get('Cache-Control'));
        $this->getJson('/api/v1/exports/'.$id.'/print')->assertUnprocessable();
        $this->assertDatabaseHas('audit_events',['action'=>'export.downloaded','subject_id'=>$id]);
    }

    public function test_owner_check_and_explicit_permission_apply_even_to_administrators(): void
    {
        $id=$this->generate()->assertCreated()->json('data.id');
        $this->loginCashier($this->reviewer);
        $this->getJson('/api/v1/exports/'.$id.'/download')->assertNotFound();
        $this->flushSession(); $this->actingAs($this->createStaffWithRole('admin'));
        $this->generate($this->report(['report_key'=>'billing']))->assertForbidden();
        $this->getJson('/api/v1/exports/'.$id.'/download')->assertForbidden();
    }

    public function test_generation_and_download_permissions_do_not_grant_source_access(): void
    {
        Role::where('code','frontdesk')->sole()->permissions()->detach(Permission::where('code','patients.view')->value('id'));
        $this->generate($this->report(['report_key'=>'billing']))->assertForbidden();
        $this->assertDatabaseCount('document_exports',0);
    }

    public function test_expiration_at_exactly_twenty_four_hours_blocks_even_metadata(): void
    {
        $id=$this->generate()->assertCreated()->json('data.id');
        $this->travel(24)->hours();
        $this->getJson('/api/v1/exports/'.$id)->assertConflict()->assertJsonPath('code','export_expired');
    }

    public function test_source_version_change_invalidates_a_report_even_when_aggregate_counts_do_not_change(): void
    {
        $id=$this->generate()->assertCreated()->json('data.id');
        $this->visit->patient->forceFill(['first_name'=>'Changed identity','version'=>2])->save();
        $this->getJson('/api/v1/exports/'.$id.'/download')->assertConflict()->assertJsonPath('code','export_outdated');
    }

    public function test_hidden_patient_changes_do_not_invalidate_a_report_or_appear_in_its_bytes(): void
    {
        $hidden=Patient::factory()->create(['access_status'=>'restricted','first_name'=>'Hidden export sentinel']);
        $id=$this->generate()->assertCreated()->json('data.id');
        $hidden->forceFill(['first_name'=>'Changed hidden identity','version'=>2])->save();
        $response=$this->get('/api/v1/exports/'.$id.'/download')->assertOk();
        $this->assertStringNotContainsString('Hidden export sentinel',$response->getContent());
    }

    public function test_role_or_scope_changes_require_regeneration_and_revocation_blocks_download(): void
    {
        $id=$this->generate()->assertCreated()->json('data.id');
        $this->cashier->forceFill(['version'=>2])->save();
        $this->getJson('/api/v1/exports/'.$id.'/download')->assertConflict();
        Role::where('code','frontdesk')->sole()->permissions()->detach(Permission::where('code','exports.download')->value('id'));
        $this->getJson('/api/v1/exports/'.$id.'/download')->assertForbidden();
    }

    public function test_unknown_filters_pagination_injection_formats_and_bad_sources_are_rejected(): void
    {
        foreach ([$this->report(['filters'=>['page'=>2]]),$this->report(['format'=>'pdf']),$this->report(['creator_id'=>$this->reviewer->id]),
            $this->report(['filters'=>['from_date'=>'2020-01-01','to_date'=>'2026-01-01']]),
            ['kind'=>'invoice','source_id'=>0,'format'=>'html'],['kind'=>'clinical','source_id'=>1,'format'=>'csv']] as $input) {
            $this->generate($input)->assertUnprocessable();
        }
        $this->assertDatabaseCount('document_exports',0);
    }

    public function test_report_exports_include_every_page_and_preserve_exact_money(): void
    {
        \App\Models\BillingAccount::factory()->count(100)->create(['actor_id'=>$this->cashier->id]);
        $this->receipt(0,'90071992547.41')->assertCreated();
        $this->loginCashier();
        $id=$this->generate($this->report(['report_key'=>'accounts']))->assertCreated()->json('data.id');
        $text=$this->get('/api/v1/exports/'.$id.'/download')->assertOk()->getContent();
        $this->assertStringContainsString('90071992547.41',$text);
        $this->assertStringContainsString('data.total,101',$text);
        $this->assertStringContainsString('data.records.100.account_id',$text);
    }

    public function test_large_exports_fail_without_saving_partial_documents(): void
    {
        $this->mock(\App\Services\OperationalReports::class)->shouldReceive('summary')->once()
            ->andReturn(['records'=>array_fill(0,ExportRendering::MAX_ROWS+1,'Synthetic oversized report')])
            ->shouldReceive('modules')->andReturn([]);
        $this->generate()->assertUnprocessable();
        $this->assertDatabaseCount('document_exports',0);
        $this->assertDatabaseCount('idempotency_requests',0);
    }

    public function test_database_retains_generated_documents_and_refuses_destructive_rollback(): void
    {
        $id=$this->generate()->assertCreated()->json('data.id');
        foreach (['update','delete'] as $operation) {
            try {
                DB::transaction(fn ()=>$operation==='update'?DB::table('document_exports')->where('id',$id)->update(['byte_size'=>1])
                    :DB::table('document_exports')->where('id',$id)->delete());
                $this->fail('Generated documents must remain immutable.');
            } catch (\Illuminate\Database\QueryException) { $this->assertDatabaseHas('document_exports',['id'=>$id]); }
        }
        $migration=require database_path('migrations/2026_11_18_230113_create_document_exports_table.php');
        $this->expectException(\RuntimeException::class); $migration->down();
    }

    public function test_export_database_errors_are_logged_without_document_content_or_filters(): void
    {
        \Illuminate\Support\Facades\Log::spy();
        $this->mock(\App\Services\ExportSources::class)->shouldReceive('snapshot')->andThrow(new \Illuminate\Database\QueryException(
            'mysql','private export SQL sentinel',[],new \Exception('private export source sentinel')));
        $response=$this->generate()->assertStatus(500);
        $this->assertStringNotContainsString('sentinel',$response->getContent());
        \Illuminate\Support\Facades\Log::shouldHaveReceived('error')->with('A document export database operation failed.');
    }

    public function test_integrity_verification_blocks_corrupted_content_and_a_wrong_declared_format(): void
    {
        $id=$this->generate()->assertCreated()->json('data.id');
        $original=DocumentExport::findOrFail($id);
        $corrupt=$original->replicate(); $corrupt->created_at=$original->created_at; $bundle=$corrupt->bundle;
        $bundle['content']='Corrupted generated document'; $corrupt->bundle=$bundle; $corrupt->save();
        $this->getJson('/api/v1/exports/'.$corrupt->id.'/download')->assertConflict()->assertJsonPath('code','export_integrity_failed');
        $wrong=$original->replicate(); $wrong->created_at=$original->created_at; $wrong->format='html'; $wrong->save();
        $this->getJson('/api/v1/exports/'.$wrong->id.'/print')->assertConflict()->assertJsonPath('code','export_integrity_failed');
    }

    public function test_invoice_and_external_payment_receipt_print_saved_details_and_become_stale_after_a_payment(): void
    {
        $invoice=$this->invoice($this->performed())->assertCreated()->json('data');
        $receipt=$this->receipt($invoice['version'],'12.34',['method'=>'pos','externally_confirmed'=>true,
            'reference'=>'Synthetic-POS-export','transaction_details'=>['receipt_number'=>'POS-copy','terminal_id'=>'Terminal-1','provider'=>'Synthetic provider']])->assertCreated()->json('data');
        $this->loginCashier();
        foreach (['invoice'=>$invoice['last_record_id'],'receipt'=>$receipt['last_record_id']] as $kind=>$source) {
            $id=$this->generate(['kind'=>$kind,'source_id'=>$source,'format'=>'html'])->assertCreated()->json('data.id');
            $response=$this->get('/api/v1/exports/'.$id.'/print')->assertOk()->assertHeader('Content-Type','text/html; charset=UTF-8');
            $this->assertStringContainsString('Saved record copy',$response->getContent());
            $this->assertStringContainsString('sandbox',$response->headers->get('Content-Security-Policy'));
            if ($kind==='receipt') { $this->assertStringContainsString('Synthetic-POS-export',$response->getContent()); }
        }
        $this->receipt($receipt['version'],'1.00')->assertCreated(); $this->loginCashier();
        $this->getJson('/api/v1/exports/'.$id.'/download')->assertConflict();
    }

    public function test_restricted_patient_financial_documents_are_hidden_after_generation(): void
    {
        $receipt=$this->receipt(0)->assertCreated()->json('data'); $this->loginCashier();
        $id=$this->generate(['kind'=>'receipt','source_id'=>$receipt['last_record_id'],'format'=>'html'])->assertCreated()->json('data.id');
        $this->visit->patient->forceFill(['access_status'=>'restricted','version'=>2])->save();
        $this->getJson('/api/v1/exports/'.$id.'/download')->assertNotFound();
    }

    public function test_rendering_escapes_html_and_neutralizes_spreadsheet_formulas_without_float_conversion(): void
    {
        $renderer=app(ExportRendering::class);
        $data=['name'=>'<script>alert(1)</script>','formula'=>" \t=HYPERLINK(1)",'money'=>'99999999999999999.01'];
        $html=$renderer->render($data,'html',now()->toISOString());
        $this->assertStringNotContainsString('<script>',$html); $this->assertStringContainsString('&lt;script&gt;',$html);
        $csv=$renderer->render($data,'csv',now()->toISOString());
        $this->assertStringContainsString("' \t=HYPERLINK(1)",$csv); $this->assertStringContainsString('99999999999999999.01',$csv);
    }

    public function test_generation_failure_rolls_back_artifact_command_and_success_audit(): void
    {
        $hit=$this->failNextWriteTo('idempotency_requests','update');
        $this->generate()->assertStatus(500); $this->assertTrue($hit());
        $this->assertDatabaseCount('document_exports',0); $this->assertDatabaseCount('idempotency_requests',0);
        $this->assertDatabaseMissing('audit_events',['action'=>'export.generated','outcome'=>'succeeded']);
    }
}
