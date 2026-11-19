<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;
use Tests\BillingTestCase;

class ReportConcurrencyTest extends BillingTestCase
{
    use DatabaseTruncation { truncateDatabaseTables as private resetTables; }
    private bool $mysqlReady=false;

    protected function truncateDatabaseTables(): void
    {
        if (DB::getDriverName()!=='mysql') { $this->markTestSkipped('Competing report snapshots require isolated MySQL.'); }
        $this->resetTables(); $this->mysqlReady=true;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->grantRolePermissions('frontdesk',['reports.billing']);
    }

    private function job(string $action): array
    {
        return ['action'=>$action,'actor'=>$this->cashier->id,'patient'=>$this->visit->patient_id,
            'key'=>fake()->uuid(),'date'=>now('Africa/Lagos')->toDateString(),'paid_at'=>now('UTC')->toISOString(),
            'password'=>self::STAFF_TEST_PASSWORD];
    }

    public function test_report_waiting_for_a_payment_reads_the_committed_receipt_and_credit_together(): void
    {
        [$writer,$report]=$this->race($this->job('receipt'),$this->job('report'));
        $this->assertSame(201,$writer['status']);
        $this->assertSame(200,$report['status']);
        $this->assertSame('250.00',$report['data']['current']['amounts']['received']);
        $this->assertSame('250.00',$report['data']['current']['amounts']['available_credit']);
        $this->assertDatabaseCount('billing_receipts',1);
    }

    public function test_a_report_permission_revoked_while_a_request_waits_is_rechecked_before_any_figures_are_returned(): void
    {
        [$writer,$report]=$this->race($this->job('revoke'),$this->job('report'));
        $this->assertSame(200,$writer['status']);
        $this->assertSame(403,$report['status']);
        $this->assertArrayNotHasKey('data',$report);
    }

    public function test_patient_restriction_committed_before_the_snapshot_removes_the_account_from_all_totals(): void
    {
        $this->invoice($this->performed())->assertCreated();
        [$writer,$report]=$this->race($this->job('restrict'),$this->job('report'));
        $this->assertSame(200,$writer['status']);
        $this->assertSame(200,$report['status']);
        $this->assertSame(0,$report['data']['current']['account_count']);
        $this->assertSame('0.00',$report['data']['current']['amounts']['gross_charges']);
    }

    private function race(array $first,array $second): array
    {
        $input=new InputStream;
        $early=$this->worker($first,true); $later=$this->worker($second,false); $later->setInput($input);
        try {
            $later->start();
            $this->assertTrue($later->waitUntil(fn (string $type,string $output): bool=>str_contains($output,'READY')),$later->getErrorOutput());
            $early->start();
            $this->assertTrue($early->waitUntil(fn (string $type,string $output): bool=>str_contains($output,'LOCKED')),$early->getErrorOutput().$early->getOutput());
            $input->write("continue\n");
            $this->assertTrue($later->waitUntil(fn (string $type,string $output): bool=>str_contains($output,'WRITING')),$later->getErrorOutput());
            $this->assertTrue($early->isRunning(),'The report must start while the competing transaction is held.');
            $early->wait(); $later->wait(); $results=[];
            foreach ([$early,$later] as $process) {
                $this->assertTrue($process->isSuccessful(),$process->getErrorOutput().$process->getOutput());
                $lines=explode("\n",trim($process->getOutput()));
                $results[]=json_decode(end($lines),true,flags:JSON_THROW_ON_ERROR);
            }
            return $results;
        } finally { $input->close(); $early->stop(0); $later->stop(0); }
    }

    private function worker(array $job,bool $hold): Process
    {
        $code= <<<'PHP'
require $argv[1].'/vendor/autoload.php';
$app=require $argv[1].'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (!$app->environment('testing') || $app->configurationIsCached() || config('database.default')!=='mysql'
    || config('database.connections.mysql.database')!=='easycare_test' || config('database.connections.mysql.username')!=='easycare_test'
    || !in_array(config('database.connections.mysql.host'),['localhost','127.0.0.1','::1'],true) || config('database.connections.mysql.url')) {
    throw new RuntimeException('Reporting workers require isolated local MySQL.');
}
$job=json_decode($argv[2],true,flags:JSON_THROW_ON_ERROR);
$actor=App\Models\User::findOrFail($job['actor']);
if ($argv[3]!=='hold') { echo "READY\n"; flush(); fgets(STDIN); echo "WRITING\n"; flush(); }
try {
    if ($job['action']==='report') {
        $response=$app->make(App\Services\ReportSnapshots::class)->run($actor,'billing',['from_date'=>$job['date'],'to_date'=>$job['date']],
            fn($viewer,$period)=>$app->make(App\Services\FinancialReports::class)->billing($viewer,$period));
        echo json_encode(['status'=>200,'data'=>$response['data']],JSON_THROW_ON_ERROR)."\n";
    } else {
        Illuminate\Support\Facades\DB::beginTransaction();
        if ($job['action']==='receipt') {
            $path='/api/v1/billing/patients/'.$job['patient'].'/receipts';
            $request=Illuminate\Http\Request::create($path,'POST',['account_version'=>0,'reason'=>'Synthetic report payment','current_password'=>$job['password'],
                'amount'=>'250.00','kind'=>'deposit','method'=>'cash','payer_name'=>'Synthetic outside payer','paid_at'=>$job['paid_at'],
                'externally_confirmed'=>false,'transaction_details'=>['receipt_number'=>'Synthetic report voucher']]);
            $request->headers->set('Idempotency-Key',$job['key']);
            $route=new Illuminate\Routing\Route('POST',ltrim($path,'/'),fn()=>null);
            $route->name('api.v1.billing.receipt'); $route->bind($request); $route->setParameter('patient',(string)$job['patient']);
            $request->setRouteResolver(fn()=>$route); $app->instance('request',$request);
            Illuminate\Support\Facades\Auth::guard('web')->setUser($actor); $request->setUserResolver(fn()=>$actor);
            $response=$app->make(App\Services\IdempotentCommands::class)->run($request,fn()=>
                $app->make(App\Services\BillingWorkflows::class)->execute($request,$actor,
                    $app->make(App\Http\Requests\Api\V1\BillingCommandRequest::class)->validated()));
            $result=['status'=>$response->getStatusCode()];
        } else {
            App\Models\Permission::where('code','staff.manage')->lockForUpdate()->firstOrFail();
            App\Models\Permission::where('code','patients.create')->lockForUpdate()->firstOrFail();
            if ($job['action']==='revoke') {
                App\Models\Role::where('code','frontdesk')->sole()->permissions()->detach(App\Models\Permission::where('code','reports.billing')->value('id'));
            } else { App\Models\Patient::findOrFail($job['patient'])->forceFill(['access_status'=>'restricted','version'=>2])->save(); }
            $result=['status'=>200];
        }
        echo "LOCKED\n"; flush(); usleep(1200000); Illuminate\Support\Facades\DB::commit();
        echo json_encode($result,JSON_THROW_ON_ERROR)."\n";
    }
} catch (Illuminate\Auth\Access\AuthorizationException $exception) {
    if (Illuminate\Support\Facades\DB::transactionLevel()>0) { Illuminate\Support\Facades\DB::rollBack(); }
    echo json_encode(['status'=>403])."\n";
} catch (Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $exception) {
    if (Illuminate\Support\Facades\DB::transactionLevel()>0) { Illuminate\Support\Facades\DB::rollBack(); }
    echo json_encode(['status'=>$exception->getStatusCode()])."\n";
}
PHP;
        return new Process([PHP_BINARY,'-r',$code,base_path(),json_encode($job,JSON_THROW_ON_ERROR),$hold?'hold':'run'],base_path(),
            ['APP_ENV'=>'testing','DB_CONNECTION'=>'mysql','DB_DATABASE'=>'easycare_test','DB_USERNAME'=>'easycare_test','DB_URL'=>''],timeout:45);
    }

    protected function tearDown(): void
    {
        try { if ($this->mysqlReady) { $this->truncateTablesForAllConnections(); } }
        finally { parent::tearDown(); }
    }
}
