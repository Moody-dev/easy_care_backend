<?php

namespace Tests\Feature;

use App\Models\DoctorAssignment;
use App\Models\Encounter;
use App\Models\InvestigationItem;
use App\Models\InvestigationOrder;
use App\Models\User;
use App\Services\InvestigationCatalog;
use App\Services\InvestigationRequests;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;
use Tests\CommittedDatabase;
use Tests\TestCase;

class InvestigationRequestConcurrencyTest extends TestCase
{
    use CommittedDatabase;

    private User $doctor;
    private User $diagnostics;
    private Encounter $visit;
    private array $data;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Competing investigation requests require the isolated MySQL database.');
        }
        $this->seed(DatabaseSeeder::class);
        $this->grantRolePermissions('doctor', ['patients.view', 'encounters.view', 'investigations.view', 'investigations.request', 'investigations.catalog.view', 'investigations.catalog.publish']);
        $this->grantRolePermissions('diagnostics-and-radiologist', ['patients.view', 'encounters.view', 'encounters.view_any', 'investigations.view', 'investigations.collect', 'investigations.process', 'investigations.cancel']);
        $this->doctor = $this->createStaffWithRole('doctor');
        $this->diagnostics = $this->createStaffWithRole('diagnostics-and-radiologist');
        $this->visit = Encounter::factory()->create(['opened_at' => now('UTC')->subHours(2)]);
        $assignment = DoctorAssignment::factory()->create(['encounter_id' => $this->visit->id, 'doctor_id' => $this->doctor->id, 'assigned_at' => now('UTC')->subHour()]);
        $schema = app(InvestigationCatalog::class)->publish(['code' => 'synthetic-race-panel', 'name' => 'Synthetic race panel', 'department' => 'laboratory',
            'specimen' => 'Synthetic specimen', 'is_active' => true, 'title' => 'Synthetic approved form',
            'service' => ['code' => 'synthetic-race-service', 'name' => 'Synthetic service'], 'fields' => ['observation' => ['type' => 'text']]],
            $this->doctor->id, 0, 'Synthetic approval', 'Initial approved publication');
        $this->data = ['patient_version' => 1, 'encounter_version' => 1, 'assignment_id' => $assignment->id, 'assignment_version' => 1,
            'reason' => 'Synthetic concurrent request', 'items' => [['test_id' => $schema->test_id, 'schema_id' => $schema->id, 'test_version' => 1, 'priority' => 'routine']]];
    }

    public function test_competing_identical_submissions_confirm_one_order_items_history_and_receipt(): void
    {
        $this->assertSame(['saved', 'replayed'], $this->race('store', $this->data, 'store', $this->data, null, true));
        $this->assertDatabaseCount('investigation_orders', 1);
        $this->assertDatabaseCount('investigation_items', 1);
        $this->assertDatabaseCount('investigation_revisions', 1);
        $this->assertDatabaseCount('idempotency_requests', 1);
    }

    public function test_collection_winning_first_prevents_stale_cancellation(): void
    {
        $order = $this->savedOrder();
        $data = ['encounter_version' => 1, 'order_version' => 1, 'version' => 1, 'reason' => 'Synthetic queue action'];
        $this->assertSame(['saved', 'version_conflict'], $this->race('collect', $data + ['specimen_ref' => 'Synthetic specimen'], 'cancel', $data, $order));
        $this->assertSame('collected', InvestigationItem::sole()->status);
        $this->assertDatabaseCount('investigation_revisions', 2);
    }

    public function test_cancellation_winning_first_prevents_stale_collection(): void
    {
        $order = $this->savedOrder();
        $data = ['encounter_version' => 1, 'order_version' => 1, 'version' => 1, 'reason' => 'Synthetic queue action'];
        $this->assertSame(['saved', 'version_conflict'], $this->race('cancel', $data, 'collect', $data + ['specimen_ref' => 'Synthetic specimen'], $order));
        $this->assertSame('cancelled', InvestigationItem::sole()->status);
        $this->assertNull(InvestigationItem::sole()->collected_at);
        $this->assertDatabaseCount('investigation_revisions', 2);
    }

    private function savedOrder(): InvestigationOrder
    {
        $this->actingAs($this->doctor)->postCommand('/api/v1/encounters/'.$this->visit->id.'/investigations', $this->data)->assertCreated();

        return InvestigationOrder::sole();
    }

    private function race(string $first, array $firstData, string $second, array $secondData, ?InvestigationOrder $order, bool $sameKey = false): array
    {
        $input = new InputStream;
        $early = $this->worker($first, $firstData, $order, true, 'synthetic-first-request-key');
        $later = $this->worker($second, $secondData, $order, false, $sameKey ? 'synthetic-first-request-key' : 'synthetic-second-request-key');
        $later->setInput($input);
        try {
            $later->start();
            $this->assertTrue($later->waitUntil(fn (string $type, string $out): bool => str_contains($out, 'READY')), $later->getErrorOutput());
            $early->start();
            $locked = $early->waitUntil(fn (string $type, string $out): bool => str_contains($out, 'LOCKED'));
            $this->assertTrue($locked, $early->getErrorOutput().$early->getOutput());
            $input->write("continue\n");
            $this->assertTrue($later->waitUntil(fn (string $type, string $out): bool => str_contains($out, 'WRITING')), $later->getErrorOutput());
            $early->wait();
            $later->wait();
            $this->assertTrue($early->isSuccessful(), $early->getErrorOutput());
            $this->assertTrue($later->isSuccessful(), $later->getErrorOutput());
            $outcomes = [];
            foreach ([$early, $later] as $process) {
                $lines = explode("\n", trim($process->getOutput()));
                $outcomes[] = end($lines);
            }

            return $outcomes;
        } finally {
            $input->close();
            $early->stop(0);
            $later->stop(0);
        }
    }

    private function worker(string $action, array $data, ?InvestigationOrder $order, bool $hold, string $key): Process
    {
        $code = <<<'PHP'
require $argv[1].'/vendor/autoload.php';
$app = require $argv[1].'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (!$app->environment('testing') || $app->configurationIsCached() || config('database.default') !== 'mysql'
    || config('database.connections.mysql.database') !== 'easycare_test' || config('database.connections.mysql.username') !== 'easycare_test'
    || !in_array(config('database.connections.mysql.host'), ['localhost','127.0.0.1','::1'],true) || config('database.connections.mysql.url')) {
    throw new RuntimeException('Request workers require the isolated MySQL database.');
}
$action=$argv[2]; $data=json_decode($argv[3],true,flags:JSON_THROW_ON_ERROR);
$uri=$action==='store'?'api/v1/encounters/{encounter}/investigations':'api/v1/investigations/orders/{investigationOrder}/items/{investigationItem}/'.$action;
$path=$action==='store'?'api/v1/encounters/'.$argv[5].'/investigations':'api/v1/investigations/orders/'.$argv[6].'/items/'.$argv[7].'/'.$action;
$request=Illuminate\Http\Request::create('/'.$path,'POST',$data); $request->headers->set('Idempotency-Key',$argv[9]);
$route=new Illuminate\Routing\Route('POST',$uri,fn()=>null); $route->name('api.v1.investigations.requests.'.$action); $route->bind($request);
$request->setRouteResolver(fn()=>$route); $app->instance('request',$request);
$request->setUserResolver(fn()=>App\Models\User::findOrFail((int)$argv[4]));
if($argv[8]!=='hold') {echo "READY\n"; flush(); fgets(STDIN); echo "WRITING\n"; flush();}
try {
    $response=$app->make(App\Services\IdempotentCommands::class)->run($request,function() use($app,$request,$data,$argv) {
        $order=$app->make(App\Services\InvestigationRequests::class)->execute($request,$request->user('web'),$data);
        if($argv[8]==='hold') {echo "LOCKED\n"; flush(); usleep(1000000);}
        return App\Services\InvestigationRequests::response($order);
    });
    echo $response->headers->get('Idempotency-Replayed')==='true'?"replayed\n":"saved\n";
} catch(App\Exceptions\WriteConflict $e) {echo $e->errorCode."\n";}
PHP;

        return new Process([PHP_BINARY, '-r', $code, base_path(), $action, json_encode($data, JSON_THROW_ON_ERROR),
            (string) ($action === 'store' ? $this->doctor->id : $this->diagnostics->id), (string) $this->visit->id,
            (string) ($order?->id ?? 0), (string) ($order?->items->sole()->id ?? 0), $hold ? 'hold' : 'run', $key],
            base_path(), ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => 'easycare_test', 'DB_USERNAME' => 'easycare_test', 'DB_URL' => ''], timeout: 30);
    }

}
