<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\BillingTestCase;
use Tests\RejectsOutboundRequests;

class DisconnectedBackendTest extends BillingTestCase
{
    use RefreshDatabase, RejectsOutboundRequests;

    public function test_recorded_cashier_transactions_reports_and_printable_copies_need_no_outbound_http_or_mail(): void
    {
        $this->grantRolePermissions('frontdesk',['reports.billing','exports.generate','exports.download']);
        $invoice=$this->invoice($this->performed())->assertCreated()->json('data');
        $receipt=$this->receipt($invoice['version'],'123.45')->assertCreated()->json('data');
        $this->loginCashier();
        $this->getJson($this->path())->assertOk()->assertJsonPath('data.totals.received','123.45');
        $this->getJson('/api/v1/reports/billing')->assertOk()->assertJsonPath('data.current.amounts.received','123.45');
        $copy=$this->postCommand('/api/v1/exports',['kind'=>'receipt','format'=>'html','source_id'=>$receipt['last_record_id']])->assertCreated()->json('data.id');
        $print=$this->get('/api/v1/exports/'.$copy.'/print')->assertOk();
        $this->assertStringContainsString('123.45',$print->getContent());
        $this->assertDoesNotMatchRegularExpression('/(?:src|href)\s*=\s*["\x27](?:https?:)?\/\/|@import|url\s*\(/i',$print->getContent());
        $this->assertStringContainsString("default-src 'none'",$print->headers->get('Content-Security-Policy'));
    }

    public function test_a_failed_save_is_not_successful_and_the_same_key_can_be_safely_retried_after_recovery(): void
    {
        $headers=['Idempotency-Key'=>fake()->uuid()];
        $failed=$this->failNextWriteTo('billing_receipts','insert');
        $response=$this->receipt(0,'10.01',headers:$headers)->assertStatus(500)->assertJsonPath('code','server_error');
        $this->assertTrue($failed());
        $this->assertDatabaseCount('billing_receipts',0); $this->assertDatabaseCount('idempotency_requests',0);
        $this->assertDatabaseMissing('audit_events',['action'=>'billing.receipt','outcome'=>'succeeded']);
        $saved=$this->receipt(0,'10.01',headers:$headers)->assertCreated()->json('data');
        $this->receipt(0,'10.01',headers:$headers)->assertOk()->assertHeader('Idempotency-Replayed','true')->assertJsonPath('data.id',$saved['id']);
        $this->assertDatabaseCount('billing_receipts',1); $this->assertSame(1001,(int)DB::table('billing_receipts')->value('amount_kobo'));
        $this->assertStringNotContainsString('Synthetic failure',$response->getContent());
    }

    public function test_a_committed_save_with_a_lost_response_replays_one_external_payment_record(): void
    {
        $headers=['Idempotency-Key'=>fake()->uuid()];
        $this->receipt(0,'7.89',headers:$headers)->assertCreated();
        $recovered=$this->receipt(0,'7.89',headers:$headers)->assertOk()->assertHeader('Idempotency-Replayed','true');
        $this->assertDatabaseCount('billing_receipts',1); $this->assertDatabaseCount('billing_events',1);
        $this->assertSame('7.89',$recovered->json('data.totals.received'));
    }

    public function test_unavailable_readiness_is_a_failure_without_connection_details(): void
    {
        DB::partialMock()->shouldReceive('select')->once()->andThrow(new \RuntimeException('private disconnected server sentinel'));
        $response=$this->getJson('/api/v1/health')->assertStatus(503)->assertJsonPath('code','service_unavailable');
        $this->assertStringNotContainsString('sentinel',$response->getContent());
    }

    public function test_http_guard_rejects_accidental_network_dependencies_before_a_connection_is_made(): void
    {
        $this->expectException(\Illuminate\Http\Client\StrayRequestException::class);
        Http::withHeaders([])->setHandler(fn () => throw new \LogicException('The transport must not be reached.'))
            ->get('https://example.invalid/never-connect');
    }
}
