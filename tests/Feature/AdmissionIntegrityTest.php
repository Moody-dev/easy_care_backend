<?php

namespace Tests\Feature;

use App\Models\Admission;
use App\Models\Bed;
use App\Models\BedAllocation;
use App\Models\DoctorAssignment;
use App\Models\Encounter;
use App\Models\Patient;
use App\Models\Ward;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdmissionIntegrityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now('UTC')->setDate(2026, 11, 14)->setTime(10, 0));
        $this->seed(DatabaseSeeder::class);
    }

    public function test_coherent_foreign_keys_still_cannot_move_saved_admissions_or_allocation_history(): void
    {
        $first = BedAllocation::factory()->create();
        $other = Admission::factory()->create();
        $otherBed = Bed::factory()->create();
        $this->reject(fn () => DB::table('admissions')->where('id', $first->admission_id)->update([
            'encounter_id' => $other->encounter_id, 'patient_id' => $other->patient_id, 'version' => 2,
            'status' => 'discharge_authorized', 'authorized_at' => '2026-11-14 11:00:00.000000',
            'authorized_by' => $other->admitted_by, 'discharge_reason' => 'Changed coherent identity',
        ]));
        $this->reject(fn () => DB::table('bed_allocations')->where('id', $first->id)->update([
            'bed_id' => $otherBed->id, 'admission_id' => $other->id, 'version' => 2,
            'released_at' => '2026-11-14 11:00:00.000000', 'released_by' => $other->admitted_by, 'release_reason' => 'Rewritten coherent ownership',
        ]));
    }

    public function test_restricted_patients_cannot_receive_new_admissions(): void
    {
        $visit = Encounter::factory()->create();
        $patient = $visit->patient;
        $patient->forceFill(['access_status' => 'restricted', 'version' => 2])->save();
        $this->reject(fn () => Admission::factory()->forEncounter($visit)->create());
    }

    #[DataProvider('invalidCatalogs')]
    public function test_catalog_validation_is_enforced_for_direct_database_writes(string $table, array $changes): void
    {
        $record = $table === 'wards' ? Ward::factory()->make() : Bed::factory()->make();
        $this->reject(fn () => DB::table($table)->insert(array_replace($record->getAttributes(), $changes)));
    }

    public static function invalidCatalogs(): array
    {
        return [
            'blank ward code' => ['wards', ['code' => '']], 'blank ward name' => ['wards', ['name' => '   ']],
            'long ward code' => ['wards', ['code' => str_repeat('x', 65)]], 'long ward name' => ['wards', ['name' => str_repeat('界', 192)]],
            'long ward type' => ['wards', ['type' => str_repeat('x', 65)]], 'negative ward rate' => ['wards', ['daily_rate' => '-0.01']],
            'invalid ward activation' => ['wards', ['is_active' => 2]], 'invalid ward version' => ['wards', ['version' => 0]],
            'blank bed label' => ['beds', ['label' => '']], 'long bed label' => ['beds', ['label' => str_repeat('界', 65)]],
            'negative bed rate' => ['beds', ['daily_rate' => '-0.01']], 'missing ward' => ['beds', ['ward_id' => 999999999]],
            'invalid bed activation' => ['beds', ['is_active' => 2]], 'invalid bed version' => ['beds', ['version' => 2]],
        ];
    }

    public function test_unicode_catalog_boundaries_and_unset_rates_round_trip_without_changing_reference_grants(): void
    {
        $ward = Ward::factory()->create(['name' => str_repeat('界', 191), 'type' => str_repeat('界', 64)]);
        $bed = Bed::factory()->for($ward)->create(['label' => str_repeat('界', 64)]);
        $this->assertSame(str_repeat('界', 191), $ward->fresh()->name);
        $this->assertSame(str_repeat('界', 64), $bed->fresh()->label);
        $this->assertNull($ward->daily_rate);
        $this->assertNull($bed->daily_rate);
        $this->assertDatabaseCount('permissions', 99);
        $this->assertDatabaseCount('role_permissions', 21);
    }

    public function test_mass_assignment_does_not_choose_admission_identity_or_allocate_an_occupied_bed(): void
    {
        foreach ([new Admission, new BedAllocation] as $record) {
            try {
                $record->fill(['id' => 999, 'patient_id' => 999, 'encounter_id' => 999, 'bed_id' => 999, 'admission_id' => 999, 'version' => 99]);
            } catch (MassAssignmentException) {
                $this->assertTrue(true);
            }
            foreach (['id', 'patient_id', 'encounter_id', 'bed_id', 'admission_id'] as $field) {
                $this->assertNull($record->getAttribute($field));
            }
            $this->assertSame(1, $record->version);
        }
        $bed = new Bed;
        $bed->fill(['label' => 'Allowed catalog label', 'ward_id' => 999, 'is_active' => false, 'occupied_by_patient_id' => 999]);
        $this->assertSame('Allowed catalog label', $bed->label);
        $this->assertNull($bed->ward_id);
        $this->assertTrue($bed->is_active);
        $this->assertNull($bed->occupied_by_patient_id);
    }

    public function test_completion_conflicts_leave_assignments_intact_until_discharge_and_a_valid_clock(): void
    {
        $this->grantRolePermissions('doctor', ['patients.view', 'encounters.view', 'encounters.progress']);
        $doctor = $this->createStaffWithRole('doctor');
        $visit = Encounter::factory()->create(['opened_by' => $doctor->id]);
        $assignment = DoctorAssignment::factory()->for($visit)->create(['doctor_id' => $doctor->id, 'assigned_by' => $doctor->id, 'assigned_at' => now('UTC')]);
        $visit->forceFill(['status' => 'in_progress', 'version' => 2])->save();
        $assignment->forceFill(['status' => 'accepted', 'accepted_at' => now('UTC'), 'version' => 2])->save();
        $admission = Admission::factory()->forEncounter($visit)->create();
        $data = ['version' => 2, 'assignment_id' => $assignment->id, 'assignment_version' => 2, 'reason' => 'Synthetic completion review'];
        $this->actingAs($doctor);
        $this->assertApiFailure($this->postCommand('/api/v1/encounters/'.$visit->id.'/complete', $data), 409, 'encounter_active_admission');
        $this->assertSame('accepted', $assignment->fresh()->status);
        $this->assertSame(2, $visit->fresh()->version);
        $admission->forceFill(['status' => 'discharge_authorized', 'authorized_at' => '2026-11-14 11:00:00',
            'authorized_by' => $doctor->id, 'discharge_reason' => 'Synthetic authorized discharge', 'version' => 2])->save();
        $admission->forceFill(['status' => 'discharged', 'discharged_at' => '2026-11-14 12:00:00', 'discharged_by' => $doctor->id, 'version' => 3])->save();
        $this->assertApiFailure($this->postCommand('/api/v1/encounters/'.$visit->id.'/complete', $data), 409, 'encounter_clock_conflict');
        $this->assertSame('accepted', $assignment->fresh()->status);
        $this->travelTo(now('UTC')->setTime(12, 1));
        $this->postCommand('/api/v1/encounters/'.$visit->id.'/complete', $data)->assertOk()->assertJsonPath('data.status', 'closed');
        $this->assertSame('completed', $assignment->fresh()->status);
        $this->assertSame('discharged', $admission->fresh()->status);
    }

    public function test_cross_patient_identity_pairs_and_malformed_initial_allocations_fail_atomically(): void
    {
        $admission = Admission::factory()->create();
        $patient = Patient::factory()->create();
        $other = Encounter::factory()->for($patient)->create();
        $this->reject(fn () => Admission::factory()->create(['encounter_id' => $other->id, 'patient_id' => $admission->patient_id]));
        $bed = Bed::factory()->create();
        foreach ([['assignment_reason' => null], ['assignment_reason' => 'x'], ['assigned_at' => '1900-01-01 00:00:00.000000'], ['assigned_by' => 999999999], ['released_at' => '2026-11-14 11:00:00.000000']] as $changes) {
            $this->reject(fn () => BedAllocation::factory()->create($changes + ['admission_id' => $admission->id, 'bed_id' => $bed->id]));
        }
    }

    public function test_readmission_cannot_be_backdated_into_a_retained_inpatient_episode(): void
    {
        $admission = Admission::factory()->create();
        $otherVisit = Encounter::factory()->for($admission->patient)->create();
        $admission->forceFill(['status' => 'discharge_authorized', 'authorized_at' => '2026-11-14 11:00:00',
            'authorized_by' => $admission->admitted_by, 'discharge_reason' => 'Synthetic retained discharge', 'version' => 2])->save();
        $admission->forceFill(['status' => 'discharged', 'discharged_at' => '2026-11-14 12:00:00',
            'discharged_by' => $admission->admitted_by, 'version' => 3])->save();
        $this->reject(fn () => Admission::factory()->forEncounter($otherVisit)->create(['admitted_at' => '2026-11-14 11:59:59.999999']));
        Admission::factory()->forEncounter($otherVisit)->create(['admitted_at' => '2026-11-14 12:00:00.000000']);
        $this->assertSame(2, Admission::count());
    }

    /** @param callable(): mixed $operation */
    private function reject(callable $operation): void
    {
        $this->assertTablesUnchanged(['wards', 'beds', 'admissions', 'bed_allocations', 'encounters'], function () use ($operation): void {
            try {
                DB::transaction($operation);
                $this->fail('Invalid inpatient data was accepted.');
            } catch (QueryException) {
                $this->assertTrue(true);
            }
        });
    }
}
