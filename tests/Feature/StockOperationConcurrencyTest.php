<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\StockBalance;
use App\Models\StockOperation;
use App\Models\StockStoreAccess;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;
use Tests\StockOperationsTestCase;

class StockOperationConcurrencyTest extends StockOperationsTestCase
{
    use DatabaseTruncation {
        truncateDatabaseTables as private resetTables;
    }

    private bool $mysqlReady = false;

    protected function truncateDatabaseTables(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Concurrent stock documents require the isolated MySQL database.');
        }
        $this->resetTables();
        $this->mysqlReady = true;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->stockFixtures();
    }

    public function test_duplicate_approval_retry_confirms_one_post_even_after_a_prior_snapshot(): void
    {
        $operation = $this->submitStock($this->draftStock($this->receiptData()));
        $job = $this->approval($operation);
        [$first,$second] = $this->race($job, $job);
        $this->assertSame(200, $first['status']);
        $this->assertSame(200, $second['status']);
        $this->assertSame($first['id'], $second['id']);
        $this->assertSame(10000000, StockBalance::sole()->quantity_micros);
        $this->assertDatabaseCount('stock_movements', 1);
        $this->assertSame(1, DB::table('audit_events')->where('action', 'stock.posted')->where('outcome', 'succeeded')->count());
    }

    public function test_competing_independent_reviewers_cannot_post_one_document_twice(): void
    {
        $operation = $this->submitStock($this->draftStock($this->receiptData()));
        StockStoreAccess::factory()->create(['user_id' => $this->outsider->id, 'store_id' => $this->mainStore->id]);
        $first = $this->approval($operation);
        $second = $this->approval($operation);
        $second['actor'] = $this->outsider->id;
        [$winner,$loser] = $this->race($first, $second);
        $this->assertSame(200, $winner['status']);
        $this->assertSame(409, $loser['status']);
        $this->assertDatabaseCount('stock_movements', 1);
        $this->assertSame($this->approver->id, StockOperation::findOrFail($operation['id'])->reviewed_by);
    }

    public function test_competing_transfers_cannot_consume_the_same_saved_source_quantity(): void
    {
        $this->postReceipt();
        $first = $this->submitStock($this->draftStock($this->outgoingData('transfer', '7')));
        $second = $this->submitStock($this->draftStock($this->outgoingData('transfer', '7')));
        [$winner,$loser] = $this->race($this->approval($first), $this->approval($second));
        $this->assertSame(200, $winner['status']);
        $this->assertSame(409, $loser['status']);
        $this->assertSame('stock_balance_changed', $loser['code']);
        $this->assertSame(3000000, StockBalance::where('store_id', $this->mainStore->id)->sole()->quantity_micros);
        $this->assertSame(7000000, StockBalance::where('store_id', $this->departmentStore->id)->sole()->quantity_micros);
        $this->assertDatabaseCount('stock_movements', 3);
    }

    public function test_competing_receipts_cannot_post_the_same_supplier_invoice_under_different_keys(): void
    {
        $first = $this->submitStock($this->draftStock($this->receiptData()));
        $second = $this->submitStock($this->draftStock($this->receiptData('synthetic-inv-1')));
        [$winner,$loser] = $this->race($this->approval($first), $this->approval($second));
        $this->assertSame(200, $winner['status']);
        $this->assertSame(409, $loser['status']);
        $this->assertSame('stock_invoice_posted', $loser['code']);
        $this->assertDatabaseCount('stock_invoice_receipts', 1);
        $this->assertDatabaseCount('stock_movements', 1);
    }

    public function test_store_access_revocation_prevents_a_waiting_approval_despite_its_earlier_snapshot(): void
    {
        $operation = $this->submitStock($this->draftStock($this->receiptData()));
        $revoke = ['action' => 'access', 'actor' => $this->preparer->id, 'operation' => null, 'key' => fake()->uuid(),
            'store' => $this->mainStore->id, 'staff' => $this->approver->id,
            'data' => ['version' => 1, 'is_active' => false, 'reason' => 'Synthetic explicit access withdrawal', 'current_password' => self::STAFF_TEST_PASSWORD]];
        [$first,$second] = $this->race($revoke, $this->approval($operation));
        $this->assertSame(200, $first['status']);
        $this->assertSame(404, $second['status']);
        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertSame('submitted', StockOperation::findOrFail($operation['id'])->status);
    }

    public function test_permission_revocation_prevents_a_waiting_approval_despite_its_earlier_snapshot(): void
    {
        $operation = $this->submitStock($this->draftStock($this->receiptData()));
        $revoke = ['action' => 'revoke', 'role' => Role::where('code', 'pharmacy')->sole()->id,
            'permission' => Permission::where('code', 'stock.approve')->sole()->id];
        [$first,$second] = $this->race($revoke, $this->approval($operation));
        $this->assertSame(200, $first['status']);
        $this->assertSame(403, $second['status']);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    /** @param array<string, mixed> $operation @return array<string, mixed> */
    private function approval(array $operation): array
    {
        return ['action' => 'approve', 'actor' => $this->approver->id, 'operation' => $operation['id'], 'key' => fake()->uuid(),
            'data' => ['version' => $operation['version'], 'reason' => 'Synthetic independent concurrent review', 'current_password' => self::STAFF_TEST_PASSWORD]];
    }

    /** @param array<string, mixed> $first @param array<string, mixed> $second @return array{array<string, mixed>, array<string, mixed>} */
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
            $this->assertTrue($later->waitUntil(fn (string $type, string $output): bool => str_contains($output, 'WRITING')), $later->getErrorOutput());
            $early->wait();
            $later->wait();
            $outcomes = [];
            foreach ([$early,$later] as $process) {
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

    /** @param array<string, mixed> $job */
    private function worker(array $job, bool $hold): Process
    {
        $code = <<<'PHP'
require $argv[1].'/vendor/autoload.php';
$app = require $argv[1].'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (!$app->environment('testing') || $app->configurationIsCached() || config('database.default') !== 'mysql'
    || config('database.connections.mysql.database') !== 'easycare_test' || config('database.connections.mysql.username') !== 'easycare_test'
    || !in_array(config('database.connections.mysql.host'), ['localhost','127.0.0.1','::1'], true) || config('database.connections.mysql.url')) {
    throw new RuntimeException('Stock workers require the isolated local MySQL test database.');
}
$job = json_decode($argv[2], true, flags: JSON_THROW_ON_ERROR);
Illuminate\Support\Facades\DB::beginTransaction();
if ($argv[3] !== 'hold') {
    Illuminate\Support\Facades\DB::table('stock_operations')->count();
    echo "READY\n"; flush(); fgets(STDIN); echo "WRITING\n"; flush();
}
try {
    if ($job['action'] === 'revoke') {
        Illuminate\Support\Facades\DB::table('permissions')->where('code', 'staff.manage')->lockForUpdate()->first();
        Illuminate\Support\Facades\DB::table('role_permissions')->where('role_id', $job['role'])->where('permission_id', $job['permission'])->delete();
        $outcome = ['status' => 200];
    } else {
        $actor = App\Models\User::findOrFail($job['actor']);
        $access = $job['action'] === 'access';
        $path = $access ? '/api/v1/stock/stores/'.$job['store'].'/access/'.$job['staff']
            : '/api/v1/stock/operations/'.$job['operation'].'/approve';
        $request = Illuminate\Http\Request::create($path, 'POST', $job['data']);
        $request->headers->set('Idempotency-Key', $job['key']);
        $request->setUserResolver(fn () => $actor);
        $route = new Illuminate\Routing\Route('POST', ltrim($path, '/'), fn () => null);
        $route->name('api.v1.stock.'.$job['action']);
        $route->bind($request);
        if ($access) {
            $route->setParameter('stockStore', (string) $job['store']);
            $route->setParameter('stockStaff', (string) $job['staff']);
        } else {
            $route->setParameter('stockOperation', (string) $job['operation']);
        }
        $request->setRouteResolver(fn () => $route);
        $app->instance('request', $request);
        Illuminate\Support\Facades\Auth::guard('web')->setUser($actor);
        $request->setUserResolver(fn () => $actor);
        $response = $app->make(App\Services\IdempotentCommands::class)->run($request, function () use ($app, $request, $access) {
            $actor = $request->attributes->get('stock_actor');
            if ($access) {
                $record = $app->make(App\Services\StockCatalog::class)->execute($request, $actor, $app->make(App\Http\Requests\Api\V1\StockCatalogCommandRequest::class)->validated());
                return App\Services\StockCatalog::response('store-access', $record);
            }
            $operation = $app->make(App\Services\StockOperations::class)->execute($request, $actor, $app->make(App\Http\Requests\Api\V1\StockOperationCommandRequest::class)->validated());
            return App\Services\StockOperations::response($operation);
        });
        $outcome = ['status' => $response->getStatusCode(), 'id' => $response->getData(true)['data']['id']];
    }
    if ($argv[3] === 'hold') { echo "LOCKED\n"; flush(); usleep(1200000); }
    Illuminate\Support\Facades\DB::commit();
    echo json_encode($outcome, JSON_THROW_ON_ERROR)."\n";
} catch (Illuminate\Auth\Access\AuthorizationException $exception) {
    Illuminate\Support\Facades\DB::rollBack();
    echo json_encode(['status' => 403], JSON_THROW_ON_ERROR)."\n";
} catch (Illuminate\Database\Eloquent\ModelNotFoundException $exception) {
    Illuminate\Support\Facades\DB::rollBack();
    echo json_encode(['status' => 404], JSON_THROW_ON_ERROR)."\n";
} catch (Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $exception) {
    Illuminate\Support\Facades\DB::rollBack();
    echo json_encode(['status' => $exception->getStatusCode(), 'code' => $exception instanceof App\Exceptions\WriteConflict ? $exception->errorCode : 'conflict'], JSON_THROW_ON_ERROR)."\n";
}
PHP;

        return new Process([PHP_BINARY, '-r', $code, base_path(), json_encode($job, JSON_THROW_ON_ERROR), $hold ? 'hold' : 'run'], base_path(),
            ['APP_ENV' => 'testing','DB_CONNECTION' => 'mysql','DB_DATABASE' => 'easycare_test','DB_USERNAME' => 'easycare_test','DB_URL' => ''], timeout: 40);
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
