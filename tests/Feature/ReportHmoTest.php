<?php

namespace Tests\Feature;

use App\Models\BillingAccount;
use App\Services\HmoBalances;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\HmoTestCase;

class ReportHmoTest extends HmoTestCase
{
    use RefreshDatabase;

    public function test_resubmissions_do_not_duplicate_claims_and_only_the_latest_reviewed_payer_decision_affects_balances(): void
    {
        $this->grantRolePermissions('frontdesk',['reports.hmo']);
        $claim=$this->approvedClaim();
        $next=$this->patientCommand($claim['version'],'/claims/'.$claim['claim_id'].'/resubmit',['reference'=>'Synthetic reporting resubmission',
            'submitted_at'=>now('UTC')->toISOString(),'externally_submitted'=>true])->assertCreated()->json('data');
        $claim['submission_id']=\Illuminate\Support\Facades\DB::table('hmo_submissions')->where('claim_id',$claim['claim_id'])->orderByDesc('sequence')->value('id');
        $decision=$this->patientCommand($next['version'],'/decisions',$this->decision($claim,'70.00'))->assertCreated()->json('data');
        $this->patientCommand($decision['version'],'/decisions/'.$decision['last_record_id'].'/review',$this->reviewFields(),$this->reviewer)->assertCreated();
        $this->getJson('/api/v1/reports/hmo')->assertOk()->assertJsonPath('data.period.submission_attempts',2)
            ->assertJsonPath('data.period.distinct_claims_submitted',1)->assertJsonPath('data.current.claim_count',1)
            ->assertJsonPath('data.current.amounts.insurer_approved','70.00')->assertJsonPath('data.current.amounts.insurer_disputed','50.00');
    }

    public function test_hmo_reports_match_reviewed_decisions_reservations_settlements_and_patient_isolation(): void
    {
        $this->grantRolePermissions('frontdesk',['reports.hmo','reports.billing']);
        $claim=$this->approvedClaim();
        $receipt=$this->remittance();
        $pending=$this->patientCommand($claim['version'],'/allocations',$this->allocationFields($claim,$receipt))->assertCreated()->json('data');
        $this->getJson('/api/v1/reports/hmo')->assertOk()->assertJsonPath('data.current.amounts.insurer_liability','120.00')
            ->assertJsonPath('data.current.amounts.insurer_approved','100.00')->assertJsonPath('data.current.amounts.insurer_reserved','80.00')
            ->assertJsonPath('data.current.amounts.insurer_disputed','20.00')->assertJsonPath('data.provider_remittance_cashflow_included',false);
        $this->patientCommand($pending['version'],'/allocations/'.$pending['last_record_id'].'/review',
            $this->reviewFields()+['remittance_version'=>3],$this->reviewer)->assertCreated();
        $response=$this->getJson('/api/v1/reports/hmo')->assertOk()->assertJsonPath('data.current.amounts.insurer_settled','80.00')
            ->assertJsonPath('data.current.amounts.insurer_outstanding','40.00')->assertJsonPath('data.period.settlement_allocations_posted.amounts.amount','80.00');
        $expected=app(HmoBalances::class)->account(BillingAccount::sole(),false)['totals'];
        foreach ($expected as $field=>$amount) {
            $this->assertSame(\App\Support\BillingMoney::format($amount),$response->json('data.current.amounts.'.$field));
        }
        $this->getJson('/api/v1/reports/billing')->assertOk()->assertJsonPath('data.current.amounts.outstanding','30.00');
        $this->visit->patient->forceFill(['access_status'=>'restricted','version'=>2])->save();
        $this->getJson('/api/v1/reports/hmo')->assertOk()->assertJsonPath('data.current.claim_count',0)->assertJsonPath('data.current.amounts.insurer_outstanding','0.00');
    }
}
