<?php

namespace Tests\Feature;

use App\Models\Admission;
use App\Models\Bed;
use App\Models\BedAllocation;
use App\Models\CurrentBedOccupancy;
use App\Models\Encounter;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\InputStream;
use Tests\CommittedDatabase;
use Tests\TestCase;

class AdmissionConcurrencyTest extends TestCase
{
    use CommittedDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now('UTC')->setDate(2026, 11, 14)->setTime(10, 0));
    }

    public function test_parallel_patients_cannot_occupy_the_same_bed(): void
    {
        [$first, $second, $bed] = $this->fixtures();
        $this->assertSame(['saved', 'rejected'], $this->concurrent('allocate', 'allocate', $first, $second, $bed));
        $this->assertSame(1, CurrentBedOccupancy::count());
        $this->assertSame($first->id, CurrentBedOccupancy::sole()->admission_id);
    }

    public function test_parallel_beds_cannot_both_be_assigned_to_one_admission(): void
    {
        [$first, , $bed] = $this->fixtures();
        $this->assertSame(['saved', 'rejected'], $this->concurrent('allocate', 'allocate_other_bed', $first, $first, $bed));
        $this->assertSame(1, BedAllocation::current()->count());
    }

    public function test_parallel_encounters_cannot_create_two_active_admissions_for_one_patient(): void
    {
        [$first, $second, $bed] = $this->fixtures();
        $visit = Encounter::factory()->create(['opened_at' => '2026-11-14 10:00:00']);
        $otherVisit = Encounter::factory()->for($visit->patient)->create(['opened_at' => '2026-11-14 10:00:00']);
        $this->assertSame(['saved', 'rejected'], $this->concurrent('admit', 'admit', $first, $second, $bed, $visit->id, $otherVisit->id));
        $this->assertSame(1, Admission::active()->where('patient_id', $visit->patient_id)->count());
    }

    public function test_admission_committing_first_prevents_encounter_cancellation(): void
    {
        [$first, $second, $bed] = $this->fixtures();
        $visit = Encounter::factory()->create(['opened_at' => '2026-11-14 10:00:00']);
        $this->assertSame(['saved', 'rejected'], $this->concurrent('admit', 'close', $first, $second, $bed, $visit->id));
        $this->assertSame('open', $visit->fresh()->status);
    }

    public function test_encounter_cancellation_committing_first_prevents_a_late_admission(): void
    {
        [$first, $second, $bed] = $this->fixtures();
        $visit = Encounter::factory()->create(['opened_at' => '2026-11-14 10:00:00']);
        $this->assertSame(['saved', 'rejected'], $this->concurrent('close', 'admit', $first, $second, $bed, $visit->id));
        $this->assertSame('cancelled', $visit->fresh()->status);
        $this->assertSame(0, Admission::where('encounter_id', $visit->id)->count());
    }

    public function test_retirement_committing_first_prevents_a_late_bed_allocation(): void
    {
        [$first, $second, $bed] = $this->fixtures();
        $this->assertSame(['saved', 'rejected'], $this->concurrent('retire', 'allocate', $first, $second, $bed));
        $this->assertSame(0, CurrentBedOccupancy::count());
    }

    public function test_allocation_committing_first_prevents_retiring_an_occupied_bed(): void
    {
        [$first, $second, $bed] = $this->fixtures();
        $this->assertSame(['saved', 'rejected'], $this->concurrent('allocate', 'retire', $first, $second, $bed));
        $this->assertTrue($bed->fresh()->is_active);
    }

    public function test_new_release_history_is_seen_despite_an_older_read_snapshot_and_cannot_be_backdated(): void
    {
        [$first, $second, $bed] = $this->fixtures();
        BedAllocation::factory()->create(['admission_id' => $first->id, 'bed_id' => $bed->id, 'assigned_at' => '2026-11-14 10:00:00']);
        $this->assertSame(['saved', 'rejected'], $this->concurrent('release', 'backdate', $first, $second, $bed));
        $this->assertSame(0, CurrentBedOccupancy::count());
        $this->assertSame(1, BedAllocation::count());
    }

    public function test_committed_discharge_history_prevents_a_backdated_readmission_from_an_older_snapshot(): void
    {
        [$first, $second, $bed] = $this->fixtures();
        $visit = Encounter::factory()->for($first->patient)->create(['opened_at' => '2026-11-14 10:00:00']);
        $first->forceFill(['status' => 'discharge_authorized', 'authorized_at' => '2026-11-14 11:00:00',
            'authorized_by' => $first->admitted_by, 'discharge_reason' => 'Concurrent authorization', 'version' => 2])->save();
        $this->assertSame(['saved', 'rejected'], $this->concurrent('discharge', 'admit', $first, $second, $bed, $visit->id));
        $this->assertSame('discharged', $first->fresh()->status);
        $this->assertSame(1, Admission::where('patient_id', $first->patient_id)->count());
    }

    /** @return array{Admission, Admission, Bed} */
    private function fixtures(): array
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Real competing allocation writes require the isolated MySQL database.');
        }
        $this->seed(DatabaseSeeder::class);

        return [Admission::factory()->create(['admitted_at' => '2026-11-14 10:00:00']),
            Admission::factory()->create(['admitted_at' => '2026-11-14 10:00:00']), Bed::factory()->create()];
    }

    /** @return list<string> */
    private function concurrent(string $firstAction, string $secondAction, Admission $first, Admission $second, Bed $bed, int $visit = 0, int $otherVisit = 0): array
    {
        $early = $this->worker($firstAction, $first, $bed, $visit, true);
        $input = new InputStream;
        $later = $this->worker($secondAction, $second, $bed, $otherVisit ?: $visit, false, $input);
        try {
            $later->start();
            $this->assertTrue($later->waitUntil(fn (string $type, string $output): bool => str_contains($output, 'SNAPSHOT')), $later->getErrorOutput());
            $early->start();
            $this->assertTrue($early->waitUntil(fn (string $type, string $output): bool => str_contains($output, 'LOCKED')), $early->getErrorOutput());
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

    private function worker(string $action, Admission $admission, Bed $bed, int $visit, bool $hold, ?InputStream $input = null): Process
    {
        $code = <<<'PHP'
require $argv[1].'/vendor/autoload.php';
$app = require $argv[1].'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (!$app->environment('testing') || $app->configurationIsCached() || config('database.default') !== 'mysql'
    || config('database.connections.mysql.database') !== 'easycare_test' || config('database.connections.mysql.username') !== 'easycare_test'
    || !in_array(config('database.connections.mysql.host'), ['localhost', '127.0.0.1', '::1'], true) || config('database.connections.mysql.url')) {
    throw new RuntimeException('Parallel admission workers require the isolated local test database.');
}
try {
    Illuminate\Support\Facades\DB::transaction(function () use ($argv): void {
        if ($argv[6] !== 'hold') {
            App\Models\BedAllocation::query()->count();
            echo "SNAPSHOT\n"; flush(); fgets(STDIN);
            echo "WRITING\n"; flush();
        }
        $admission = App\Models\Admission::findOrFail((int)$argv[3]);
        $bed = App\Models\Bed::findOrFail((int)$argv[4]);
        switch ($argv[2]) {
            case 'allocate': case 'allocate_other_bed': case 'backdate':
                if ($argv[2] === 'allocate_other_bed') $bed = App\Models\Bed::factory()->create();
                App\Models\BedAllocation::factory()->create(['bed_id'=>$bed->id, 'admission_id'=>$admission->id,
                    'assigned_at'=>$argv[2] === 'backdate' ? '2026-11-14 10:30:00' : '2026-11-14 11:00:00']);
                break;
            case 'retire': $bed->forceFill(['is_active'=>false, 'version'=>2])->save(); break;
            case 'release': App\Models\BedAllocation::where('bed_id',$bed->id)->whereNull('released_at')->firstOrFail()
                ->forceFill(['released_at'=>'2026-11-14 11:00:00', 'released_by'=>$admission->admitted_by, 'release_reason'=>'Concurrent release', 'version'=>2])->save(); break;
            case 'admit': $encounter = App\Models\Encounter::findOrFail((int)$argv[5]); App\Models\Admission::factory()->forEncounter($encounter)->create(['admitted_at'=>'2026-11-14 11:00:00']); break;
            case 'discharge': $admission->forceFill(['status'=>'discharged','discharged_at'=>'2026-11-14 12:00:00','discharged_by'=>$admission->admitted_by,'version'=>3])->save(); break;
            case 'close': App\Models\Encounter::findOrFail((int)$argv[5])->forceFill(['status'=>'cancelled','closed_at'=>'2026-11-14 11:00:00','version'=>2])->save(); break;
        }
        if ($argv[6] === 'hold') { echo "LOCKED\n"; flush(); usleep(700000); }
    });
    echo "saved\n";
} catch (Illuminate\Database\QueryException) { if ($argv[6] === 'hold') { echo "LOCKED\n"; flush(); } echo "rejected\n"; }
PHP;
        $process = new Process([PHP_BINARY, '-r', $code, base_path(), $action, (string) $admission->id, (string) $bed->id,
            (string) $visit, $hold ? 'hold' : 'run'], base_path(), ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql'], timeout: 30);
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
