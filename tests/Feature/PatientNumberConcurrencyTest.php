<?php

namespace Tests\Feature;

use App\Models\PatientCategory;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;
use Tests\CommittedDatabase;
use Tests\TestCase;

class PatientNumberConcurrencyTest extends TestCase
{
    use CommittedDatabase;

    protected function setUp(): void
    {
        if (! in_array($_ENV['DB_CONNECTION'] ?? getenv('DB_CONNECTION'), ['mysql', 'pgsql'], true)) {
            $this->markTestSkipped('Requires an isolated MySQL or PostgreSQL server.');
        }
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->grantRolePermissions('frontdesk', ['patients.view', 'patients.create']);
    }

    public function test_parallel_distinct_registrations_reserve_different_numbers(): void
    {
        $first = $this->createStaffWithRole('frontdesk');
        $second = $this->createStaffWithRole('frontdesk');
        $results = $this->race($first, $second, false);
        $this->assertSame(['PVT-01', 'PVT-02'], array_column($results, 'mrn'));
        $this->assertDatabaseCount('patient_number_reservations', 2);
        $this->assertDatabaseCount('patients', 0);
        $this->assertSame(2, (int) DB::table('patient_number_sequences')->where('prefix', 'PVT')->value('last_value'));
    }

    public function test_parallel_same_submission_replays_one_number_and_token(): void
    {
        $actor = $this->createStaffWithRole('frontdesk');
        $results = $this->race($actor, $actor, true);
        $this->assertSame([201, 200], array_column($results, 'status'));
        $this->assertSame($results[0]['id'], $results[1]['id']);
        $this->assertSame($results[0]['token_hash'], $results[1]['token_hash']);
        $this->assertDatabaseCount('patient_number_reservations', 1);
        $this->assertSame(1, (int) DB::table('patient_number_sequences')->where('prefix', 'PVT')->value('last_value'));
    }

    private function race(User $firstActor, User $secondActor, bool $sameKey): array
    {
        $key = (string) Str::uuid();
        $category = PatientCategory::where('code', 'private')->sole()->id;
        $input = new InputStream;
        $first = $this->worker($firstActor, $category, $key, true)->setInput($input);
        $second = $this->worker($secondActor, $category, $sameKey ? $key : (string) Str::uuid(), false);
        try {
            $first->start();
            $this->assertTrue($first->waitUntil(fn (string $type, string $output): bool => str_contains($output, 'LOCKED')), $first->getErrorOutput());
            $second->start();
            $this->assertTrue($second->waitUntil(fn (string $type, string $output): bool => str_contains($output, 'READY')), $second->getErrorOutput());
            $input->write("continue\n");
            $input->close();
            $first->wait();
            $second->wait();
            $this->assertTrue($first->isSuccessful(), $first->getErrorOutput());
            $this->assertTrue($second->isSuccessful(), $second->getErrorOutput());
            $outcomes = [];
            foreach ([$first, $second] as $process) {
                $lines = explode("\n", trim($process->getOutput()));
                $outcomes[] = json_decode(end($lines), true, flags: JSON_THROW_ON_ERROR);
            }

            return $outcomes;
        } finally {
            $first->stop(0);
            $second->stop(0);
        }
    }

    private function worker(User $actor, int $category, string $key, bool $hold): Process
    {
        $code = <<<'PHP'
require $argv[1].'/vendor/autoload.php';
$app = require $argv[1].'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$driver = config('database.default');
$settings = config('database.connections.'.$driver);
if (!$app->environment('testing') || $app->configurationIsCached() || !in_array($driver, ['mysql','pgsql'], true)
    || $settings['database'] !== ($driver === 'pgsql' ? 'easycare_pg_test' : 'easycare_test')
    || $settings['username'] !== 'easycare_test' || !in_array($settings['host'], ['127.0.0.1','localhost','::1'], true)
    || !empty($settings['url']) || ($driver === 'pgsql' && (int) $settings['port'] !== 55432)) {
    throw new RuntimeException('Parallel numbering workers require an isolated test database.');
}
$actor = App\Models\User::query()->findOrFail((int) $argv[2]);
echo "READY\n"; flush();
Illuminate\Support\Facades\DB::beginTransaction();
if ($argv[5] === 'hold') {
    App\Models\Permission::where('code','staff.manage')->lockForUpdate()->firstOrFail();
    echo "LOCKED\n"; flush();
    if (trim(fgets(STDIN)) !== 'continue') { throw new RuntimeException('Missing concurrency barrier.'); }
}
$response = $app->make(App\Services\PatientNumbers::class)->reserve($actor, (int) $argv[3], $argv[4]);
Illuminate\Support\Facades\DB::commit();
$data = $response->getData(true)['data'];
echo json_encode(['status'=>$response->getStatusCode(),'id'=>$data['id'],'mrn'=>$data['mrn'],
    'token_hash'=>hash('sha256',$data['number_reservation_token'])])."\n";
PHP;
        $driver = DB::getDriverName();
        $settings = config('database.connections.'.$driver);

        return new Process([PHP_BINARY, '-r', $code, base_path(), (string) $actor->id, (string) $category, $key, $hold ? 'hold' : 'run'],
            base_path(), ['APP_ENV' => 'testing', 'APP_KEY' => config('app.key'), 'DB_CONNECTION' => $driver,
                'DB_HOST' => $settings['host'], 'DB_PORT' => (string) $settings['port'], 'DB_DATABASE' => $settings['database'],
                'DB_USERNAME' => $settings['username'], 'DB_PASSWORD' => $settings['password'], 'DB_URL' => '',
                'DB_SCHEMA' => 'easycare', 'DB_SSLMODE' => 'disable', 'CACHE_STORE' => 'array'], timeout: 30);
    }
}
