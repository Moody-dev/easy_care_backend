<?php

namespace Tests\Feature;

use App\Models\DrugPack;
use App\Models\DrugPrice;
use App\Models\StockBalance;
use App\Models\StockMovement;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class StockConcurrencyTest extends TestCase
{
    use DatabaseTruncation {
        truncateDatabaseTables as private resetTables;
    }

    private bool $mysqlReady = false;

    protected function truncateDatabaseTables(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Stock locking requires the isolated MySQL database.');
        }
        $this->resetTables();
        $this->mysqlReady = true;
    }

    public function test_competing_issues_cannot_consume_the_same_stock_even_after_an_earlier_snapshot_read(): void
    {
        $opening = StockMovement::factory()->create(['quantity_micros' => 10000000]);
        $first = $this->movementData($opening, ['kind' => 'dispense', 'quantity_micros' => -7000000]);
        $second = $this->movementData($opening, ['kind' => 'dispense', 'quantity_micros' => -7000000]);
        $this->assertOneWinner($this->race('stock_movements', $first, $second));
        $this->assertSame(3000000, StockBalance::sole()->quantity_micros);
        $this->assertDatabaseCount('stock_movements', 2);
    }

    public function test_duplicate_command_keys_cannot_post_two_movements(): void
    {
        $opening = StockMovement::factory()->create();
        $data = $this->movementData($opening, ['kind' => 'receipt', 'quantity_micros' => 1000000]);
        $this->assertOneWinner($this->race('stock_movements', $data, $data));
        $this->assertSame(11000000, StockBalance::sole()->quantity_micros);
        $this->assertDatabaseCount('stock_movements', 2);
    }

    public function test_competing_reversals_do_not_reverse_the_same_entry_twice(): void
    {
        $opening = StockMovement::factory()->create();
        $first = $this->movementData($opening, ['kind' => 'reversal', 'quantity_micros' => -10000000, 'reversal_of' => $opening->id]);
        $second = $first;
        $second['movement_key'] = (string) Str::uuid();
        $this->assertOneWinner($this->race('stock_movements', $first, $second));
        $this->assertSame(0, StockBalance::sole()->quantity_micros);
        $this->assertDatabaseCount('stock_movements', 2);
    }

    public function test_competing_pack_versions_preserve_one_next_conversion(): void
    {
        $pack = DrugPack::factory()->create();
        $first = $pack->getAttributes();
        unset($first['id']);
        $first['version'] = 2;
        $first['base_quantity_micros'] = 12000000;
        $first['recorded_at'] = now('UTC')->addSecond()->format('Y-m-d H:i:s.u');
        $second = $first;
        $second['base_quantity_micros'] = 24000000;
        $this->assertOneWinner($this->race('drug_packs', $first, $second));
        $this->assertSame(12000000, DrugPack::where('version', 2)->sole()->base_quantity_micros);
        $this->assertDatabaseCount('drug_packs', 2);
    }

    public function test_competing_price_versions_preserve_one_next_price(): void
    {
        $price = DrugPrice::factory()->create();
        $first = $price->getAttributes();
        unset($first['id']);
        $first['version'] = 2;
        $first['amount_micros'] = 1000000;
        $first['recorded_at'] = now('UTC')->addSecond()->format('Y-m-d H:i:s.u');
        $first['effective_at'] = $first['recorded_at'];
        $second = $first;
        $second['amount_micros'] = 2000000;
        $this->assertOneWinner($this->race('drug_prices', $first, $second));
        $this->assertSame(1000000, DrugPrice::where('version', 2)->sole()->amount_micros);
        $this->assertDatabaseCount('drug_prices', 2);
    }

    /** @param array<string, mixed> $changes @return array<string, mixed> */
    private function movementData(StockMovement $source, array $changes): array
    {
        $data = $source->getAttributes();
        unset($data['id']);

        return $changes + ['movement_key' => (string) Str::uuid(), 'recorded_at' => now('UTC')->addSecond()->format('Y-m-d H:i:s.u')] + $data;
    }

    /** @param array{array<string, mixed>, array<string, mixed>} $outcomes */
    private function assertOneWinner(array $outcomes): void
    {
        $this->assertTrue($outcomes[0]['ok']);
        $this->assertFalse($outcomes[1]['ok']);
        $this->assertContains($outcomes[1]['state'], ['23000', '45000']);
    }

    /** @param array<string, mixed> $first @param array<string, mixed> $second @return array{array<string, mixed>, array<string, mixed>} */
    private function race(string $table, array $first, array $second): array
    {
        $input = new InputStream;
        $early = $this->worker($table, $first, true);
        $later = $this->worker($table, $second, false);
        $later->setInput($input);
        try {
            $later->start();
            $this->assertTrue($later->waitUntil(fn (string $type, string $output): bool => str_contains($output, 'READY')), $later->getErrorOutput());
            $early->start();
            $this->assertTrue($early->waitUntil(fn (string $type, string $output): bool => str_contains($output, 'LOCKED')), $early->getErrorOutput());
            $input->write("continue\n");
            $this->assertTrue($later->waitUntil(fn (string $type, string $output): bool => str_contains($output, 'WRITING')), $later->getErrorOutput());
            $early->wait();
            $later->wait();
            $outcomes = [];
            foreach ([$early, $later] as $process) {
                $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
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

    /** @param array<string, mixed> $data */
    private function worker(string $table, array $data, bool $hold): Process
    {
        $code = <<<'PHP'
require $argv[1].'/vendor/autoload.php';
$app = require $argv[1].'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (!$app->environment('testing') || $app->configurationIsCached() || config('database.default') !== 'mysql'
    || config('database.connections.mysql.database') !== 'easycare_test' || config('database.connections.mysql.username') !== 'easycare_test'
    || !in_array(config('database.connections.mysql.host'), ['localhost', '127.0.0.1', '::1'], true) || config('database.connections.mysql.url')) {
    throw new RuntimeException('Stock workers require the isolated local MySQL test database.');
}
if (!in_array($argv[2], ['stock_movements', 'drug_packs', 'drug_prices'], true)) { throw new RuntimeException('Unknown stock table.'); }
Illuminate\Support\Facades\DB::beginTransaction();
if ($argv[4] !== 'hold') {
    Illuminate\Support\Facades\DB::table($argv[2])->count();
    echo "READY\n"; flush(); fgets(STDIN); echo "WRITING\n"; flush();
}
try {
    $id = Illuminate\Support\Facades\DB::table($argv[2])->insertGetId(json_decode($argv[3], true, flags: JSON_THROW_ON_ERROR));
    if ($argv[4] === 'hold') { echo "LOCKED\n"; flush(); usleep(1200000); }
    Illuminate\Support\Facades\DB::commit();
    echo json_encode(['ok' => true, 'id' => $id], JSON_THROW_ON_ERROR)."\n";
} catch (Illuminate\Database\QueryException $error) {
    Illuminate\Support\Facades\DB::rollBack();
    echo json_encode(['ok' => false, 'state' => $error->errorInfo[0]], JSON_THROW_ON_ERROR)."\n";
}
PHP;

        return new Process([PHP_BINARY, '-r', $code, base_path(), $table, json_encode($data, JSON_THROW_ON_ERROR), $hold ? 'hold' : 'run'],
            base_path(), ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => 'easycare_test', 'DB_USERNAME' => 'easycare_test', 'DB_URL' => ''], timeout: 30);
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
