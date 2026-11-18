<?php

namespace Tests\Feature;

use App\Models\Admission;
use App\Models\Bed;
use App\Models\BedAllocation;
use App\Models\CurrentBedOccupancy;
use App\Models\Encounter;
use App\Models\Ward;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdmissionFoundationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now('UTC')->setDate(2026, 11, 14)->setTime(10, 0));
        $this->seed(DatabaseSeeder::class);
    }

    public function test_saved_identity_relationships_and_current_occupancy_are_derived_from_allocation_history(): void
    {
        $admission = Admission::factory()->create(['admitted_at' => '2026-11-14 10:00:00.123456']);
        $bed = Bed::factory()->create(['label' => 'Bed Ω', 'daily_rate' => '1250.50']);
        $allocation = BedAllocation::factory()->create(['admission_id' => $admission->id, 'bed_id' => $bed->id]);
        $this->assertTrue($admission->encounter->admission->is($admission));
        $this->assertTrue($admission->patient->admissions->sole()->is($admission));
        $this->assertTrue($admission->admittedBy->is($admission->encounter->openedBy));
        $this->assertTrue($bed->ward->beds->sole()->is($bed));
        $this->assertTrue($allocation->bed->is($bed));
        $this->assertTrue($allocation->admission->is($admission));
        $this->assertTrue($allocation->assignedBy->is($admission->admittedBy));
        $occupancy = $bed->currentOccupancy;
        $this->assertTrue($occupancy->allocation->is($allocation));
        $this->assertTrue($occupancy->admission->is($admission));
        $this->assertTrue($occupancy->bed->is($bed));
        $this->assertTrue($admission->currentOccupancy->is($occupancy));
        $this->assertSame('1250.50', $bed->fresh()->daily_rate);
        $this->assertSame('123456', $allocation->fresh()->assigned_at->format('u'));
        $this->assertSame(0, Bed::available()->count());
        $this->assertSame(1, BedAllocation::current()->count());
    }

    public function test_transfers_discharge_and_new_encounter_readmission_preserve_history(): void
    {
        $admission = Admission::factory()->create();
        $first = BedAllocation::factory()->create(['admission_id' => $admission->id]);
        $this->release($first, '2026-11-14 11:00:00.123456');
        $second = BedAllocation::factory()->create(['admission_id' => $admission->id, 'assigned_at' => '2026-11-14 11:00:00.123456']);
        $this->assertSame(2, $admission->allocations()->count());
        $this->assertTrue($admission->fresh()->currentOccupancy->allocation->is($second));
        $this->assertSame(1, Bed::available()->count());
        $this->authorize($admission, '2026-11-14 12:00:00.000001');
        $this->assertSame(1, Admission::active()->count());
        $this->release($second, '2026-11-14 12:30:00.999999');
        $admission->forceFill(['status' => 'discharged', 'discharged_at' => '2026-11-14 12:30:00.999999', 'discharged_by' => $admission->admitted_by, 'version' => 3])->save();
        $this->assertSame(0, Admission::active()->count());
        $this->assertSame(0, CurrentBedOccupancy::count());
        $this->assertSame(2, Bed::available()->count());
        $this->assertTrue($admission->fresh()->authorizedBy->is($admission->admittedBy));
        $this->assertTrue($admission->fresh()->dischargedBy->is($admission->admittedBy));
        $this->assertTrue($second->fresh()->releasedBy->is($admission->admittedBy));
        $visit = Encounter::factory()->for($admission->patient)->create(['opened_at' => '2026-11-14 13:00:00']);
        Admission::factory()->forEncounter($visit)->create();
        $this->assertSame(2, $admission->patient->admissions()->count());
        $this->reject(fn () => Admission::factory()->forEncounter($admission->encounter)->create());
    }

    public function test_bedless_admissions_and_unique_active_patient_and_bed_reservations(): void
    {
        $admission = Admission::factory()->create();
        $this->assertNull($admission->currentOccupancy);
        $visit = Encounter::factory()->for($admission->patient)->create();
        $this->reject(fn () => Admission::factory()->forEncounter($visit)->create());
        $first = BedAllocation::factory()->create(['admission_id' => $admission->id]);
        $other = Admission::factory()->create();
        $otherBed = Bed::factory()->create();
        $this->reject(fn () => BedAllocation::factory()->create(['admission_id' => $admission->id, 'bed_id' => $otherBed->id]));
        $this->reject(fn () => BedAllocation::factory()->create(['admission_id' => $other->id, 'bed_id' => $first->bed_id]));
        $this->authorize($admission);
        $this->reject(fn () => Admission::factory()->forEncounter($visit)->create());
        $this->assertDatabaseCount('current_bed_occupancies', 1);
    }

    public function test_failed_transfer_restores_the_original_release_and_occupancy(): void
    {
        $first = BedAllocation::factory()->create();
        $occupied = BedAllocation::factory()->create();
        $this->reject(function () use ($first, $occupied): void {
            $this->release($first, '2026-11-14 11:00:00');
            BedAllocation::factory()->create(['admission_id' => $first->admission_id, 'bed_id' => $occupied->bed_id, 'assigned_at' => '2026-11-14 11:00:00']);
        });
        $this->assertNull($first->fresh()->released_at);
        $this->assertSame($first->id, $first->admission->currentOccupancy->allocation_id);
    }

    public function test_occupied_locations_cannot_retire_and_retired_locations_cannot_allocate(): void
    {
        $allocation = BedAllocation::factory()->create();
        $bed = $allocation->bed;
        $this->reject(fn () => $bed->forceFill(['is_active' => false, 'version' => 2])->save());
        $this->reject(fn () => $bed->ward->forceFill(['is_active' => false, 'version' => 2])->save());
        $this->release($allocation, '2026-11-14 11:00:00');
        $this->reject(fn () => $bed->fresh()->forceFill(['label' => 'Changed used location', 'version' => 2])->save());
        $bed->fresh()->forceFill(['is_active' => false, 'version' => 2])->save();
        $this->reject(fn () => BedAllocation::factory()->create(['bed_id' => $bed->id]));
        $other = Bed::factory()->create();
        $other->ward->forceFill(['is_active' => false, 'version' => 2])->save();
        $this->reject(fn () => BedAllocation::factory()->create(['bed_id' => $other->id]));
        $this->assertSame(0, Bed::available()->count());
    }

    public function test_occupancy_projection_cannot_be_manually_inserted_updated_deleted_or_saved(): void
    {
        $allocation = BedAllocation::factory()->create();
        foreach (['insert', 'update', 'delete'] as $action) {
            $this->reject(fn () => match ($action) {
                'insert' => DB::table('current_bed_occupancies')->insert(['bed_id' => $allocation->bed_id, 'admission_id' => $allocation->admission_id, 'allocation_id' => $allocation->id]),
                'update' => DB::table('current_bed_occupancies')->update(['allocation_id' => $allocation->id]),
                'delete' => DB::table('current_bed_occupancies')->delete(),
            });
        }
        try {
            CurrentBedOccupancy::sole()->save();
            $this->fail('The occupancy projection was writable.');
        } catch (LogicException) {
            $this->assertTrue(true);
        }
        $this->expectException(LogicException::class);
        CurrentBedOccupancy::sole()->delete();
    }

    #[DataProvider('invalidAdmissions')]
    public function test_invalid_admission_creation_is_rejected_atomically(array $changes): void
    {
        $admission = Admission::factory()->make();
        $this->reject(fn () => DB::table('admissions')->insert(array_replace($admission->getAttributes(), $changes)));
    }

    public static function invalidAdmissions(): array
    {
        return [
            'wrong patient' => [['patient_id' => 999999999]], 'missing encounter' => [['encounter_id' => 999999999]],
            'missing admitting staff' => [['admitted_by' => 999999999]], 'bad status' => [['status' => 'ADMITTED']],
            'wrong initial version' => [['version' => 2]], 'earlier admission' => [['admitted_at' => '1900-01-01 00:00:00.000000']],
            'missing time' => [['admitted_at' => null]], 'bad date' => [['admitted_at' => 'bad']],
            'premature authorization' => [['authorized_by' => 1, 'authorized_at' => '2026-11-14 10:00:00.000000']],
            'premature summary' => [['discharge_summary' => 'Cannot fabricate authorization']],
        ];
    }

    #[DataProvider('invalidAuthorizations')]
    public function test_discharge_authorization_guards_identity_versions_and_provenance(array $changes): void
    {
        $admission = Admission::factory()->create();
        $this->reject(fn () => DB::table('admissions')->where('id', $admission->id)->update(array_replace([
            'status' => 'discharge_authorized', 'authorized_at' => '2026-11-14 11:00:00.000000', 'authorized_by' => $admission->admitted_by,
            'discharge_reason' => 'Synthetic discharge reason', 'version' => 2,
        ], $changes)));
    }

    public static function invalidAuthorizations(): array
    {
        return [
            'missing signer' => [['authorized_by' => null]], 'missing time' => [['authorized_at' => null]],
            'missing reason' => [['discharge_reason' => null]], 'short reason' => [['discharge_reason' => 'x']],
            'earlier signature' => [['authorized_at' => '1900-01-01 00:00:00.000000']], 'stale version' => [['version' => 1]],
            'jumped version' => [['version' => 3]], 'skipped signoff' => [['status' => 'discharged', 'discharged_at' => '2026-11-14 11:00:00.000000', 'discharged_by' => 1]],
            'changed admitting staff' => [['admitted_by' => 999999999]], 'changed admission time' => [['admitted_at' => '2026-11-14 10:30:00.000000']],
        ];
    }

    #[DataProvider('invalidReleases')]
    public function test_allocation_history_cannot_be_rewritten_or_released_without_provenance(array $changes): void
    {
        $allocation = BedAllocation::factory()->create();
        $this->reject(fn () => DB::table('bed_allocations')->where('id', $allocation->id)->update(array_replace([
            'released_at' => '2026-11-14 11:00:00.000000', 'released_by' => $allocation->assigned_by, 'release_reason' => 'Synthetic transfer release', 'version' => 2,
        ], $changes)));
    }

    public static function invalidReleases(): array
    {
        return [
            'missing time' => [['released_at' => null]], 'missing staff' => [['released_by' => null]], 'missing reason' => [['release_reason' => null]],
            'short reason' => [['release_reason' => 'x']], 'earlier release' => [['released_at' => '1900-01-01 00:00:00.000000']],
            'bad date' => [['released_at' => 'bad']], 'stale version' => [['version' => 1]], 'jumped version' => [['version' => 3]],
            'changed assignment time' => [['assigned_at' => '2026-11-14 10:30:00.000000']], 'changed assignment reason' => [['assignment_reason' => 'Rewriting retained history']],
            'missing bed' => [['bed_id' => 999999999]], 'changed admission' => [['admission_id' => 999999999]],
        ];
    }

    public function test_terminal_records_cannot_reopen_and_backdating_cannot_overlap_retained_history(): void
    {
        $allocation = BedAllocation::factory()->create();
        $this->release($allocation, '2026-11-14 11:00:00.123456');
        $this->reject(fn () => $allocation->forceFill(['released_at' => null, 'released_by' => null, 'release_reason' => null, 'version' => 3])->save());
        $this->reject(fn () => BedAllocation::factory()->create(['bed_id' => $allocation->bed_id, 'assigned_at' => '2026-11-14 11:00:00.123455']));
        $this->reject(fn () => BedAllocation::factory()->create(['admission_id' => $allocation->admission_id, 'assigned_at' => '2026-11-14 11:00:00.123455']));
        $admission = $allocation->admission;
        $this->authorize($admission, '2026-11-14 12:00:00');
        $admission->forceFill(['status' => 'discharged', 'discharged_at' => '2026-11-14 13:00:00', 'discharged_by' => $admission->admitted_by, 'version' => 3])->save();
        $this->reject(fn () => $admission->forceFill(['status' => 'admitted', 'version' => 4])->save());
        $this->reject(fn () => BedAllocation::factory()->create(['admission_id' => $admission->id, 'assigned_at' => '2026-11-14 14:00:00']));
        foreach (['admissions' => $admission->id, 'bed_allocations' => $allocation->id, 'beds' => $allocation->bed_id, 'wards' => $allocation->bed->ward_id] as $table => $id) {
            $this->reject(fn () => DB::table($table)->where('id', $id)->delete());
        }
    }

    public function test_authorization_is_immutable_and_discharge_requires_released_occupancy(): void
    {
        $allocation = BedAllocation::factory()->create();
        $admission = $allocation->admission;
        $this->authorize($admission);
        $end = ['status' => 'discharged', 'discharged_at' => '2026-11-14 12:00:00.000000', 'discharged_by' => $admission->admitted_by, 'version' => 3];
        $this->reject(fn () => DB::table('admissions')->where('id', $admission->id)->update($end));
        $this->release($allocation, '2026-11-14 12:00:00.000001');
        $this->reject(fn () => DB::table('admissions')->where('id', $admission->id)->update($end));
        $end['discharged_at'] = '2026-11-14 13:00:00.000000';
        foreach (['authorized_at' => '2026-11-14 11:30:00.000000', 'authorized_by' => 999999999,
            'discharge_reason' => 'Changed signed reason', 'discharge_summary' => 'Changed signed summary', 'discharged_by' => null] as $field => $value) {
            $this->reject(fn () => DB::table('admissions')->where('id', $admission->id)->update([$field => $value] + $end));
        }
        DB::table('admissions')->where('id', $admission->id)->update($end);
        $this->assertSame('discharged', $admission->fresh()->status);
    }

    public function test_active_admissions_prevent_encounter_closure_and_closed_encounters_cannot_be_admitted(): void
    {
        $admission = Admission::factory()->create();
        $this->reject(fn () => $admission->encounter->forceFill(['status' => 'cancelled', 'closed_at' => now('UTC'), 'version' => 2])->save());
        $visit = Encounter::factory()->create();
        $visit->forceFill(['status' => 'cancelled', 'closed_at' => now('UTC'), 'version' => 2])->save();
        $this->reject(fn () => Admission::factory()->forEncounter($visit)->create());
    }

    public function test_visit_api_reports_an_active_admission_conflict_without_changing_the_visit(): void
    {
        $this->grantRolePermissions('frontdesk', ['patients.view', 'encounters.view', 'encounters.view_any', 'encounters.cancel']);
        $staff = $this->createStaffWithRole('frontdesk');
        $admission = Admission::factory()->create();
        $this->actingAs($staff);
        $this->assertApiFailure($this->postCommand('/api/v1/encounters/'.$admission->encounter_id.'/cancel', ['version' => 1,
            'reason' => 'Attempted cancellation with an inpatient']), 409, 'encounter_active_admission');
        $this->assertSame('open', $admission->encounter->fresh()->status);
        $this->assertDatabaseCount('encounter_revisions', 0);
        $this->assertDatabaseCount('idempotency_requests', 0);
    }

    public function test_catalogs_protect_identity_versions_unique_labels_and_reference_seeding(): void
    {
        $this->assertDatabaseCount('wards', 0);
        $this->assertDatabaseCount('beds', 0);
        $bed = Bed::factory()->create();
        $this->reject(fn () => Bed::factory()->create(['ward_id' => $bed->ward_id, 'label' => $bed->label]));
        Bed::factory()->create(['label' => $bed->label]);
        $this->reject(fn () => $bed->forceFill(['label' => 'New label', 'version' => 1])->save());
        $ward = Ward::factory()->create();
        $this->reject(fn () => $bed->fresh()->forceFill(['ward_id' => $ward->id, 'version' => 2])->save());
        $this->reject(fn () => $bed->ward->forceFill(['code' => 'Changed immutable code', 'version' => 2])->save());
        $bed->fresh()->forceFill(['label' => 'Bed 新', 'version' => 2])->save();
        $this->assertSame('Bed 新', $bed->fresh()->label);
        $this->seed(DatabaseSeeder::class);
        $this->assertDatabaseCount('permissions', 118);
        $this->assertDatabaseCount('role_permissions', 21);
    }

    /** @param callable(): mixed $operation */
    private function reject(callable $operation): void
    {
        $this->assertTablesUnchanged(['wards', 'beds', 'admissions', 'bed_allocations', 'encounters'], function () use ($operation): void {
            try {
                DB::transaction($operation);
                $this->fail('The database accepted an invalid admission storage mutation.');
            } catch (QueryException) {
                $this->assertTrue(true);
            }
        });
    }

    private function authorize(Admission $admission, string $at = '2026-11-14 11:00:00'): void
    {
        $admission->forceFill(['status' => 'discharge_authorized', 'authorized_at' => $at, 'authorized_by' => $admission->admitted_by,
            'discharge_reason' => 'Synthetic discharge authorization', 'version' => 2])->save();
    }

    private function release(BedAllocation $allocation, string $at): void
    {
        $allocation->forceFill(['released_at' => $at, 'released_by' => $allocation->assigned_by,
            'release_reason' => 'Synthetic allocation release', 'version' => 2])->save();
    }
}
