<?php

namespace Tests\Feature;

use App\Models\StockBalance;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;
use Tests\MedicationFulfillmentTestCase;

class MedicationFulfillmentConcurrencyTest extends MedicationFulfillmentTestCase
{
    use DatabaseTruncation {
        truncateDatabaseTables as private resetTables;
    }

    private bool $mysqlReady = false;

    protected function truncateDatabaseTables(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Concurrent dispensing requires the isolated MySQL database.');
        }
        $this->resetTables();
        $this->mysqlReady = true;
    }

    private function issueJob(array $authorization): array
    {
        return ['actor' => $this->preparer->id, 'authorization' => $authorization['id'], 'action' => 'dispense', 'key' => fake()->uuid(),
            'data' => ['encounter_version' => 1, 'version' => $authorization['version'], 'quantity' => '4', 'batch_id' => $this->batch->id,
                'store_id' => $this->mainStore->id, 'store_version' => 1, 'drug_version' => 1, 'balance_version' => 1,
                'current_password' => self::STAFF_TEST_PASSWORD, 'reason' => 'Synthetic competing medication issue']];
    }

    public function test_duplicate_issue_after_an_earlier_snapshot_confirms_one_stock_deduction(): void
    {
        $authorization = $this->authorizeLine()->assertCreated()->json('data');
        $job = $this->issueJob($authorization);
        [$first, $second] = $this->race($job, $job);
        $this->assertSame(201, $first['status']);
        $this->assertSame(200, $second['status']);
        $this->assertSame($first['id'], $second['id']);
        $this->assertDatabaseCount('medication_dispenses', 1);
        $this->assertDatabaseCount('stock_movements', 2);
        $this->assertSame(6000000, StockBalance::sole()->quantity_micros);
    }

    public function test_competing_issues_cannot_reset_the_prescription_allowance_after_an_earlier_snapshot(): void
    {
        $authorization = $this->authorizeLine()->assertCreated()->json('data');
        $first = $this->issueJob($authorization);
        $second = $this->issueJob($authorization);
        $second['actor'] = $this->approver->id;
        [$winner, $loser] = $this->race($first, $second);
        $this->assertSame(201, $winner['status']);
        $this->assertSame(409, $loser['status']);
        $this->assertDatabaseCount('medication_dispenses', 1);
        $this->assertSame(6000000, StockBalance::sole()->quantity_micros);
    }

    public function test_waiting_return_request_cannot_return_a_quantity_just_administered(): void
    {
        $issued = $this->issue($this->authorizeLine()->assertCreated()->json('data'))->assertCreated()->json('data');
        $base = ['authorization' => $issued['id'], 'data' => ['encounter_version' => 1, 'version' => $issued['version'],
            'dispense_id' => $issued['last_record_id'], 'quantity' => '4', 'reason' => 'Synthetic competing physical disposition', 'current_password' => self::STAFF_TEST_PASSWORD]];
        $first = $base + ['actor' => $this->nurse->id, 'action' => 'administer', 'key' => fake()->uuid()];
        $first['data']['observed_at'] = now('UTC')->toISOString();
        $second = $base + ['actor' => $this->preparer->id, 'action' => 'return-request', 'key' => fake()->uuid()];
        $second['data'] += ['disposition' => 'usable', 'usable_confirmed' => true];
        [$winner, $loser] = $this->race($first, $second);
        $this->assertSame(201, $winner['status']);
        $this->assertSame(409, $loser['status']);
        $this->assertDatabaseCount('medication_administrations', 1);
        $this->assertDatabaseCount('medication_returns', 0);
        $this->assertDatabaseCount('stock_movements', 2);
    }

    public function test_two_reviewers_cannot_restore_the_same_return_twice(): void
    {
        $issued = $this->issue($this->authorizeLine()->assertCreated()->json('data'))->assertCreated()->json('data');
        $pending = $this->command($issued, 'return-request', ['dispense_id' => $issued['last_record_id'], 'quantity' => '2',
            'disposition' => 'usable', 'usable_confirmed' => true])->assertCreated()->json('data');
        $job = ['authorization' => $issued['id'], 'action' => 'return-approve', 'actor' => $this->approver->id, 'key' => fake()->uuid(),
            'data' => ['version' => $pending['version'], 'encounter_version' => 1, 'dispense_id' => $issued['last_record_id'],
                'return_id' => $pending['last_record_id'], 'reason' => 'Synthetic independent return review', 'current_password' => self::STAFF_TEST_PASSWORD]];
        $second = $job;
        $second['key'] = fake()->uuid();
        \App\Models\StockStoreAccess::factory()->create(['user_id' => $this->outsider->id, 'store_id' => $this->mainStore->id]);
        $second['actor'] = $this->outsider->id;
        [$winner, $loser] = $this->race($job, $second);
        $this->assertSame(201, $winner['status']);
        $this->assertSame(409, $loser['status']);
        $this->assertDatabaseCount('medication_return_reviews', 1);
        $this->assertDatabaseCount('stock_movements', 3);
        $this->assertSame(8000000, StockBalance::sole()->quantity_micros);
    }

    public function test_different_prescriptions_cannot_issue_the_same_remaining_batch_stock(): void
    {
        $first = $this->authorizeLine(['maximum_quantity' => '8'])->assertCreated()->json('data');
        $this->stockLogin($this->doctor);
        $path = '/api/v1/encounters/'.$this->visit->id.'/prescriptions';
        $draft = $this->postCommand($path, ['encounter_version' => 1, 'payload' => ['items' => [[
            'medication_name' => 'Second synthetic medication order', 'dose' => 'Staff entered', 'route' => 'Staff entered', 'frequency' => 'Staff entered']]]])->assertCreated()->json('data');
        $this->postCommand($path.'/'.$draft['id'].'/sign', ['encounter_version' => 1, 'version' => 1, 'current_password' => self::STAFF_TEST_PASSWORD])->assertOk();
        $this->item = \App\Models\PrescriptionItem::where('prescription_id', $draft['id'])->sole();
        $second = $this->authorizeLine(['maximum_quantity' => '8'])->assertCreated()->json('data');
        $early = $this->issueJob($first);
        $later = $this->issueJob($second);
        $early['data']['quantity'] = '7';
        $later['data']['quantity'] = '7';
        $later['actor'] = $this->approver->id;
        [$winner, $loser] = $this->race($early, $later);
        $this->assertSame(201, $winner['status']);
        $this->assertSame(409, $loser['status']);
        $this->assertDatabaseCount('medication_dispenses', 1);
        $this->assertSame(3000000, StockBalance::sole()->quantity_micros);
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
    throw new RuntimeException('Medication workers require the isolated local MySQL test database.');
}
$job = json_decode($argv[2], true, flags: JSON_THROW_ON_ERROR);
Illuminate\Support\Facades\DB::beginTransaction();
if ($argv[3] !== 'hold') {
    Illuminate\Support\Facades\DB::table('medication_events')->count();
    echo "READY\n"; flush(); fgets(STDIN);
}
try {
    $actor = App\Models\User::findOrFail($job['actor']);
    $path = '/api/v1/medications/'.$job['authorization'].'/'.$job['action'];
    $request = Illuminate\Http\Request::create($path, 'POST', $job['data']);
    $request->headers->set('Idempotency-Key', $job['key']);
    $route = new Illuminate\Routing\Route('POST', ltrim($path, '/'), fn () => null);
    $route->name('api.v1.medications.'.$job['action']);
    $route->bind($request);
    $route->setParameter('medication', (string) $job['authorization']);
    $request->setRouteResolver(fn () => $route);
    $app->instance('request', $request);
    Illuminate\Support\Facades\Auth::guard('web')->setUser($actor);
    $request->setUserResolver(fn () => $actor);
    $response = $app->make(App\Services\IdempotentCommands::class)->run($request, function () use ($app, $request, $actor) {
        $service = $app->make(App\Services\MedicationFulfillment::class);
        $authorization = $service->execute($request, $actor, $app->make(App\Http\Requests\Api\V1\MedicationCommandRequest::class)->validated());
        return $service->response($authorization)->setStatusCode(201);
    });
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
        try {
            if ($this->mysqlReady) {
                $this->truncateTablesForAllConnections();
            }
        } finally {
            parent::tearDown();
        }
    }
}
