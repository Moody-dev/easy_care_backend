<?php

namespace Tests\Feature;

use App\Models\Bed;
use App\Models\BedAllocation;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Ward;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LocationApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now('UTC')->setDate(2026, 11, 14)->setTime(10, 0));
        $this->seed(DatabaseSeeder::class);
        $this->grantRolePermissions('admin', ['locations.view', 'locations.manage']);
        $this->actingAs($this->createStaffWithRole('admin'));
    }

    public function test_catalog_creation_updates_and_retries_preserve_stable_identity_versions_and_decimal_rates(): void
    {
        $wardData = ['code' => 'WARD-A', 'name' => 'Ward A', 'type' => 'General', 'daily_rate' => '12500.50'];
        $key = ['Idempotency-Key' => 'location-ward-confirmation'];
        $ward = $this->postCommand('/api/v1/locations/wards', $wardData, $key)->assertCreated()
            ->assertJsonPath('data.version', 1)->assertJsonPath('data.daily_rate', '12500.50')->json('data');
        $this->postCommand('/api/v1/locations/wards', $wardData, $key)->assertOk()->assertHeader('Idempotency-Replayed', 'true');
        $bedData = ['ward_id' => $ward['id'], 'ward_version' => 1, 'label' => 'A-01', 'daily_rate' => '0'];
        $bed = $this->postCommand('/api/v1/locations/beds', $bedData)->assertCreated()->assertJsonPath('data.daily_rate', '0.00')->json('data');
        $this->getJson('/api/v1/locations/beds/'.$bed['id'])->assertOk()->assertJsonPath('data.available', true);
        $update = ['version' => 1, 'name' => 'Renamed Ward', 'daily_rate' => null];
        $updateKey = ['Idempotency-Key' => 'location-update-confirmation'];
        $this->patchCommand('/api/v1/locations/wards/'.$ward['id'], $update, $updateKey)->assertOk()
            ->assertJsonPath('data.version', 2)->assertJsonPath('data.code', 'WARD-A')->assertJsonPath('data.daily_rate', null);
        $this->patchCommand('/api/v1/locations/wards/'.$ward['id'], $update, $updateKey)->assertOk()->assertHeader('Idempotency-Replayed', 'true');
        $this->postCommand('/api/v1/locations/wards', $wardData, $key)->assertConflict()->assertJsonPath('code', 'idempotency_outdated');
        $this->patchCommand('/api/v1/locations/beds/'.$bed['id'], ['version' => 1, 'label' => 'A-02'])->assertOk()
            ->assertJsonPath('data.version', 2)->assertJsonPath('data.ward_id', $ward['id']);
        $this->assertDatabaseCount('wards', 1);
        $this->assertDatabaseCount('beds', 1);
    }

    public function test_occupancy_is_derived_without_exposing_patient_admission_or_allocation_identity(): void
    {
        $available = Bed::factory()->create();
        $occupied = Bed::factory()->create();
        BedAllocation::factory()->create(['bed_id' => $occupied->id]);
        $inactive = Bed::factory()->create(['is_active' => false]);
        $this->getJson('/api/v1/locations/beds?available=1')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $available->id)->assertJsonMissingPath('data.0.patient_id');
        $this->getJson('/api/v1/locations/beds?available=0')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/v1/locations/beds/'.$occupied->id)->assertOk()->assertJsonPath('data.available', false)
            ->assertJsonMissingPath('data.admission_id')->assertJsonMissingPath('data.allocation_id');
        $this->getJson('/api/v1/locations/beds/'.$inactive->id)->assertOk()->assertJsonPath('data.available', false);
    }

    public function test_ward_retirement_removes_all_its_beds_from_availability_and_restore_reuses_identity(): void
    {
        $ward = Ward::factory()->create();
        $bed = Bed::factory()->for($ward)->create();
        $this->patchCommand('/api/v1/locations/wards/'.$ward->id, ['version' => 1, 'is_active' => false])->assertOk();
        $this->getJson('/api/v1/locations/beds/'.$bed->id)->assertOk()->assertJsonPath('data.available', false);
        $this->postCommand('/api/v1/locations/beds', ['ward_id' => $ward->id, 'ward_version' => 2, 'label' => 'New'])
            ->assertConflict()->assertJsonPath('code', 'ward_inactive');
        $this->patchCommand('/api/v1/locations/wards/'.$ward->id, ['version' => 2, 'is_active' => true])->assertOk();
        $this->getJson('/api/v1/locations/beds/'.$bed->id)->assertOk()->assertJsonPath('data.available', true);
        $this->assertDatabaseCount('wards', 1);
    }

    public function test_occupied_locations_cannot_retire_and_used_labels_cannot_be_rewritten(): void
    {
        $bed = Bed::factory()->create();
        $allocation = BedAllocation::factory()->create(['bed_id' => $bed->id]);
        foreach (['/beds/'.$bed->id, '/wards/'.$bed->ward_id] as $path) {
            $this->assertTablesUnchanged(['wards', 'beds', 'bed_allocations', 'idempotency_requests'], function () use ($path): void {
                $this->patchCommand('/api/v1/locations'.$path, ['version' => 1, 'is_active' => false])
                    ->assertConflict()->assertJsonPath('code', 'location_occupied');
            });
        }
        $allocation->forceFill(['released_at' => now('UTC'), 'released_by' => $allocation->assigned_by,
            'release_reason' => 'Synthetic release reason', 'version' => 2])->save();
        $this->patchCommand('/api/v1/locations/beds/'.$bed->id, ['version' => 1, 'label' => 'Replacement'])
            ->assertConflict()->assertJsonPath('code', 'bed_identity_used');
        $this->patchCommand('/api/v1/locations/beds/'.$bed->id, ['version' => 1, 'is_active' => false])->assertOk();
    }

    public function test_duplicate_codes_and_labels_are_validation_errors_but_labels_may_repeat_in_different_wards(): void
    {
        $ward = Ward::factory()->create();
        Bed::factory()->for($ward)->create(['label' => '01']);
        $this->postCommand('/api/v1/locations/wards', ['code' => $ward->code, 'name' => 'Another'])
            ->assertUnprocessable()->assertJsonValidationErrors('code');
        $data = ['ward_id' => $ward->id, 'ward_version' => 1, 'label' => '01'];
        $this->postCommand('/api/v1/locations/beds', $data)->assertUnprocessable()->assertJsonValidationErrors('label');
        $other = Ward::factory()->create();
        $data['ward_id'] = $other->id;
        $this->postCommand('/api/v1/locations/beds', $data)->assertCreated();
    }

    #[DataProvider('invalidCatalogs')]
    public function test_catalog_validation_rejects_invalid_values_and_server_fields(array $changes, string $field): void
    {
        $this->assertTablesUnchanged(['wards', 'beds', 'idempotency_requests'], function () use ($changes, $field): void {
            $this->postCommand('/api/v1/locations/wards', array_replace(['code' => 'VALID', 'name' => 'Valid name'], $changes))
                ->assertUnprocessable()->assertJsonValidationErrors($field);
        });
    }

    public static function invalidCatalogs(): array
    {
        return [[['name' => ' '], 'name'], [['code' => 'with spaces'], 'code'], [['daily_rate' => -1], 'daily_rate'],
            [['daily_rate' => '1.001'], 'daily_rate'], [['daily_rate' => '1000000000000'], 'daily_rate'],
            [['is_active' => false], 'is_active'], [['version' => 5], 'version'], [['type' => str_repeat('a', 65)], 'type']];
    }

    public function test_updates_cannot_change_ward_code_bed_ward_or_use_stale_versions(): void
    {
        $bed = Bed::factory()->create();
        $this->patchCommand('/api/v1/locations/wards/'.$bed->ward_id, ['version' => 1, 'code' => 'NEW'])->assertUnprocessable();
        $this->patchCommand('/api/v1/locations/beds/'.$bed->id, ['version' => 1, 'ward_id' => Ward::factory()->create()->id])->assertUnprocessable();
        $this->patchCommand('/api/v1/locations/beds/'.$bed->id, ['version' => 2, 'daily_rate' => '10'])->assertConflict()->assertJsonPath('code', 'version_conflict');
        $this->postCommand('/api/v1/locations/beds', ['ward_id' => $bed->ward_id, 'ward_version' => 2, 'label' => '02'])->assertConflict();
    }

    public function test_catalog_permission_is_opt_in_and_patient_permissions_do_not_grant_it(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->assertDatabaseCount('permissions', 88);
        $this->assertDatabaseCount('role_permissions', 23);
        $this->assertSame(0, Role::where('code', 'nurse')->sole()->permissions()->where('code', 'like', 'admissions.%')->count());
        $role = Role::where('code', 'admin')->sole();
        $role->permissions()->detach(Permission::where('code', 'locations.manage')->sole()->id);
        $this->getJson('/api/v1/locations/wards')->assertOk();
        $this->postCommand('/api/v1/locations/wards', ['code' => 'NEW', 'name' => 'New'])->assertForbidden();
        $role->permissions()->detach(Permission::where('code', 'locations.view')->sole()->id);
        $this->getJson('/api/v1/locations/wards')->assertForbidden();
        $this->getJson('/api/v1/locations/beds')->assertForbidden();
    }

    public function test_location_failure_rolls_back_catalog_receipt_and_success_audit(): void
    {
        $reached = $this->failNextWriteTo('idempotency_requests', 'update');
        $this->assertTablesUnchanged(['wards', 'beds', 'idempotency_requests'], function (): void {
            $this->postCommand('/api/v1/locations/wards', ['code' => 'NEW', 'name' => 'New'])->assertServerError();
        });
        $this->assertTrue($reached());
        $this->assertNoSuccessfulAudit('location.created');
    }

    public function test_lists_are_paginated_and_filter_on_active_state_and_ward(): void
    {
        $ward = Ward::factory()->create();
        Bed::factory()->count(2)->for($ward)->create();
        Bed::factory()->create();
        $this->getJson('/api/v1/locations/beds?ward_id='.$ward->id.'&per_page=1')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('meta.total', 2);
        $this->getJson('/api/v1/locations/wards?active=0')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/locations/beds?per_page=10001')->assertUnprocessable();
    }
}
