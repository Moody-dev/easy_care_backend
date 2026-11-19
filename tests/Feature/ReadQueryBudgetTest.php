<?php

namespace Tests\Feature;

use App\Models\BillingAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\BillingTestCase;

class ReadQueryBudgetTest extends BillingTestCase
{
    use RefreshDatabase;

    #[\PHPUnit\Framework\Attributes\DataProvider('payerModes')]
    public function test_account_page_queries_do_not_grow_per_returned_account_and_balances_keep_the_same_meaning(bool $hmo): void
    {
        $this->grantRolePermissions('frontdesk',['reports.billing',...($hmo?['reports.hmo','hmo.view']:[])]);
        BillingAccount::factory()->count(28)->create(['actor_id'=>$this->cashier->id]);
        $invoice=$this->invoice($this->performed())->assertCreated()->json('data');
        $this->receipt($invoice['version'],'250.00')->assertCreated();
        $this->visit=\App\Models\Encounter::factory()->create(['opened_at'=>now('UTC')->subHour()]);
        $second=$this->invoice($this->performed())->assertCreated()->json('data');
        $this->receipt($second['version'],'111.12')->assertCreated();
        $this->loginCashier();
        $this->getJson('/api/v1/reports/accounts?per_page=1')->assertOk();
        $counts=[];
        foreach ([1,25,100] as $size) {
            DB::flushQueryLog(); DB::enableQueryLog();
            try {
                $response=$this->getJson('/api/v1/reports/accounts?per_page='.$size)->assertOk();
                $counts[$size]=count(DB::getQueryLog());
            } finally { DB::disableQueryLog(); DB::flushQueryLog(); }
            $response->assertJsonPath('data.total',30)->assertJsonCount(min($size,30),'data.data');
            if ($size===100) {
                $first=$response->json('data.data.28.current');
                $last=$response->json('data.data.29.current');
                $this->assertSame('200.00',$first['amounts']['gross_charges']);
                $this->assertSame('250.00',$first['amounts']['available_credit']);
                $this->assertSame('200.00',$last['amounts']['gross_charges']);
                $this->assertSame('111.12',$last['amounts']['available_credit']);
                $this->assertSame(1,$last['invoice_count']);
                $this->assertSame(1,$last['receipt_count']);
                if ($hmo) { $this->assertSame('0.00',$last['amounts']['insurer_outstanding']); }
                else { $this->assertArrayNotHasKey('insurer_outstanding',$last['amounts']); }
            }
        }
        $this->assertLessThanOrEqual($counts[1]+3,$counts[25],json_encode($counts));
        $this->assertLessThanOrEqual($counts[1]+3,$counts[100],json_encode($counts));
    }

    public static function payerModes(): array { return [[false],[true]]; }

    public function test_patient_pages_are_stable_bounded_and_do_not_query_each_category_separately(): void
    {
        $category=$this->visit->patient->category_id;
        $records=\App\Models\Patient::factory()->count(110)->create(['created_by'=>$this->cashier->id,'category_id'=>$category,'last_name'=>'SyntheticPaging']);
        \App\Models\Patient::factory()->create(['created_by'=>$this->cashier->id,'category_id'=>$category,'last_name'=>'SyntheticPaging','access_status'=>'restricted']);
        $this->loginCashier(); $this->getJson('/api/v1/patients/options')->assertOk(); $counts=[];
        foreach ([1,100] as $size) {
            DB::flushQueryLog(); DB::enableQueryLog();
            try {
                $response=$this->getJson('/api/v1/patients?q=SyntheticPaging&per_page='.$size)->assertOk()->assertJsonPath('meta.total',110)->assertJsonCount($size,'data');
                $counts[$size]=count(DB::getQueryLog());
            } finally { DB::disableQueryLog(); DB::flushQueryLog(); }
            $this->assertSame($records->take($size)->modelKeys(),array_column($response->json('data'),'id'));
        }
        $this->assertLessThanOrEqual($counts[1]+1,$counts[100]);
        $page=$this->getJson('/api/v1/patients?q=SyntheticPaging&per_page=25&page=5')->assertOk()->assertJsonCount(10,'data');
        $this->assertSame($records->slice(100)->values()->modelKeys(),array_column($page->json('data'),'id'));
        $this->getJson('/api/v1/patients?per_page=101')->assertUnprocessable();
    }
}
