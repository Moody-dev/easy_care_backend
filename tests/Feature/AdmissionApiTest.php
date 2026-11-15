<?php

namespace Tests\Feature;

use App\Models\Admission;
use App\Models\Bed;
use App\Models\BedAllocation;
use App\Models\DoctorAssignment;
use App\Models\Encounter;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdmissionApiTest extends TestCase
{
    use RefreshDatabase;

    private User $registrar;
    private User $doctor;
    private User $nurse;
    private Encounter $visit;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now('UTC')->setDate(2026, 11, 14)->setTime(10, 0));
        $this->seed(DatabaseSeeder::class);
        $this->registrar = $this->createStaffWithRole('frontdesk');
        $this->doctor = $this->createStaffWithRole('doctor');
        $this->nurse = $this->createStaffWithRole('nurse');
        $read = ['patients.view', 'encounters.view', 'admissions.view'];
        $this->grantRolePermissions('frontdesk', [...$read, 'encounters.view_any', 'admissions.create', 'admissions.allocate', 'locations.view']);
        $this->grantRolePermissions('doctor', [...$read, 'admissions.authorize_discharge']);
        $this->grantRolePermissions('nurse', [...$read, 'encounters.view_any', 'admissions.allocate', 'admissions.complete_discharge', 'locations.view']);
        $this->visit = Encounter::factory()->create(['opened_at' => now('UTC')]);
        DoctorAssignment::factory()->for($this->visit)->create(['doctor_id' => $this->doctor->id,
            'assigned_by' => $this->registrar->id, 'assigned_at' => now('UTC')]);
        $this->actingAs($this->registrar);
    }

    public function test_admit_allocate_transfer_authorize_and_complete_keep_actual_times_history_and_retry_receipts(): void
    {
        $key = ['Idempotency-Key' => 'admission-create-confirmation'];
        $admission = $this->postCommand($this->storeUrl(), $this->opening(), $key)->assertCreated()
            ->assertJsonPath('data.status', 'admitted')->assertJsonPath('data.workflow_version', 1)
            ->assertJsonPath('data.current_allocation', null)->json('data');
        $this->postCommand($this->storeUrl(), $this->opening(), $key)->assertOk()->assertHeader('Idempotency-Replayed', 'true');
        $first = Bed::factory()->create();
        $second = Bed::factory()->create();
        $this->travel(5)->minutes();
        $admission = $this->command($admission, 'allocate', $this->bedData($first))->assertOk()->json('data');
        $oldAllocation = $admission['current_allocation'];
        $this->travel(5)->minutes();
        $transferData = $this->data($admission, 'transfer') + $this->bedData($second);
        $transferKey = ['Idempotency-Key' => 'admission-transfer-confirmation'];
        $admission = $this->postCommand($this->url($admission, 'transfer'), $transferData, $transferKey)->assertOk()
            ->assertJsonPath('data.workflow_version', 3)->assertJsonPath('data.version', 1)->json('data');
        $this->postCommand($this->url($admission, 'transfer'), $transferData, $transferKey)->assertOk()->assertHeader('Idempotency-Replayed', 'true');
        $this->postCommand($this->storeUrl(), $this->opening(), $key)->assertConflict()->assertJsonPath('code', 'idempotency_outdated');
        $this->getJson('/api/v1/locations/beds/'.$first->id)->assertOk()->assertJsonPath('data.available', true);
        $this->getJson('/api/v1/locations/beds/'.$second->id)->assertOk()->assertJsonPath('data.available', false);
        $this->assertSame($admission['current_allocation']['assigned_at'], BedAllocation::findOrFail($oldAllocation['id'])->released_at->toISOString());
        $this->signIn($this->doctor);
        $this->travel(5)->minutes();
        $admission = $this->command($admission, 'authorize-discharge', ['discharge_summary' => 'Private discharge summary'])->assertOk()
            ->assertJsonPath('data.status', 'discharge_authorized')->assertJsonPath('data.version', 2)
            ->assertJsonPath('data.authorized_by', $this->doctor->id)->json('data');
        $this->assertDatabaseHas('current_bed_occupancies', ['bed_id' => $second->id]);
        $this->signIn($this->nurse);
        $this->travel(5)->minutes();
        $complete = $this->data($admission, 'complete-discharge');
        $completeKey = ['Idempotency-Key' => 'admission-complete-confirmation'];
        $done = $this->postCommand($this->url($admission, 'complete-discharge'), $complete, $completeKey)->assertOk()
            ->assertJsonPath('data.status', 'discharged')->assertJsonPath('data.version', 3)
            ->assertJsonPath('data.workflow_version', 5)->assertJsonPath('data.current_allocation', null)->json('data');
        $this->postCommand($this->url($admission, 'complete-discharge'), $complete, $completeKey)->assertOk()->assertHeader('Idempotency-Replayed', 'true');
        $this->assertSame('2026-11-14T10:00:00.000000Z', $done['admitted_at']);
        $this->assertSame('2026-11-14T10:15:00.000000Z', $done['authorized_at']);
        $this->assertSame('2026-11-14T10:20:00.000000Z', $done['discharged_at']);
        $this->assertSame($admission['discharge_reason'], $done['discharge_reason']);
        $this->assertSame($this->nurse->id, $done['discharged_by']);
        $this->assertDatabaseCount('current_bed_occupancies', 0);
        $this->assertDatabaseCount('bed_allocations', 2);
        $this->assertDatabaseCount('admission_revisions', 5);
        $this->getJson('/api/v1/admissions/'.$done['id'].'/history?per_page=2')->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 5)->assertJsonPath('data.0.operation', 'admitted');
        $this->getJson('/api/v1/admissions/'.$done['id'].'/allocations')->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.released_by', $this->registrar->id)->assertJsonPath('data.1.released_by', $this->nurse->id);
        $this->assertSame('open', $this->visit->fresh()->status);
        $this->assertAuditExcludes(['Private admission reason', 'Private discharge summary']);
        $this->assertAuditEventRecorded('admission.discharged', $this->nurse->id, 'admission', $done['id']);
    }

    public function test_release_and_reallocation_increment_workflow_version_and_reject_an_old_bedless_tab(): void
    {
        $initial = $this->admit();
        $bed = Bed::factory()->create();
        $assigned = $this->command($initial, 'allocate', $this->bedData($bed))->assertOk()->json('data');
        $released = $this->command($assigned, 'release')->assertOk()->assertJsonPath('data.workflow_version', 3)->json('data');
        $this->assertTablesUnchanged($this->tables(), function () use ($initial, $bed): void {
            $this->command($initial, 'allocate', $this->bedData($bed))->assertConflict()->assertJsonPath('code', 'version_conflict');
        });
        $this->command($released, 'allocate', $this->bedData($bed))->assertOk()->assertJsonPath('data.workflow_version', 4);
        $this->assertDatabaseCount('bed_allocations', 2);
    }

    public function test_occupied_destination_conflict_does_not_release_the_original_bed(): void
    {
        $first = Bed::factory()->create();
        $second = Bed::factory()->create();
        $admission = $this->command($this->admit(), 'allocate', $this->bedData($first))->assertOk()->json('data');
        BedAllocation::factory()->create(['bed_id' => $second->id]);
        $this->assertTablesUnchanged($this->tables(), function () use ($admission, $second): void {
            $this->command($admission, 'transfer', $this->bedData($second))->assertConflict()->assertJsonPath('code', 'bed_unavailable');
        });
        $this->assertSame($first->id, Admission::findOrFail($admission['id'])->currentOccupancy->bed_id);
    }

    #[DataProvider('failureBoundaries')]
    public function test_transfer_rolls_back_release_new_allocation_history_and_receipt_after_late_failure(string $table, string $operation): void
    {
        $admission = $this->command($this->admit(), 'allocate', $this->bedData(Bed::factory()->create()))->assertOk()->json('data');
        $second = Bed::factory()->create();
        $reached = $this->failNextWriteTo($table, $operation);
        $this->assertTablesUnchanged($this->tables(), function () use ($admission, $second): void {
            $this->command($admission, 'transfer', $this->bedData($second))->assertServerError();
        });
        $this->assertTrue($reached());
        $this->assertNoSuccessfulAudit('admission.transferred');
    }

    public static function failureBoundaries(): array
    {
        return [['bed_allocations', 'update'], ['bed_allocations', 'insert'], ['admission_revisions', 'insert'], ['idempotency_requests', 'update']];
    }

    public function test_failed_discharge_completion_rolls_back_the_bed_release(): void
    {
        $admission = $this->command($this->admit(), 'allocate', $this->bedData(Bed::factory()->create()))->assertOk()->json('data');
        $this->signIn($this->doctor);
        $admission = $this->command($admission, 'authorize-discharge')->assertOk()->json('data');
        $this->signIn($this->nurse);
        $reached = $this->failNextWriteTo('admissions', 'update');
        $this->assertTablesUnchanged($this->tables(), function () use ($admission): void {
            $this->command($admission, 'complete-discharge')->assertServerError();
        });
        $this->assertTrue($reached());
        $this->assertDatabaseCount('current_bed_occupancies', 1);
        $this->assertNoSuccessfulAudit('admission.discharged');
    }

    public function test_nurse_cannot_complete_without_authorization_and_authorization_does_not_free_a_bed(): void
    {
        $admission = $this->admit();
        $this->signIn($this->nurse);
        $this->assertTablesUnchanged($this->tables(), function () use ($admission): void {
            $this->command($admission, 'complete-discharge')->assertConflict()->assertJsonPath('code', 'discharge_not_authorized');
        });
        $this->signIn($this->doctor);
        $authorized = $this->command($admission, 'authorize-discharge')->assertOk()->json('data');
        $this->signIn($this->nurse);
        $this->command($authorized, 'complete-discharge')->assertOk()->assertJsonPath('data.current_allocation', null);
    }

    #[DataProvider('authorityFailures')]
    public function test_discharge_authority_requires_current_doctor_then_a_different_nurse(string $scenario): void
    {
        $admission = $this->admit();
        $this->grantRolePermissions('frontdesk', ['admissions.authorize_discharge', 'admissions.complete_discharge']);
        if ($scenario === 'not-doctor') {
            $this->assertTablesUnchanged($this->tables(), fn () => $this->command($admission, 'authorize-discharge')->assertForbidden());

            return;
        }
        if ($scenario === 'other-doctor') {
            $this->grantRolePermissions('doctor', ['encounters.view_any']);
            $this->signIn($this->createStaffWithRole('doctor'));
            $this->assertTablesUnchanged($this->tables(), fn () => $this->command($admission, 'authorize-discharge')->assertForbidden());

            return;
        }
        $this->signIn($this->doctor);
        $admission = $this->command($admission, 'authorize-discharge')->assertOk()->json('data');
        if ($scenario === 'same-person') {
            $this->doctor->roles()->attach(Role::where('code', 'nurse')->sole());
        } else {
            $this->signIn($this->registrar);
        }
        $this->assertTablesUnchanged($this->tables(), fn () => $this->command($admission, 'complete-discharge')->assertForbidden());
    }

    public static function authorityFailures(): array
    {
        return [['not-doctor'], ['other-doctor'], ['not-nurse'], ['same-person']];
    }

    public function test_stale_assignment_visit_patient_and_allocation_versions_are_rejected(): void
    {
        $opening = $this->opening();
        $opening['patient_version'] = 2;
        $this->postCommand($this->storeUrl(), $opening)->assertConflict();
        $admission = $this->admit();
        $this->command($admission, 'allocate', $this->bedData(Bed::factory()->create()) + ['encounter_version' => 2])->assertConflict();
        $assigned = $this->command($admission, 'allocate', $this->bedData(Bed::factory()->create()))->assertOk()->json('data');
        $data = $this->data($assigned, 'release');
        $data['allocation_version'] = 2;
        $this->postCommand($this->url($assigned, 'release'), $data)->assertConflict()->assertJsonPath('code', 'allocation_conflict');
        $this->signIn($this->doctor);
        $data = $this->data($assigned, 'authorize-discharge');
        $data['assignment_version'] = 2;
        $this->postCommand($this->url($assigned, 'authorize-discharge'), $data)->assertConflict()->assertJsonPath('code', 'assignment_conflict');
    }

    public function test_one_active_admission_per_patient_and_one_episode_per_visit(): void
    {
        $this->admit();
        $this->postCommand($this->storeUrl(), $this->opening())->assertConflict()->assertJsonPath('code', 'admission_exists');
        $other = Encounter::factory()->for($this->visit->patient)->create();
        $this->postCommand('/api/v1/encounters/'.$other->id.'/admission', $this->opening())->assertConflict()->assertJsonPath('code', 'admission_exists');
        $this->assertDatabaseCount('admissions', 1);
        $this->assertDatabaseCount('admission_revisions', 1);
    }

    public function test_restricted_records_disappear_from_lists_details_history_commands_and_receipts(): void
    {
        $key = ['Idempotency-Key' => 'restricted-admission-confirmation'];
        $admission = $this->postCommand($this->storeUrl(), $this->opening(), $key)->assertCreated()->json('data');
        $this->visit->patient->forceFill(['access_status' => 'restricted', 'version' => 2])->save();
        foreach (['', '/history', '/allocations'] as $suffix) {
            $this->getJson('/api/v1/admissions/'.$admission['id'].$suffix)->assertNotFound();
        }
        $this->getJson('/api/v1/admissions')->assertOk()->assertJsonCount(0, 'data');
        $this->postCommand($this->storeUrl(), $this->opening(), $key)->assertNotFound();
        $this->command($admission, 'allocate', $this->bedData(Bed::factory()->create()))->assertNotFound();
    }

    public function test_other_clinician_visibility_and_permission_revocation_apply_to_reads_writes_and_replay(): void
    {
        $key = ['Idempotency-Key' => 'revoked-admission-confirmation'];
        $admission = $this->postCommand($this->storeUrl(), $this->opening(), $key)->assertCreated()->json('data');
        $other = $this->createStaffWithRole('doctor');
        $this->signIn($other);
        $this->getJson('/api/v1/admissions')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/admissions/'.$admission['id'])->assertNotFound();
        $this->command($admission, 'authorize-discharge')->assertNotFound();
        $this->signIn($this->registrar);
        Role::where('code', 'frontdesk')->sole()->permissions()->detach(Permission::where('code', 'admissions.create')->sole()->id);
        $this->postCommand($this->storeUrl(), $this->opening(), $key)->assertForbidden();
        $this->assertDatabaseCount('admissions', 1);
    }

    public function test_changed_payload_target_and_expired_receipts_cannot_replay(): void
    {
        $key = ['Idempotency-Key' => 'strict-admission-confirmation'];
        $this->postCommand($this->storeUrl(), $this->opening(), $key)->assertCreated();
        $data = $this->opening();
        $data['reason'] = 'A different private admission reason';
        $this->postCommand($this->storeUrl(), $data, $key)->assertConflict()->assertJsonPath('code', 'idempotency_conflict');
        $other = Encounter::factory()->create();
        $this->postCommand('/api/v1/encounters/'.$other->id.'/admission', $this->opening(), $key)->assertConflict()->assertJsonPath('code', 'idempotency_conflict');
        $this->travel(1)->days();
        $this->postCommand($this->storeUrl(), $this->opening(), $key)->assertConflict()->assertJsonPath('code', 'idempotency_expired');
    }

    public function test_backward_clock_cannot_admit_allocate_authorize_or_complete(): void
    {
        $admission = $this->admit();
        $this->travel(-1)->minutes();
        $this->command($admission, 'allocate', $this->bedData(Bed::factory()->create()))->assertConflict()->assertJsonPath('code', 'admission_clock_conflict');
        $this->signIn($this->doctor);
        $this->command($admission, 'authorize-discharge')->assertConflict()->assertJsonPath('code', 'admission_clock_conflict');
        $this->travel(2)->minutes();
        $admission = $this->command($admission, 'authorize-discharge')->assertOk()->json('data');
        $this->travel(-1)->minutes();
        $this->signIn($this->nurse);
        $this->command($admission, 'complete-discharge')->assertConflict()->assertJsonPath('code', 'admission_clock_conflict');
    }

    public function test_list_filters_pagination_and_timezone_follow_original_episode_identity(): void
    {
        $bed = Bed::factory()->create();
        $admission = $this->command($this->admit(), 'allocate', $this->bedData($bed))->assertOk()->json('data');
        $this->getJson('/api/v1/admissions?ward_id='.$bed->ward_id.'&patient_id='.$this->visit->patient_id.'&per_page=1')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $admission['id'])->assertJsonPath('timezone', 'Africa/Lagos');
        $this->getJson('/api/v1/admissions?status=discharged')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/admissions?per_page=10001')->assertUnprocessable();
        $this->getJson('/api/v1/admissions/options')->assertOk()->assertJsonPath('data.discharge_completer', 'different_nurse');
    }

    #[DataProvider('invalidOpenings')]
    public function test_registration_validation_protects_server_owned_identity_and_times(array $changes, string $field): void
    {
        $data = array_replace($this->opening(), $changes);
        $this->assertTablesUnchanged($this->tables(), function () use ($data, $field): void {
            $this->postCommand($this->storeUrl(), $data)->assertUnprocessable()->assertJsonValidationErrors($field);
        });
    }

    public static function invalidOpenings(): array
    {
        return [
            [['identity_verified' => false], 'identity_verified'], [['reason' => '  '], 'reason'],
            [['admitted_at' => '2000-01-01T00:00:00Z'], 'admitted_at'], [['admitted_by' => 1], 'admitted_by'],
            [['patient_id' => 1], 'patient_id'], [['encounter_version' => 0], 'encounter_version'],
        ];
    }

    public function test_missing_submission_key_and_anonymous_access_fail_before_domain_changes(): void
    {
        $this->postJson($this->storeUrl(), $this->opening())->assertUnprocessable()->assertJsonValidationErrors('idempotency_key');
        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $this->getJson('/api/v1/admissions')->assertUnauthorized();
        $this->assertDatabaseCount('admissions', 0);
    }

    public function test_unicode_summary_limits_fail_validation_before_storage_and_preserve_accepted_text(): void
    {
        $admission = $this->admit();
        $this->signIn($this->doctor);
        $this->command($admission, 'authorize-discharge', ['discharge_summary' => str_repeat('😀', 17000)])
            ->assertUnprocessable()->assertJsonValidationErrors('discharge_summary');
        $summary = str_repeat('😀', 16000);
        $this->command($admission, 'authorize-discharge', ['discharge_summary' => $summary])->assertOk()
            ->assertJsonPath('data.discharge_summary', $summary);
    }

    public function test_database_failures_hide_private_sql_and_bindings_from_responses_and_logs(): void
    {
        Log::spy();
        $hit = false;
        DB::listen(function (QueryExecuted $event) use (&$hit): void {
            if (! $hit && preg_match('/^insert into [`"]admissions[`"]/i', $event->sql) === 1) {
                $hit = true;
                throw new QueryException('testing', 'Private admission SQL', ['Private admission reason'], new PDOException('Private failure'));
            }
        });
        $this->assertTablesUnchanged($this->tables(), function (): void {
            $this->assertApiFailure($this->postCommand($this->storeUrl(), $this->opening()), 500, 'server_error');
        });
        $this->assertTrue($hit);
        Log::shouldHaveReceived('error')->once()->with('An admission or location database operation failed.');
    }

    private function opening(): array
    {
        return ['encounter_version' => 1, 'patient_version' => 1, 'identity_verified' => true, 'reason' => 'Private admission reason'];
    }

    private function storeUrl(): string
    {
        return '/api/v1/encounters/'.$this->visit->id.'/admission';
    }

    private function admit(): array
    {
        return $this->postCommand($this->storeUrl(), $this->opening())->assertCreated()->json('data');
    }

    private function bedData(Bed $bed): array
    {
        return ['bed_id' => $bed->id, 'bed_version' => $bed->version, 'ward_version' => $bed->ward->version];
    }

    private function data(array $admission, string $action): array
    {
        $data = ['version' => $admission['version'], 'workflow_version' => $admission['workflow_version'],
            'encounter_version' => $admission['encounter_version'], 'reason' => 'Private admission reason'];
        if (in_array($action, ['release', 'transfer', 'complete-discharge'], true)) {
            $data += ['allocation_id' => $admission['current_allocation']['id'] ?? null,
                'allocation_version' => $admission['current_allocation']['version'] ?? null];
        }
        if ($action === 'authorize-discharge') {
            $data += ['assignment_id' => $admission['current_assignment']['id'], 'assignment_version' => $admission['current_assignment']['version']];
        }

        return $data;
    }

    private function url(array $admission, string $action): string
    {
        return '/api/v1/admissions/'.$admission['id'].'/'.$action;
    }

    private function command(array $admission, string $action, array $extra = []): \Illuminate\Testing\TestResponse
    {
        return $this->postCommand($this->url($admission, $action), array_replace($this->data($admission, $action), $extra));
    }

    private function signIn(User $actor): void
    {
        $this->flushSession();
        $this->actingAs($actor);
    }

    private function tables(): array
    {
        return ['patients', 'encounters', 'doctor_assignments', 'admissions', 'bed_allocations', 'admission_revisions', 'idempotency_requests'];
    }
}
