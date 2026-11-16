<?php

namespace Tests\Feature;

use App\Models\InvestigationSchema;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class InvestigationConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Competing catalog publications require the isolated MySQL database.');
        }
        $this->seed(DatabaseSeeder::class);
        $this->grantRolePermissions('diagnostics-and-radiologist', ['investigations.catalog.publish']);
    }

    public function test_competing_different_definitions_cannot_publish_the_same_expected_version(): void
    {
        [$first, $second] = $this->race(false);
        $this->assertTrue($first['ok']);
        $this->assertFalse($second['ok']);
        $this->assertDatabaseCount('investigation_tests', 1);
        $this->assertDatabaseCount('services', 1);
        $this->assertDatabaseCount('investigation_schemas', 1);
        $this->assertSame('Synthetic first title', InvestigationSchema::sole()->schema_json['title']);
        $this->assertSame(1, DB::table('audit_events')->where('action', 'investigation.published')->count());
    }

    public function test_competing_unchanged_retry_confirms_one_schema_and_one_publication_event(): void
    {
        [$first, $second] = $this->race(true);
        $this->assertTrue($first['ok']);
        $this->assertTrue($second['ok']);
        $this->assertSame($first['id'], $second['id']);
        $this->assertDatabaseCount('investigation_schemas', 1);
        $this->assertSame(1, DB::table('audit_events')->where('action', 'investigation.published')->count());
    }

    /** @return array{array<string, mixed>, array<string, mixed>} */
    private function race(bool $same): array
    {
        $firstActor = $this->createStaffWithRole('diagnostics-and-radiologist');
        $otherActor = $same ? $firstActor : $this->createStaffWithRole('diagnostics-and-radiologist');
        $data = ['code' => 'synthetic-race', 'name' => 'Synthetic race test', 'department' => 'laboratory', 'specimen' => null,
            'is_active' => true, 'title' => 'Synthetic first title', 'service' => ['code' => 'synthetic-race-service', 'name' => 'Synthetic service'],
            'fields' => ['observation' => ['type' => 'text']]];
        $other = $data;
        if (! $same) {
            $other['title'] = 'Synthetic competing title';
        }
        $input = new InputStream;
        $early = $this->worker($data, $firstActor->id, true);
        $later = $this->worker($other, $otherActor->id, false);
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
            $this->assertTrue($early->isSuccessful(), $early->getErrorOutput());
            $this->assertTrue($later->isSuccessful(), $later->getErrorOutput());
            $outcomes = [];
            foreach ([$early, $later] as $process) {
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
    private function worker(array $data, int $actor, bool $hold): Process
    {
        $code = <<<'PHP'
require $argv[1].'/vendor/autoload.php';
$app = require $argv[1].'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (!$app->environment('testing') || $app->configurationIsCached() || config('database.default') !== 'mysql'
    || config('database.connections.mysql.database') !== 'easycare_test' || config('database.connections.mysql.username') !== 'easycare_test'
    || !in_array(config('database.connections.mysql.host'), ['localhost', '127.0.0.1', '::1'], true) || config('database.connections.mysql.url')) {
    throw new RuntimeException('Catalog workers require the isolated local MySQL test database.');
}
if ($argv[4] !== 'hold') { echo "READY\n"; flush(); fgets(STDIN); echo "WRITING\n"; flush(); }
try {
    $schema = Illuminate\Support\Facades\DB::transaction(function () use ($app, $argv) {
        $record = $app->make(App\Services\InvestigationCatalog::class)->publish(json_decode($argv[2], true, flags: JSON_THROW_ON_ERROR),
            (int)$argv[3], 0, 'Synthetic approval', 'Synthetic competing publication');
        if ($argv[4] === 'hold') { echo "LOCKED\n"; flush(); usleep(1200000); }
        return $record;
    });
    echo json_encode(['ok' => true, 'id' => $schema->id], JSON_THROW_ON_ERROR)."\n";
} catch (RuntimeException) {
    echo json_encode(['ok' => false], JSON_THROW_ON_ERROR)."\n";
}
PHP;

        return new Process([PHP_BINARY, '-r', $code, base_path(), json_encode($data, JSON_THROW_ON_ERROR), (string) $actor, $hold ? 'hold' : 'run'],
            base_path(), ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => 'easycare_test', 'DB_USERNAME' => 'easycare_test', 'DB_URL' => ''], timeout: 30);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        RefreshDatabaseState::$migrated = false;
    }
}
