<?php

namespace Tests\Feature;

use App\Services\BillingBalances;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\BillingTestCase;

class ReportFinancialTest extends BillingTestCase
{
    use RefreshDatabase;

    public function test_approved_credit_allocation_release_and_physical_refund_match_existing_ledger_calculations(): void
    {
        $invoice=$this->invoice($this->performed())->assertCreated()->json('data');
        $receipt=$this->receipt($invoice['version'],'250.00')->assertCreated()->json('data');
        $allocated=$this->writeBill($receipt['version'],'/allocations',['receipt_id'=>$receipt['last_record_id'],'invoice_id'=>$invoice['last_record_id'],'amount'=>'200.00'])->assertCreated()->json('data');
        $credit=$this->writeBill($allocated['version'],'/credits',['item_id'=>DB::table('billing_invoice_items')->value('id'),'amount'=>'100.00'])->assertCreated()->json('data');
        $credited=$this->writeBill($credit['version'],'/changes/'.$credit['last_record_id'].'/approve',[], $this->reviewer)->assertCreated()->json('data');
        $refund=$this->writeBill($credited['version'],'/refunds',['receipt_id'=>$receipt['last_record_id'],'amount'=>'50.00'])->assertCreated()->json('data');
        $this->getJson('/api/v1/reports/billing')->assertOk()->assertJsonPath('data.current.amounts.refund_reserved','50.00')
            ->assertJsonPath('data.current.amounts.available_credit','100.00')->assertJsonPath('data.period.physical_refunds_paid.amounts.amount','0.00');
        $this->writeBill($refund['version'],'/changes/'.$refund['last_record_id'].'/approve',['payout_confirmed'=>true,
            'payout_reference'=>'Synthetic report refund','payout_at'=>now('UTC')->toISOString(),'payout_method'=>'cash',
            'payee_name'=>'Synthetic recipient','payout_details'=>[]],$this->reviewer)->assertCreated();
        $this->getJson('/api/v1/reports/billing')->assertOk()->assertJsonPath('data.current.amounts.outstanding','0.00')
            ->assertJsonPath('data.current.amounts.allocated','100.00')->assertJsonPath('data.current.amounts.credited','100.00')
            ->assertJsonPath('data.current.amounts.refunded','50.00')->assertJsonPath('data.current.amounts.refund_reserved','0.00')
            ->assertJsonPath('data.period.physical_refunds_paid.amounts.amount','50.00')->assertJsonPath('data.period.credits_approved.amounts.amount','100.00');
    }

    public function test_multiple_patient_totals_can_exceed_the_per_account_money_limit_without_rounding_or_overflow(): void
    {
        $this->price=\App\Models\ServicePrice::factory()->create(['service_id'=>$this->price->service_id,'version'=>2,
            'amount_micros'=>999999999990000,'effective_at'=>now('UTC')]);
        for ($i=0;$i<2;$i++) {
            if ($i===1) { $this->visit=\App\Models\Encounter::factory()->create(['opened_at'=>now('UTC')->subHour()]); }
            $source=$this->writeBill(0,'/performed-services',['encounter_id'=>$this->visit->id,'encounter_version'=>1,'service_id'=>$this->price->service_id,
                'quantity'=>'1000','occurred_at'=>now('UTC')->toISOString(),'performed_confirmed'=>true,'reference'=>'Synthetic-large-report-'.$i])->assertCreated()->json('data');
            $this->invoice($source)->assertCreated();
        }
        $this->getJson('/api/v1/reports/billing')->assertOk()->assertJsonPath('data.current.amounts.gross_charges','1999999999980.00')
            ->assertJsonPath('data.current.account_count',2);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now('Africa/Lagos')->addDay()->setTime(12,0)->utc());
        $this->grantRolePermissions('frontdesk',['dashboard.view','reports.billing']);
    }

    public function test_financial_reports_match_retained_account_balances_and_separate_period_cashflows(): void
    {
        $invoice=$this->invoice($this->performed())->assertCreated()->json('data');
        $receipt=$this->receipt($invoice['version'],'250.00')->assertCreated()->json('data');
        $this->writeBill($receipt['version'],'/allocations',['receipt_id'=>$receipt['last_record_id'],'invoice_id'=>$invoice['last_record_id'],'amount'=>'150.00'])->assertCreated();
        $response=$this->getJson('/api/v1/reports/billing')->assertOk()->assertJsonPath('data.current.amounts.outstanding','50.00')
            ->assertJsonPath('data.current.amounts.available_credit','100.00')->assertJsonPath('data.period.physical_payments_received.amounts.amount','250.00');
        $this->assertSame('200.00',$response->json('data.current.amounts.gross_charges'));
        $account=\App\Models\BillingAccount::sole();
        $expected=app(BillingBalances::class)->snapshot($account,false)['totals'];
        $this->assertSame(\App\Support\BillingMoney::format($expected['outstanding']),$response->json('data.current.amounts.outstanding'));
        $tomorrow=now('Africa/Lagos')->addDay()->toDateString();
        $this->getJson('/api/v1/reports/billing?from_date='.$tomorrow.'&to_date='.$tomorrow)->assertOk()
            ->assertJsonPath('data.period.physical_payments_received.amounts.amount','0.00')->assertJsonPath('data.current.amounts.outstanding','50.00');
        $this->getJson('/api/v1/reports/accounts')->assertOk()->assertJsonPath('data.data.0.current.amounts.available_credit','100.00');
    }

    public function test_restricted_patient_accounts_are_excluded_from_money_and_counts(): void
    {
        $invoice=$this->invoice($this->performed())->assertCreated()->json('data');
        $this->visit->patient->forceFill(['access_status'=>'restricted','version'=>2])->save();
        $this->getJson('/api/v1/reports/billing')->assertOk()->assertJsonPath('data.current.account_count',0)
            ->assertJsonPath('data.current.amounts.gross_charges','0.00');
        $this->getJson('/api/v1/reports/billing?patient_id='.$this->visit->patient_id)->assertNotFound();
        $this->getJson('/api/v1/reports/hmo')->assertForbidden();
    }
}
