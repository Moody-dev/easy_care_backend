<?php

namespace Tests\Feature;

use App\Models\Admission;
use App\Models\AdmissionRevision;
use App\Models\Bed;
use App\Models\BedAllocation;
use App\Models\User;
use App\Services\AdmissionFlow;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;
use Tests\CommittedDatabase;
use Tests\TestCase;

class AdmissionApiConcurrencyTest extends TestCase
{
    use CommittedDatabase;

    private User $nurse;
    private User $otherNurse;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Competing API commands require the isolated MySQL database.');
        }
        $this->travelTo(now('UTC')->setDate(2026, 11, 14)->setTime(10, 0));
        $this->seed(DatabaseSeeder::class);
        $this->grantRolePermissions('nurse', ['patients.view', 'encounters.view', 'encounters.view_any', 'admissions.view',
            'locations.view', 'admissions.allocate', 'admissions.complete_discharge']);
        $this->nurse = $this->createStaffWithRole('nurse');
        $this->otherNurse = $this->createStaffWithRole('nurse');
    }

    public function test_competing_admissions_cannot_claim_the_same_available_bed_through_command_transactions(): void
    {
        $first = Admission::factory()->create();
        $second = Admission::factory()->create();
        $bed = Bed::factory()->create();
        $results = $this->race($first, 'allocate', $this->bedData($bed), $second, 'allocate', $this->bedData($bed));
        $this->assertSame(['saved', 'bed_unavailable'], $results);
        $this->assertDatabaseCount('current_bed_occupancies', 1);
        $this->assertSame($first->id, BedAllocation::current()->sole()->admission_id);
        $this->assertDatabaseCount('idempotency_requests', 1);
        $this->assertDatabaseCount('admission_revisions', 1);
    }

    public function test_same_actor_and_submission_replay_after_competing_commit_without_duplicate_allocation_or_history(): void
    {
        $admission = Admission::factory()->create();
        $bed = Bed::factory()->create();
        $this->otherNurse = $this->nurse;
        $this->assertSame(['saved', 'replayed'], $this->race($admission, 'allocate', $this->bedData($bed),
            $admission, 'allocate', $this->bedData($bed), sameKey: true));
        $this->assertDatabaseCount('bed_allocations', 1);
        $this->assertDatabaseCount('admission_revisions', 1);
        $this->assertDatabaseCount('idempotency_requests', 1);
    }

    public function test_transfer_committing_first_keeps_new_occupancy_and_rejects_stale_discharge_completion(): void
    {
        [$admission, $bed] = $this->authorized();
        $this->assertSame(['saved', 'version_conflict'], $this->race($admission, 'transfer', $this->bedData($bed),
            $admission, 'complete-discharge', []));
        $this->assertSame('discharge_authorized', $admission->fresh()->status);
        $this->assertSame($bed->id, BedAllocation::current()->sole()->bed_id);
        $this->assertDatabaseCount('admission_revisions', 2);
    }

    public function test_completion_committing_first_releases_bed_and_prevents_a_stale_transfer(): void
    {
        [$admission, $bed] = $this->authorized();
        $this->assertSame(['saved', 'version_conflict'], $this->race($admission, 'complete-discharge', [],
            $admission, 'transfer', $this->bedData($bed)));
        $this->assertSame('discharged', $admission->fresh()->status);
        $this->assertDatabaseCount('current_bed_occupancies', 0);
        $this->assertDatabaseCount('bed_allocations', 1);
        $this->assertDatabaseCount('admission_revisions', 2);
    }

    private function authorized(): array
    {
        $doctor = $this->createStaffWithRole('doctor');
        $admission = Admission::factory()->create();
        BedAllocation::factory()->create(['admission_id' => $admission->id]);
        $admission->forceFill(['status' => 'discharge_authorized', 'authorized_by' => $doctor->id,
            'authorized_at' => now('UTC'), 'discharge_reason' => 'Synthetic discharge authorization', 'version' => 2])->save();
        AdmissionRevision::factory()->create(['admission_id' => $admission->id, 'operation' => 'discharge_authorized',
            'actor_id' => $doctor->id]);

        return [$admission, Bed::factory()->create()];
    }

    private function bedData(Bed $bed): array
    {
        return ['bed_id' => $bed->id, 'bed_version' => 1, 'ward_version' => 1];
    }

    private function commandData(Admission $admission, string $action, array $extra): array
    {
        $snapshot = AdmissionFlow::snapshot($admission);
        $data = ['version' => $snapshot['version'], 'workflow_version' => $snapshot['workflow_version'],
            'encounter_version' => $snapshot['encounter_version'], 'reason' => 'Synthetic competing command'];
        if (in_array($action, ['transfer', 'complete-discharge'], true)) {
            $data += ['allocation_id' => $snapshot['current_allocation']['id'], 'allocation_version' => $snapshot['current_allocation']['version']];
        }

        return $data + $extra;
    }

    private function race(Admission $first, string $firstAction, array $firstExtra, Admission $second,
        string $secondAction, array $secondExtra, bool $sameKey = false): array
    {
        $input = new InputStream;
        $early = $this->worker($first, $firstAction, $this->commandData($first, $firstAction, $firstExtra), $this->nurse, true, 'parallel-command-first');
        $later = $this->worker($second, $secondAction, $this->commandData($second, $secondAction, $secondExtra), $this->otherNurse, false,
            $sameKey ? 'parallel-command-first' : 'parallel-command-second', $input);
        try {
            $later->start();
            $ready = $later->waitUntil(fn (string $type, string $output): bool => str_contains($output, 'READY'));
            $this->assertTrue($ready, $later->getErrorOutput().$later->getOutput());
            $early->start();
            $locked = $early->waitUntil(fn (string $type, string $output): bool => str_contains($output, 'LOCKED'));
            $this->assertTrue($locked, $early->getErrorOutput().$early->getOutput());
            $input->write("continue\n");
            $this->assertTrue($later->waitUntil(fn (string $type, string $output): bool => str_contains($output, 'WRITING')), $later->getErrorOutput());
            $early->wait();
            $later->wait();
            $this->assertTrue($early->isSuccessful(), $early->getErrorOutput());
            $this->assertTrue($later->isSuccessful(), $later->getErrorOutput());

            return [$this->outcome($early), $this->outcome($later)];
        } finally {
            $input->close();
            $early->stop(0);
            $later->stop(0);
        }
    }

    private function worker(Admission $admission, string $action, array $data, User $actor, bool $hold, string $key, ?InputStream $input = null): Process
    {
        $code = <<<'PHP'
require $argv[1].'/vendor/autoload.php';
$app = require $argv[1].'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (!$app->environment('testing') || $app->configurationIsCached() || config('database.default') !== 'mysql'
    || config('database.connections.mysql.database') !== 'easycare_test' || config('database.connections.mysql.username') !== 'easycare_test'
    || !in_array(config('database.connections.mysql.host'), ['localhost', '127.0.0.1', '::1'], true) || config('database.connections.mysql.url')) {
    throw new RuntimeException('Competing command workers require the isolated local test database.');
}
Illuminate\Support\Carbon::setTestNow('2026-11-14 11:00:00 UTC');
$data = json_decode($argv[4], true, flags: JSON_THROW_ON_ERROR);
$request = Illuminate\Http\Request::create('/api/v1/admissions/'.$argv[2].'/'.$argv[3], 'POST', $data);
$request->headers->set('Idempotency-Key', $argv[7]);
$route = new Illuminate\Routing\Route('POST', 'api/v1/admissions/{admission}/'.$argv[3], fn () => null);
$route->name('api.v1.admissions.'.$argv[3]);
$route->bind($request);
$request->setRouteResolver(fn () => $route);
$request->setUserResolver(fn () => App\Models\User::findOrFail((int)$argv[5]));
$app->instance('request', $request);
$request->setUserResolver(fn () => App\Models\User::findOrFail((int)$argv[5]));
if ($argv[6] !== 'hold') { echo "READY\n"; flush(); fgets(STDIN); echo "WRITING\n"; flush(); }
try {
    $result = $app->make(App\Services\IdempotentCommands::class)->run($request, function () use ($app, $request, $data, $argv) {
        $record = $app->make(App\Services\AdmissionFlow::class)->execute($request, $request->user('web'), $data);
        if ($argv[6] === 'hold') { echo "LOCKED\n"; flush(); usleep(700000); }
        return App\Services\AdmissionFlow::response($record);
    });
    echo $result->headers->get('Idempotency-Replayed') === 'true' ? "replayed\n" : "saved\n";
} catch (App\Exceptions\WriteConflict $exception) {
    if ($argv[6] === 'hold') { echo "LOCKED\n"; flush(); }
    echo $exception->errorCode."\n";
}
PHP;
        $process = new Process([PHP_BINARY, '-r', $code, base_path(), (string) $admission->id, $action,
            json_encode($data, JSON_THROW_ON_ERROR), (string) $actor->id, $hold ? 'hold' : 'run', $key],
            base_path(), ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql'], timeout: 30);
        if ($input !== null) {
            $process->setInput($input);
        }

        return $process;
    }

    private function outcome(Process $process): string
    {
        $lines = explode("\n", trim($process->getOutput()));

        return end($lines);
    }

}
