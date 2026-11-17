<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;
use Tests\BillingTestCase;

class BillingConcurrencyTest extends BillingTestCase
{
    use DatabaseTruncation { truncateDatabaseTables as private resetTables; }

    private bool $mysqlReady = false;

    protected function truncateDatabaseTables(): void
    {
        if (DB::getDriverName() !== 'mysql') { $this->markTestSkipped('Concurrent financial commands require isolated MySQL.'); }
        $this->resetTables();
        $this->mysqlReady = true;
    }

    private function job(int $version, string $action, array $data): array
    {
        return ['patient' => $this->visit->patient_id, 'actor' => $this->cashier->id, 'action' => $action, 'key' => fake()->uuid(),
            'data' => ['account_version' => $version, 'reason' => 'Synthetic competing financial command', 'current_password' => self::STAFF_TEST_PASSWORD] + $data];
    }

    public function test_duplicate_invoice_confirms_one_charge_after_an_earlier_snapshot(): void
    {
        $source = $this->performed();
        $job = $this->job($source['version'], 'invoice', ['encounter_id' => $this->visit->id, 'encounter_version' => 1,
            'self_pay_confirmed' => true, 'sources' => [['type' => 'performed', 'id' => $source['last_record_id'], 'price_id' => $this->price->id]]]);
        [$first, $second] = $this->race($job, $job);
        $this->assertSame(201, $first['status']);
        $this->assertSame(200, $second['status']);
        $this->assertDatabaseCount('billing_invoices', 1);
        $this->assertDatabaseCount('billing_invoice_items', 1);
    }

    public function test_two_cashiers_cannot_allocate_the_same_receipt_credit_twice(): void
    {
        $invoice = $this->invoice($this->performed())->assertCreated()->json('data');
        $receipt = $this->receipt($invoice['version'], '200.00')->assertCreated()->json('data');
        $first = $this->job($receipt['version'], 'allocate', ['receipt_id' => $receipt['last_record_id'], 'invoice_id' => $invoice['last_record_id'], 'amount' => '150.00']);
        $second = $first;
        $second['actor'] = $this->reviewer->id;
        $second['key'] = fake()->uuid();
        [$winner, $loser] = $this->race($first, $second);
        $this->assertSame(201, $winner['status']);
        $this->assertSame(409, $loser['status']);
        $this->assertDatabaseCount('billing_allocations', 1);
        $this->writeBill($receipt['version'] + 1, '/allocations', $first['data'] + [])->assertConflict();
        $this->assertSame(15000, (int) DB::table('billing_allocations')->sum('amount_kobo'));
    }

    public function test_pending_refund_reservation_prevents_a_waiting_allocation_using_old_credit(): void
    {
        $invoice = $this->invoice($this->performed())->assertCreated()->json('data');
        $receipt = $this->receipt($invoice['version'], '100.00')->assertCreated()->json('data');
        $first = $this->job($receipt['version'], 'refund-request', ['receipt_id' => $receipt['last_record_id'], 'amount' => '80.00']);
        $second = $this->job($receipt['version'], 'allocate', ['receipt_id' => $receipt['last_record_id'], 'invoice_id' => $invoice['last_record_id'], 'amount' => '50.00']);
        [$winner, $loser] = $this->race($first, $second);
        $this->assertSame(201, $winner['status']);
        $this->assertSame(409, $loser['status']);
        $this->writeBill($receipt['version'] + 1, '/allocations', ['receipt_id' => $receipt['last_record_id'], 'invoice_id' => $invoice['last_record_id'], 'amount' => '50.00'])->assertConflict();
        $this->assertDatabaseCount('billing_allocations', 0);
        $this->assertDatabaseCount('billing_changes', 1);
    }

    public function test_duplicate_external_payment_reference_cannot_be_recorded_for_different_patients(): void
    {
        $first = $this->job(0, 'receipt', ['amount' => '25.00', 'kind' => 'payment', 'method' => 'pos', 'reference' => 'SYN-POS-SHARED',
            'payer_name' => 'Synthetic payer', 'paid_at' => now('UTC')->toISOString(), 'externally_confirmed' => true,
            'transaction_details' => ['provider' => 'Synthetic provider', 'terminal_id' => 'Synthetic terminal']]);
        $second = $first;
        $second['patient'] = \App\Models\Patient::factory()->create()->id;
        $second['actor'] = $this->reviewer->id;
        $second['key'] = fake()->uuid();
        [$winner, $loser] = $this->race($first, $second);
        $this->assertSame(201, $winner['status']);
        $this->assertSame(409, $loser['status']);
        $this->assertDatabaseCount('billing_receipts', 1);
        $this->assertDatabaseCount('billing_accounts', 1);
    }

    public function test_independent_reviewers_cannot_record_the_same_physical_refund_twice(): void
    {
        $receipt = $this->receipt(0, '100.00')->assertCreated()->json('data');
        $change = $this->writeBill($receipt['version'], '/refunds', ['receipt_id' => $receipt['last_record_id'], 'amount' => '50.00'])->assertCreated()->json('data');
        $first = $this->job($change['version'], 'approve', ['payout_confirmed' => true, 'payout_reference' => 'SYN-REFUND-VOUCHER', 'payout_at' => now('UTC')->toISOString(),
            'payout_method' => 'cash', 'payee_name' => 'Synthetic refund recipient', 'payout_details' => []]);
        $first['change'] = $change['last_record_id'];
        $first['actor'] = $this->reviewer->id;
        $second = $first;
        $second['actor'] = $this->createStaffWithRole('frontdesk')->id;
        $second['key'] = fake()->uuid();
        [$winner, $loser] = $this->race($first, $second);
        $this->assertSame(201, $winner['status']);
        $this->assertSame(409, $loser['status']);
        $this->assertDatabaseCount('billing_change_reviews', 1);
        $this->assertSame(5000, (int) DB::table('billing_changes')->sum('amount_kobo'));
    }

    public function test_approved_credit_prevents_a_waiting_allocation_from_using_the_previous_invoice_balance(): void
    {
        $invoice = $this->invoice($this->performed())->assertCreated()->json('data');
        $receipt = $this->receipt($invoice['version'], '200.00')->assertCreated()->json('data');
        $item = DB::table('billing_invoice_items')->sole();
        $change = $this->writeBill($receipt['version'], '/credits', ['item_id' => $item->id, 'amount' => '100.00'])->assertCreated()->json('data');
        $first = $this->job($change['version'], 'approve', []);
        $first['change'] = $change['last_record_id'];
        $first['actor'] = $this->reviewer->id;
        $second = $this->job($change['version'], 'allocate', ['receipt_id' => $receipt['last_record_id'], 'invoice_id' => $invoice['last_record_id'], 'amount' => '200.00']);
        [$winner, $loser] = $this->race($first, $second);
        $this->assertSame(201, $winner['status']);
        $this->assertSame(409, $loser['status']);
        $this->writeBill($change['version'] + 1, '/allocations', ['receipt_id' => $receipt['last_record_id'], 'invoice_id' => $invoice['last_record_id'], 'amount' => '200.00'])->assertConflict();
        $this->assertDatabaseCount('billing_allocations', 0);
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
    $paths = ['invoice' => 'invoices', 'allocate' => 'allocations', 'receipt' => 'receipts', 'refund-request' => 'refunds',
        'approve' => 'changes/'.($job['change'] ?? '').'/approve'];
    $path = '/api/v1/billing/patients/'.$job['patient'].'/'.$paths[$job['action']];
    $request = Illuminate\Http\Request::create($path, 'POST', $job['data']);
    $request->headers->set('Idempotency-Key', $job['key']);
    $route = new Illuminate\Routing\Route('POST', ltrim($path, '/'), fn () => null);
    $route->name('api.v1.billing.'.$job['action']);
    $route->bind($request);
    $route->setParameter('patient', (string) $job['patient']);
    if (isset($job['change'])) { $route->setParameter('billingChange', (string) $job['change']); }
    $request->setRouteResolver(fn () => $route);
    $app->instance('request', $request);
    Illuminate\Support\Facades\Auth::guard('web')->setUser($actor);
    $request->setUserResolver(fn () => $actor);
    $response = $app->make(App\Services\IdempotentCommands::class)->run($request, fn () =>
        $app->make(App\Services\BillingWorkflows::class)->execute($request, $actor,
            $app->make(App\Http\Requests\Api\V1\BillingCommandRequest::class)->validated()));
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
