<?php

namespace Tests\Feature;

use App\Models\DoctorAssignment;
use App\Models\Encounter;
use App\Models\InvestigationItem;
use App\Models\InvestigationResult;
use App\Models\User;
use App\Services\InvestigationCatalog;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;
use Tests\CommittedDatabase;
use Tests\TestCase;

class InvestigationResultConcurrencyTest extends TestCase
{
    use CommittedDatabase;

    private User $author;
    private User $reviewer;
    private User $otherReviewer;
    private InvestigationItem $item;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Competing result transactions require isolated MySQL.');
        }
        $this->seed(DatabaseSeeder::class);
        $this->grantRolePermissions('doctor', ['patients.view', 'encounters.view', 'investigations.view', 'investigations.request',
            'investigations.catalog.view', 'investigations.catalog.publish']);
        $this->grantRolePermissions('diagnostics-and-radiologist', ['patients.view', 'encounters.view', 'encounters.view_any', 'investigations.view',
            'investigations.process', 'investigations.results.view', 'investigations.results.enter', 'investigations.results.verify',
            'investigations.results.release', 'investigations.results.amend']);
        $doctor = $this->createStaffWithRole('doctor');
        $this->author = $this->createStaffWithRole('diagnostics-and-radiologist');
        $this->reviewer = $this->createStaffWithRole('diagnostics-and-radiologist');
        $this->otherReviewer = $this->createStaffWithRole('diagnostics-and-radiologist');
        $visit = Encounter::factory()->create(['opened_at' => now('UTC')->subHours(2)]);
        $assignment = DoctorAssignment::factory()->create(['encounter_id' => $visit->id, 'doctor_id' => $doctor->id, 'assigned_at' => now('UTC')->subHour()]);
        $schema = app(InvestigationCatalog::class)->publish(['code' => 'synthetic-result-race', 'name' => 'Synthetic result race',
            'department' => 'radiology', 'specimen' => null, 'is_active' => true, 'title' => 'Synthetic result race',
            'service' => ['code' => 'synthetic-result-race', 'name' => 'Synthetic service'],
            'fields' => ['value' => ['type' => 'number', 'unit' => null, 'required' => true]]],
            $doctor->id, 0, 'Synthetic approval', 'Synthetic approved publication');
        $this->login($doctor);
        $order = $this->postCommand('/api/v1/encounters/'.$visit->id.'/investigations',
            ['patient_version' => 1, 'encounter_version' => 1, 'assignment_id' => $assignment->id, 'assignment_version' => 1,
                'reason' => 'Synthetic request', 'items' => [['test_id' => $schema->test_id, 'schema_id' => $schema->id, 'test_version' => 1, 'priority' => 'routine']]])
            ->assertCreated()->json('data');
        $this->login($this->author);
        $this->postCommand('/api/v1/investigations/orders/'.$order['id'].'/items/'.$order['items'][0]['id'].'/process',
            ['encounter_version' => 1, 'order_version' => 1, 'version' => 1, 'reason' => 'Synthetic processing'])->assertOk();
        $this->item = InvestigationItem::findOrFail($order['items'][0]['id']);
    }

    private function login(User $user): void { $this->flushSession(); $this->actingAs($user); }

    private function path(?array $result = null): string
    {
        return '/api/v1/investigations/items/'.$this->item->id.'/results'.($result === null ? '' : '/'.$result['id']);
    }

    private function entry(): array
    {
        return ['encounter_version' => 1, 'item_version' => $this->item->fresh()->version, 'reason' => 'Synthetic result race',
            'fields' => ['value' => 0], 'comment' => null, 'attachment_ids' => []];
    }

    private function action(array $result): array
    {
        return ['encounter_version' => 1, 'item_version' => $this->item->fresh()->version, 'version' => $result['version'], 'reason' => 'Synthetic result action'];
    }

    private function submitted(): array
    {
        $draft = $this->postCommand($this->path(), $this->entry())->assertCreated()->json('data');

        return $this->postCommand($this->path($draft).'/submit', $this->action($draft))->assertOk()->json('data');
    }

    public function test_competing_identical_drafts_save_one_result_history_and_command(): void
    {
        $this->assertSame(['saved', 'replayed'], $this->race('store', $this->entry(), null, true));
        $this->assertDatabaseCount('investigation_results', 1);
        $this->assertDatabaseCount('investigation_result_revisions', 1);
        $this->assertSame(1, DB::table('idempotency_requests')->where('resource_type', 'investigation_result')->count());
    }

    public function test_competing_independent_verifiers_cannot_replace_each_others_verification(): void
    {
        $result = $this->submitted();
        $this->assertSame(['saved', 'version_conflict'], $this->race('verify', $this->action($result) + ['current_password' => self::STAFF_TEST_PASSWORD], $result));
        $this->assertSame($this->reviewer->id, InvestigationResult::sole()->verified_by);
        $this->assertDatabaseCount('investigation_result_revisions', 3);
    }

    public function test_competing_same_key_release_completes_the_item_once(): void
    {
        $result = $this->submitted();
        $this->login($this->reviewer);
        $result = $this->postCommand($this->path($result).'/verify', $this->action($result) + ['current_password' => self::STAFF_TEST_PASSWORD])->assertOk()->json('data');
        $this->assertSame(['saved', 'replayed'], $this->race('release', $this->action($result)
            + ['current_password' => self::STAFF_TEST_PASSWORD, 'order_version' => 2], $result, true));
        $this->assertSame('completed', $this->item->fresh()->status);
        $this->assertDatabaseCount('investigation_result_revisions', 4);
        $this->assertDatabaseCount('investigation_revisions', 3);
    }

    public function test_competing_amendments_keep_one_pending_replacement_and_the_original_release(): void
    {
        $result = $this->submitted();
        $this->login($this->reviewer);
        $result = $this->postCommand($this->path($result).'/verify', $this->action($result) + ['current_password' => self::STAFF_TEST_PASSWORD])->assertOk()->json('data');
        $result = $this->postCommand($this->path($result).'/release', $this->action($result)
            + ['current_password' => self::STAFF_TEST_PASSWORD, 'order_version' => 2])->assertOk()->json('data');
        $this->assertSame(['saved', 'result_work_in_progress'], $this->race('amend', $this->entry() + ['version' => $result['version']], $result));
        $this->assertDatabaseCount('investigation_results', 2);
        $this->assertSame('released', InvestigationResult::findOrFail($result['id'])->status);
        $this->assertSame(1, InvestigationResult::where('status', 'draft')->count());
    }

    private function race(string $action, array $data, ?array $result, bool $sameKey = false): array
    {
        $input = new InputStream;
        $early = $this->worker($action, $data, $result, true, 'synthetic-result-first-key');
        $later = $this->worker($action, $data, $result, false, $sameKey ? 'synthetic-result-first-key' : 'synthetic-result-second-key');
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

    private function worker(string $action, array $data, ?array $result, bool $hold, string $key): Process
    {
        $code = <<<'PHP'
require $argv[1].'/vendor/autoload.php';
$app = require $argv[1].'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (!$app->environment('testing') || $app->configurationIsCached() || config('database.default') !== 'mysql'
    || config('database.connections.mysql.database') !== 'easycare_test' || config('database.connections.mysql.username') !== 'easycare_test'
    || !in_array(config('database.connections.mysql.host'), ['localhost','127.0.0.1','::1'],true) || config('database.connections.mysql.url')) {
    throw new RuntimeException('Result workers require the isolated MySQL database.');
}
$action=$argv[2]; $data=json_decode($argv[3],true,flags:JSON_THROW_ON_ERROR);
$uri='api/v1/investigations/items/{investigationItem}/results'.($action==='store'?'':'/{investigationResult}/'.$action);
$path='api/v1/investigations/items/'.$argv[5].'/results'.($action==='store'?'':'/'.$argv[6].'/'.$action);
$request=Illuminate\Http\Request::create('/'.$path,'POST',$data); $request->headers->set('Idempotency-Key',$argv[8]);
$route=new Illuminate\Routing\Route('POST',$uri,fn()=>null); $route->name('api.v1.investigations.results.'.$action); $route->bind($request);
$request->setRouteResolver(fn()=>$route); $app->instance('request',$request);
$request->setUserResolver(fn()=>App\Models\User::findOrFail((int)$argv[4]));
if($argv[7]!=='hold') {echo "READY\n"; flush(); fgets(STDIN); echo "WRITING\n"; flush();}
try {
    $response=$app->make(App\Services\IdempotentCommands::class)->run($request,function() use($app,$request,$data,$argv) {
        $result=$app->make(App\Services\InvestigationResults::class)->execute($request,$request->user('web'),$data);
        if($argv[7]==='hold') {echo "LOCKED\n"; flush(); usleep(1000000);}
        return App\Services\InvestigationResults::response($result);
    });
    echo $response->headers->get('Idempotency-Replayed')==='true'?"replayed\n":"saved\n";
} catch(App\Exceptions\WriteConflict $e) {echo $e->errorCode."\n";}
PHP;
        $actor = match ($action) {
            'store' => $this->author,
            'verify' => $hold ? $this->reviewer : $this->otherReviewer,
            'release' => $this->reviewer,
            'amend' => $hold ? $this->author : $this->otherReviewer,
        };

        return new Process([PHP_BINARY, '-r', $code, base_path(), $action, json_encode($data, JSON_THROW_ON_ERROR),
            (string) $actor->id, (string) $this->item->id, (string) ($result['id'] ?? 0), $hold ? 'hold' : 'run', $key], base_path(),
            ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => 'easycare_test', 'DB_USERNAME' => 'easycare_test', 'DB_URL' => ''], timeout: 45);
    }

}
