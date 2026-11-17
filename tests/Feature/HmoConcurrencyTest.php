<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;
use Tests\HmoTestCase;

class HmoConcurrencyTest extends HmoTestCase
{
    use DatabaseTruncation { truncateDatabaseTables as private resetTables; }

    private bool $mysqlReady = false;

    protected function truncateDatabaseTables(): void
    {
        if (DB::getDriverName() !== 'mysql') { $this->markTestSkipped('Concurrent financial commands require isolated MySQL.'); }
        $this->resetTables();
        $this->mysqlReady = true;
    }

    public function test_competing_hmo_invoice_posts_cannot_charge_the_same_source_twice(): void
    {
        $this->setupCatalog();
        $coverage = $this->coverage();
        $source = $this->performed($coverage['version']);
        $first = $this->job('invoice', '/patients/'.$this->visit->patient_id.'/invoices', ['account_version'=>$source['version']]+$this->splitFields($source));
        $second = $first; $second['actor']=$this->reviewer->id; $second['key']=fake()->uuid();
        [$winner,$loser]=$this->race($first,$second);
        $this->assertSame(201,$winner['status']);
        $this->assertSame(409,$loser['status']);
        $this->assertDatabaseCount('billing_invoices',1);
        $this->assertDatabaseCount('hmo_invoice_shares',1);
    }

    public function test_competing_independent_tariff_reviews_publish_only_one_bundle(): void
    {
        $this->setupCatalog();
        $import=$this->command('/imports',$this->importFields($this->csv(),1))->assertCreated()->json('data.id');
        $first=$this->job('tariff-review','/imports/'.$import.'/review',$this->reviewFields());
        $first['record']=$import; $first['actor']=$this->reviewer->id;
        $second=$first; $second['actor']=$this->createStaffWithRole('frontdesk')->id; $second['key']=fake()->uuid();
        [$winner,$loser]=$this->race($first,$second);
        $this->assertSame(201,$winner['status']);
        $this->assertSame(409,$loser['status']);
        $this->assertSame(1,DB::table('hmo_reviews')->where('kind','tariff')->where('record_id',$import)->count());
    }

    public function test_competing_cashiers_cannot_record_one_external_hmo_payment_twice(): void
    {
        $this->setupCatalog();
        $first=$this->job('remittance-create','/remittances',['provider_id'=>$this->providerId,'amount'=>'100.00',
            'reference'=>'Synthetic shared remittance','method'=>'cash','payer_name'=>'Synthetic payer',
            'received_at'=>now('UTC')->toISOString(),'externally_confirmed'=>true,'transaction_details'=>['receipt_number'=>'Synthetic voucher']]);
        $second=$first; $second['actor']=$this->reviewer->id; $second['key']=fake()->uuid();
        [$winner,$loser]=$this->race($first,$second);
        $this->assertSame(201,$winner['status']);
        $this->assertSame(409,$loser['status']);
        $this->assertDatabaseCount('hmo_remittances',1);
    }

    public function test_competing_claims_for_different_patients_cannot_spend_the_same_remittance(): void
    {
        $firstClaim=$this->approvedClaim();
        $firstPatient=$this->visit->patient_id;
        $receipt=$this->remittance();
        $this->visit=\App\Models\Encounter::factory()->create(['opened_at'=>now('UTC')->subHour()]);
        $secondClaim=$this->approvedClaim(false);
        $first=$this->job('allocation-record','/patients/'.$firstPatient.'/allocations',
            ['account_version'=>$firstClaim['version']]+$this->allocationFields($firstClaim,$receipt));
        $first['patient']=$firstPatient;
        $second=$this->job('allocation-record','/patients/'.$this->visit->patient_id.'/allocations',
            ['account_version'=>$secondClaim['version']]+$this->allocationFields($secondClaim,$receipt));
        $second['actor']=$this->reviewer->id;
        $second['data']['reference']='Synthetic allocation competing patient';
        [$winner,$loser]=$this->race($first,$second);
        $this->assertSame(201,$winner['status']);
        $this->assertSame(409,$loser['status']);
        $receipt['version']=3;
        $fields=$this->allocationFields($secondClaim,$receipt);
        $fields['reference']='Synthetic allocation competing patient';
        $this->patientCommand($secondClaim['version'],'/allocations',$fields)->assertConflict()->assertJsonPath('code','hmo_receipt_exceeded');
        $this->assertDatabaseCount('hmo_allocations',1);
        $this->assertSame(8000,(int)DB::table('hmo_allocations')->sum('amount_kobo'));
    }

    private function job(string $action,string $path,array $data): array
    {
        return ['patient'=>$this->visit->patient_id,'actor'=>$this->cashier->id,'action'=>$action,'path'=>'/api/v1/hmo'.$path,'key'=>fake()->uuid(),
            'data'=>$data+['reason'=>'Synthetic competing HMO command','current_password'=>self::STAFF_TEST_PASSWORD]];
    }

    private function race(array $first, array $second): array
    {
        $input = new InputStream;
        $early = $this->worker($first, true);
        $later = $this->worker($second, false);
        $later->setInput($input);
        try {
            $later->start();
            $this->assertTrue($later->waitUntil(fn (string $type, string $output): bool => str_contains($output, 'READY')), $later->getErrorOutput());
            $early->start();
            $this->assertTrue($early->waitUntil(fn (string $type, string $output): bool => str_contains($output, 'LOCKED')), $early->getErrorOutput().$early->getOutput());
            $input->write("continue\n");
            $early->wait();
            $later->wait();
            $outcomes = [];
            foreach ([$early, $later] as $process) {
                $this->assertTrue($process->isSuccessful(), $process->getErrorOutput().$process->getOutput());
                $lines = explode("\n", trim($process->getOutput()));
                $outcomes[] = json_decode(end($lines), true, flags: JSON_THROW_ON_ERROR);
            }

            return $outcomes;
        } finally {
            $input->close();
            $early->stop(0);
            $later->stop(0);
        }
    }

    private function worker(array $job, bool $hold): Process
    {
        $code = <<<'PHP'
require $argv[1].'/vendor/autoload.php';
$app = require $argv[1].'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (!$app->environment('testing') || $app->configurationIsCached() || config('database.default') !== 'mysql'
    || config('database.connections.mysql.database') !== 'easycare_test' || config('database.connections.mysql.username') !== 'easycare_test'
    || !in_array(config('database.connections.mysql.host'), ['localhost','127.0.0.1','::1'], true) || config('database.connections.mysql.url')) {
    throw new RuntimeException('Financial workers require the isolated local MySQL test database.');
}
$job = json_decode($argv[2], true, flags: JSON_THROW_ON_ERROR);
Illuminate\Support\Facades\DB::beginTransaction();
if ($argv[3] !== 'hold') {
    Illuminate\Support\Facades\DB::table('billing_events')->count();
    echo "READY\n"; flush(); fgets(STDIN);
}
try {
    $actor = App\Models\User::findOrFail($job['actor']);
    $path = $job['path'];
    $request = Illuminate\Http\Request::create($path, 'POST', $job['data']);
    $request->headers->set('Idempotency-Key', $job['key']);
    $route = new Illuminate\Routing\Route('POST', ltrim($path, '/'), fn () => null);
    $route->name('api.v1.hmo.'.$job['action']);
    $route->bind($request);
    $route->setParameter('patient', (string) $job['patient']);
    if (isset($job['record'])) { $route->setParameter('hmoRecord', (string) $job['record']); }
    $request->setRouteResolver(fn () => $route);
    $app->instance('request', $request);
    Illuminate\Support\Facades\Auth::guard('web')->setUser($actor);
    $request->setUserResolver(fn () => $actor);
    $response = $app->make(App\Services\IdempotentCommands::class)->run($request, fn () =>
        $app->make(App\Services\HmoWorkflows::class)->execute($request, $actor,
            $app->make(App\Http\Requests\Api\V1\HmoCommandRequest::class)->validated()));
    if ($argv[3] === 'hold') { echo "LOCKED\n"; flush(); usleep(1200000); }
    Illuminate\Support\Facades\DB::commit();
    echo json_encode(['status' => $response->getStatusCode(), 'id' => $response->getData(true)['data']['id']], JSON_THROW_ON_ERROR)."\n";
} catch (Illuminate\Auth\Access\AuthorizationException $exception) {
    Illuminate\Support\Facades\DB::rollBack(); echo json_encode(['status' => 403])."\n";
} catch (Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $exception) {
    Illuminate\Support\Facades\DB::rollBack(); echo json_encode(['status' => $exception->getStatusCode()])."\n";
}
PHP;

        return new Process([PHP_BINARY, '-r', $code, base_path(), json_encode($job, JSON_THROW_ON_ERROR), $hold ? 'hold' : 'run'], base_path(),
            ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => 'easycare_test', 'DB_USERNAME' => 'easycare_test', 'DB_URL' => ''], timeout: 40);
    }

    protected function tearDown(): void
    {
        try { if ($this->mysqlReady) { $this->truncateTablesForAllConnections(); } }
        finally { parent::tearDown(); }
    }
}
