<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;
use Tests\BillingTestCase;

class ExportConcurrencyTest extends BillingTestCase
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
        $this->grantRolePermissions('frontdesk',['reports.billing','exports.generate','exports.download']);
    }

    private function job(string $action): array
    {
        return ['action'=>$action,'actor'=>$this->cashier->id,'patient'=>$this->visit->patient_id,
            'key'=>fake()->uuid(),'date'=>now('Africa/Lagos')->toDateString(),'paid_at'=>now('UTC')->toISOString(),
            'password'=>self::STAFF_TEST_PASSWORD];
    }

    private function copy(): int
    {
        $this->loginCashier();
        return $this->postCommand('/api/v1/exports',['kind'=>'report','format'=>'csv','report_key'=>'billing',
            'filters'=>['from_date'=>now('Africa/Lagos')->toDateString(),'to_date'=>now('Africa/Lagos')->toDateString(),'patient_id'=>$this->visit->patient_id]])
            ->assertCreated()->json('data.id');
    }

    public function test_competing_identical_commands_create_one_document_and_replay_the_same_owner_outcome(): void
    {
        $job=$this->job('generate');
        [$first,$second]=$this->race($job,$job);
        $this->assertSame(201,$first['status']); $this->assertSame(200,$second['status']);
        $this->assertSame($first['id'],$second['id']);
        $this->assertDatabaseCount('document_exports',1);
        $this->assertDatabaseCount('idempotency_requests',1);
    }

    public function test_download_waiting_for_permission_revocation_rechecks_access_before_returning_bytes(): void
    {
        $id=$this->copy();
        [$writer,$reader]=$this->race($this->job('revoke'),$this->job('download')+['export'=>$id]);
        $this->assertSame(200,$writer['status']); $this->assertSame(403,$reader['status']);
        $this->assertDatabaseMissing('audit_events',['action'=>'export.downloaded','outcome'=>'succeeded']);
    }

    public function test_download_waiting_for_patient_restriction_does_not_return_the_saved_private_copy(): void
    {
        $id=$this->copy();
        [$writer,$reader]=$this->race($this->job('restrict'),$this->job('download')+['export'=>$id]);
        $this->assertSame(200,$writer['status']); $this->assertSame(404,$reader['status']);
    }

    public function test_a_committed_payment_makes_an_earlier_report_copy_outdated_before_download(): void
    {
        $id=$this->copy();
        [$writer,$reader]=$this->race($this->job('receipt'),$this->job('download')+['export'=>$id]);
        $this->assertSame(201,$writer['status']); $this->assertSame(409,$reader['status']);
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
            $input->write("continue\n"); $early->wait(); $later->wait(); $results=[];
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
if ($argv[3]!=='hold') { echo "READY\n"; flush(); fgets(STDIN); }
try {
    if ($job['action']==='download') {
        $response=$app->make(App\Services\DocumentExports::class)->download($actor,$job['export'],false);
        echo json_encode(['status'=>$response->getStatusCode()])."\n";
    } else {
        Illuminate\Support\Facades\DB::beginTransaction();
        if ($job['action']==='generate') {
            $path='/api/v1/exports';
            $request=Illuminate\Http\Request::create($path,'POST',['kind'=>'report','format'=>'csv','report_key'=>'billing',
                'filters'=>['from_date'=>$job['date'],'to_date'=>$job['date'],'patient_id'=>$job['patient']]]);
            $request->headers->set('Idempotency-Key',$job['key']);
            $route=new Illuminate\Routing\Route('POST',ltrim($path,'/'),fn()=>null); $route->name('api.v1.exports.store'); $route->bind($request);
            $request->setRouteResolver(fn()=>$route); $app->instance('request',$request);
            Illuminate\Support\Facades\Auth::guard('web')->setUser($actor); $request->setUserResolver(fn()=>$actor);
            $response=$app->make(App\Services\IdempotentCommands::class)->run($request,fn()=>
                $app->make(App\Services\DocumentExports::class)->create($actor,$request->all()));
            $result=['status'=>$response->getStatusCode(),'id'=>$response->getData(true)['data']['id']];
        } elseif ($job['action']==='receipt') {
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
                App\Models\Role::where('code','frontdesk')->sole()->permissions()->detach(App\Models\Permission::where('code','exports.download')->value('id'));
            } else { App\Models\Patient::findOrFail($job['patient'])->forceFill(['access_status'=>'restricted','version'=>2])->save(); }
            $result=['status'=>200];
        }
        echo "LOCKED\n"; flush(); usleep(1200000); Illuminate\Support\Facades\DB::commit();
        echo json_encode($result,JSON_THROW_ON_ERROR)."\n";
    }
} catch (Illuminate\Database\Eloquent\ModelNotFoundException $exception) {
    if (Illuminate\Support\Facades\DB::transactionLevel()>0) { Illuminate\Support\Facades\DB::rollBack(); }
    echo json_encode(['status'=>404])."\n";
} catch (App\Exceptions\WriteConflict $exception) {
    if (Illuminate\Support\Facades\DB::transactionLevel()>0) { Illuminate\Support\Facades\DB::rollBack(); }
    echo json_encode(['status'=>409])."\n";
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
