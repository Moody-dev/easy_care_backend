<?php

namespace Tests\Feature;

use App\Models\DoctorAssignment;
use App\Models\Encounter;
use App\Models\InvestigationItem;
use App\Models\InvestigationOrder;
use App\Models\InvestigationSchema;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\InvestigationCatalog;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class InvestigationRequestApiTest extends TestCase
{
    use RefreshDatabase;

    private User $doctor;
    private User $diagnostics;
    private Encounter $visit;
    private DoctorAssignment $assignment;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->grantRolePermissions('doctor', ['patients.view', 'encounters.view', 'investigations.view', 'investigations.request',
            'investigations.review', 'investigations.catalog.view', 'investigations.catalog.publish', 'encounters.progress']);
        $this->grantRolePermissions('diagnostics-and-radiologist', ['patients.view', 'encounters.view', 'encounters.view_any',
            'investigations.view', 'investigations.collect', 'investigations.process', 'investigations.cancel', 'investigations.review']);
        $this->doctor = $this->createStaffWithRole('doctor');
        $this->diagnostics = $this->createStaffWithRole('diagnostics-and-radiologist');
        $this->visit = Encounter::factory()->create(['opened_at' => now('UTC')->subHours(2)]);
        $this->assignment = DoctorAssignment::factory()->create(['encounter_id' => $this->visit->id, 'doctor_id' => $this->doctor->id, 'assigned_at' => now('UTC')->subHour()]);
        $this->login($this->doctor);
    }

    private function login(User $user): static
    {
        $this->flushSession();
        $this->actingAs($user);

        return $this;
    }
    private function form(string $department = 'laboratory', string $code = 'synthetic-panel'): InvestigationSchema
    {
        return app(InvestigationCatalog::class)->publish(['code' => $code, 'name' => 'Synthetic '.$code, 'department' => $department,
            'specimen' => $department === 'laboratory' ? 'Synthetic specimen' : null, 'is_active' => true, 'title' => 'Synthetic approved form',
            'service' => ['code' => 'service-'.$code, 'name' => 'Synthetic service'],
            'fields' => ['value' => ['type' => 'number', 'unit' => 'synthetic-unit', 'required' => true]]],
            $this->doctor->id, 0, 'Synthetic approval', 'Initial approved publication');
    }

    private function data(?InvestigationSchema $schema = null): array
    {
        $schema ??= $this->form();

        return ['patient_version' => $this->visit->patient->version, 'encounter_version' => $this->visit->version,
            'assignment_id' => $this->assignment->id, 'assignment_version' => $this->assignment->version,
            'clinical_context' => 'Private investigation context', 'reason' => 'Private request reason',
            'items' => [['test_id' => $schema->test_id, 'test_version' => $schema->version, 'schema_id' => $schema->id, 'priority' => 'routine']]];
    }

    private function order(?array $data = null, array $headers = []): array
    {
        return $this->postCommand('/api/v1/encounters/'.$this->visit->id.'/investigations', $data ?? $this->data(), $headers)->assertCreated()->json('data');
    }

    private function path(array $order, int $index = 0): string
    {
        return '/api/v1/investigations/orders/'.$order['id'].'/items/'.$order['items'][$index]['id'];
    }

    private function action(array $order): array
    {
        return ['encounter_version' => 1, 'order_version' => $order['version'], 'version' => $order['items'][0]['version'], 'reason' => 'Private diagnostics action'];
    }

    public function test_request_pins_original_identity_and_confirmed_retry_creates_one_order(): void
    {
        $data = $this->data();
        $key = ['Idempotency-Key' => 'investigation-request-confirmation'];
        $order = $this->order($data, $key);
        $this->assertSame($this->doctor->id, $order['ordered_by']);
        $this->assertSame($this->visit->patient_id, $order['patient_id']);
        $this->assertSame($data['items'][0]['schema_id'], $order['items'][0]['schema_id']);
        $this->postCommand('/api/v1/encounters/'.$this->visit->id.'/investigations', $data, $key)->assertOk()
            ->assertHeader('Idempotency-Replayed', 'true')->assertJsonPath('data.id', $order['id']);
        $this->assertDatabaseCount('investigation_orders', 1);
        $this->assertDatabaseCount('investigation_items', 1);
        $this->assertDatabaseCount('investigation_revisions', 1);
        $this->assertDatabaseCount('idempotency_requests', 1);
        $this->assertAuditEventRecorded('command.replayed', $this->doctor->id, 'investigation_order', $order['id']);
        $this->assertAuditExcludes(['Private investigation context', 'Private request reason']);
    }

    public function test_laboratory_collection_then_processing_retains_server_times_actor_and_history(): void
    {
        $order = $this->order();
        $this->login($this->diagnostics);
        $this->postCommand($this->path($order).'/process', $this->action($order))->assertConflict()->assertJsonPath('code', 'investigation_transition_conflict');
        $data = $this->action($order) + ['specimen_ref' => 'Private specimen reference'];
        $key = ['Idempotency-Key' => 'investigation-collection-confirmation'];
        $collected = $this->postCommand($this->path($order).'/collect', $data, $key)->assertOk()->assertJsonPath('data.status', 'collected')->json('data');
        $this->assertSame($this->diagnostics->id, $collected['items'][0]['collected_by']);
        $this->assertNotNull($collected['items'][0]['collected_at']);
        $this->postCommand($this->path($order).'/collect', $data, $key)->assertOk()->assertHeader('Idempotency-Replayed', 'true');
        $processed = $this->postCommand($this->path($collected).'/process', $this->action($collected))->assertOk()->assertJsonPath('data.status', 'processing')->json('data');
        $this->assertSame($collected['items'][0]['collected_at'], $processed['items'][0]['collected_at']);
        $this->assertSame($this->diagnostics->id, $processed['items'][0]['processing_by']);
        $this->postCommand($this->path($processed).'/cancel', $this->action($processed))->assertConflict();
        $this->getJson('/api/v1/investigations/orders/'.$order['id'].'/history')->assertOk()->assertJsonPath('meta.total', 3)
            ->assertJsonPath('data.0.operation', 'processing')->assertJsonPath('data.2.snapshot.items.0.status', 'requested');
        $this->assertAuditExcludes(['Private specimen reference', 'Private diagnostics action']);
    }

    public function test_radiology_skips_collection_and_order_status_is_derived_from_individual_items(): void
    {
        $data = $this->data($this->form('radiology', 'synthetic-scan'));
        $lab = $this->form();
        $data['items'][] = ['test_id' => $lab->test_id, 'schema_id' => $lab->id, 'test_version' => 1, 'priority' => 'urgent'];
        $order = $this->order($data);
        $this->login($this->diagnostics);
        $this->postCommand($this->path($order).'/collect', $this->action($order) + ['specimen_ref' => 'No radiology collection'])->assertConflict();
        $processed = $this->postCommand($this->path($order).'/process', $this->action($order))->assertOk()->json('data');
        $this->assertNull($processed['items'][0]['collected_at']);
        $this->assertSame('requested', $processed['items'][1]['status']);
        $this->getJson('/api/v1/investigations/queue?per_page=1')->assertOk()->assertJsonPath('data.0.priority', 'urgent')->assertJsonPath('meta.total', 2);
        $this->getJson('/api/v1/investigations/queue?department=radiology&status=processing')->assertOk()->assertJsonPath('meta.total', 1);
    }

    public function test_original_doctor_can_cancel_requested_items_and_diagnostics_can_cancel_collected_items(): void
    {
        $order = $this->order();
        $cancelled = $this->postCommand($this->path($order).'/cancel', $this->action($order))->assertOk()->assertJsonPath('data.status', 'cancelled')->json('data');
        $this->assertSame($this->doctor->id, $cancelled['items'][0]['cancelled_by']);
        $this->getJson('/api/v1/investigations/queue')->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson('/api/v1/investigations/queue?active=0')->assertOk()->assertJsonPath('meta.total', 1);
        $next = $this->order($this->data(InvestigationSchema::firstOrFail()));
        $this->login($this->diagnostics);
        $collected = $this->postCommand($this->path($next).'/collect', $this->action($next) + ['specimen_ref' => 'Synthetic specimen'])->assertOk()->json('data');
        $this->postCommand($this->path($collected).'/cancel', $this->action($collected))->assertOk()->assertJsonPath('data.items.0.specimen_ref', 'Synthetic specimen');
    }

    public function test_only_the_current_doctor_can_order_and_other_roles_need_explicit_processing_authority(): void
    {
        $data = $this->data();
        $other = $this->createStaffWithRole('doctor');
        $this->login($other)->postCommand('/api/v1/encounters/'.$this->visit->id.'/investigations', $data)->assertNotFound();
        $this->login($this->diagnostics)->postCommand('/api/v1/encounters/'.$this->visit->id.'/investigations', $data)->assertForbidden();
        $this->login($this->doctor);
        $order = $this->order($data);
        $this->grantRolePermissions('doctor', ['investigations.collect', 'investigations.process', 'encounters.view_any']);
        $this->postCommand($this->path($order).'/collect', $this->action($order) + ['specimen_ref' => 'Synthetic specimen'])->assertForbidden();
        $this->login($other)->postCommand($this->path($order).'/cancel', $this->action($order))->assertForbidden();
    }

    public function test_restricted_patient_cross_order_items_and_revoked_permissions_are_isolated_in_reads_writes_history_and_replays(): void
    {
        $data = $this->data();
        $key = ['Idempotency-Key' => 'investigation-private-confirmation'];
        $first = $this->order($data, $key);
        $second = $this->order($data);
        $this->getJson('/api/v1/investigations/orders/'.$first['id'].'/items/'.$second['items'][0]['id'])->assertNotFound();
        $this->postCommand('/api/v1/investigations/orders/'.$first['id'].'/items/'.$second['items'][0]['id'].'/cancel', $this->action($first))->assertNotFound();
        $this->visit->patient->forceFill(['access_status' => 'restricted', 'version' => 2])->save();
        $this->getJson('/api/v1/investigations/orders')->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson('/api/v1/investigations/queue')->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson('/api/v1/investigations/orders/'.$first['id'].'/history')->assertNotFound();
        $this->postCommand('/api/v1/encounters/'.$this->visit->id.'/investigations', $data, $key)->assertNotFound();
        Role::where('code', 'doctor')->sole()->permissions()->detach(Permission::where('code', 'investigations.view')->sole()->id);
        $this->getJson('/api/v1/investigations/orders/'.$first['id'])->assertForbidden();
    }

    public function test_active_orders_keep_original_schema_after_catalog_revision_and_retirement(): void
    {
        $schema = $this->form();
        $order = $this->order($this->data($schema));
        $definition = $schema->schema_json;
        $definition['name'] = 'Revised synthetic catalog name';
        $definition['is_active'] = false;
        $definition['fields']['value']['unit'] = 'revised-unit';
        app(InvestigationCatalog::class)->publish($definition, $this->doctor->id, 1, 'Synthetic second approval', 'Retire catalog entry');
        $this->getJson('/api/v1/investigations/orders/'.$order['id'])->assertOk()
            ->assertJsonPath('data.items.0.schema_id', $schema->id)->assertJsonPath('data.items.0.test_snapshot.name', 'Synthetic synthetic-panel');
        $this->login($this->diagnostics)->postCommand($this->path($order).'/collect', $this->action($order) + ['specimen_ref' => 'Synthetic specimen'])->assertOk();
        $this->assertSame('synthetic-unit', InvestigationItem::sole()->schema->schema_json['fields']['value']['unit']);
    }

    public function test_stale_versions_and_changed_submission_keys_cannot_duplicate_or_overwrite_saved_work(): void
    {
        $data = $this->data();
        $key = ['Idempotency-Key' => 'investigation-stale-confirmation'];
        $order = $this->order($data, $key);
        $data['reason'] = 'Changed request reason';
        $this->postCommand('/api/v1/encounters/'.$this->visit->id.'/investigations', $data, $key)->assertConflict()->assertJsonPath('code', 'idempotency_conflict');
        $this->login($this->diagnostics);
        $this->postCommand($this->path($order).'/collect', $this->action($order) + ['specimen_ref' => 'Synthetic specimen'])->assertOk();
        $this->postCommand($this->path($order).'/cancel', $this->action($order))->assertConflict()->assertJsonPath('code', 'version_conflict');
        $this->login($this->doctor);
        $data['reason'] = 'Private request reason';
        $this->postCommand('/api/v1/encounters/'.$this->visit->id.'/investigations', $data, $key)->assertConflict()->assertJsonPath('code', 'idempotency_outdated');
        $this->assertDatabaseCount('investigation_orders', 1);
    }

    public function test_unknown_or_stale_forms_and_one_bad_item_roll_back_the_entire_request(): void
    {
        $data = $this->data();
        $data['items'][] = ['test_id' => 9999999, 'schema_id' => 9999999, 'test_version' => 1, 'priority' => 'urgent'];
        $this->postCommand('/api/v1/encounters/'.$this->visit->id.'/investigations', $data)->assertNotFound();
        $this->assertDatabaseCount('investigation_orders', 0);
        $this->assertDatabaseCount('investigation_items', 0);
        $this->assertDatabaseCount('idempotency_requests', 0);
        $data['items'] = [$data['items'][0]];
        $data['items'][0]['test_version'] = 2;
        $this->postCommand('/api/v1/encounters/'.$this->visit->id.'/investigations', $data)->assertConflict();
        $data['items'][0]['test_version'] = 1;
        $data['items'][0]['schema_id'] = $this->form('radiology', 'synthetic-other')->id;
        $this->postCommand('/api/v1/encounters/'.$this->visit->id.'/investigations', $data)->assertConflict();
    }

    public function test_failed_history_or_audit_insert_rolls_back_every_related_row_and_receipt(): void
    {
        $data = $this->data();
        $triggered = $this->failNextWriteTo('investigation_revisions', 'insert');
        $this->assertTablesUnchanged(['investigation_orders', 'investigation_items', 'investigation_revisions', 'idempotency_requests'], function () use ($data): void {
            $this->postCommand('/api/v1/encounters/'.$this->visit->id.'/investigations', $data)->assertStatus(500);
        });
        $this->assertTrue($triggered());
        $order = $this->order($data);
        $this->login($this->diagnostics);
        $triggered = $this->failNextWriteTo('audit_events', 'insert');
        $this->assertTablesUnchanged(['investigation_orders', 'investigation_items', 'investigation_revisions', 'idempotency_requests'], function () use ($order): void {
            $this->postCommand($this->path($order).'/collect', $this->action($order) + ['specimen_ref' => 'Synthetic specimen'])->assertStatus(500);
        });
        $this->assertTrue($triggered());
    }

    public function test_pending_items_block_visit_closure_before_assignments_change_and_all_cancelled_items_allow_it(): void
    {
        $order = $this->order();
        $accept = ['version' => 1, 'assignment_id' => $this->assignment->id, 'assignment_version' => 1, 'reason' => 'Accept saved visit'];
        $this->postCommand('/api/v1/encounters/'.$this->visit->id.'/accept', $accept)->assertOk();
        $complete = ['version' => 2, 'assignment_id' => $this->assignment->id, 'assignment_version' => 2, 'reason' => 'Complete saved visit'];
        $this->postCommand('/api/v1/encounters/'.$this->visit->id.'/complete', $complete)->assertConflict()->assertJsonPath('code', 'encounter_pending_investigations');
        $action = $this->action($order);
        $action['encounter_version'] = 2;
        $cancelled = $this->postCommand($this->path($order).'/cancel', $action)->assertOk()->json('data');
        $this->postCommand('/api/v1/encounters/'.$this->visit->id.'/complete', $complete)->assertOk();
        $closed = $this->action($cancelled);
        $closed['encounter_version'] = 3;
        $this->login($this->diagnostics)->postCommand($this->path($order).'/collect', $closed + ['specimen_ref' => 'Synthetic specimen'])
            ->assertConflict()->assertJsonPath('code', 'investigation_visit_closed');
        $this->assertSame('completed', $this->assignment->fresh()->status);
    }

    #[DataProvider('badInput')]
    public function test_invalid_requests_cannot_supply_patient_actors_times_or_unbounded_items(string $case): void
    {
        $data = $this->data();
        match ($case) {
            'patient' => $data['patient_id'] = 1, 'actor' => $data['ordered_by'] = 1,
            'time' => $data['requested_at'] = '2026-01-01', 'duplicate' => $data['items'][] = $data['items'][0],
            'empty' => $data['items'] = [], 'large' => $data['items'] = array_fill(0, 21, $data['items'][0]),
            'priority' => $data['items'][0]['priority'] = 'emergency-rule', 'schema' => $data['items'][0]['schema_id'] = 0,
            'item-actor' => $data['items'][0]['collected_by'] = 1, 'reason' => $data['reason'] = ' ',
        };
        $this->postCommand('/api/v1/encounters/'.$this->visit->id.'/investigations', $data)->assertUnprocessable();
        $this->assertDatabaseCount('investigation_orders', 0);
        $this->assertDatabaseCount('idempotency_requests', 0);
    }

    public static function badInput(): array
    {
        return array_map(fn (string $case): array => [$case], ['patient', 'actor', 'time', 'duplicate', 'empty', 'large', 'priority', 'schema', 'item-actor', 'reason']);
    }

    public function test_guest_inactive_default_roles_and_history_permissions_cannot_bypass_access(): void
    {
        $order = $this->order();
        $this->flushSession();
        auth()->guard('web')->logout();
        $this->getJson('/api/v1/investigations/queue')->assertUnauthorized()->assertHeader('Cache-Control', 'no-store, private');
        $admin = $this->createStaffWithRole('admin');
        $this->login($admin)->getJson('/api/v1/investigations/orders')->assertForbidden();
        $this->doctor->forceFill(['is_active' => false])->save();
        $this->login($this->doctor)->getJson('/api/v1/investigations/orders/'.$order['id'])->assertUnauthorized();
        Role::where('code', 'diagnostics-and-radiologist')->sole()->permissions()->detach(Permission::where('code', 'investigations.review')->sole()->id);
        $this->login($this->diagnostics)->getJson('/api/v1/investigations/orders/'.$order['id'])->assertOk();
        $this->getJson('/api/v1/investigations/orders/'.$order['id'].'/history')->assertForbidden();
    }

    public function test_stale_patient_visit_and_assignment_versions_reject_requests_without_partial_records(): void
    {
        $input = $this->data();
        foreach (['patient_version', 'encounter_version', 'assignment_version', 'assignment_id'] as $key) {
            $changed = $input;
            $changed[$key]++;
            $this->postCommand('/api/v1/encounters/'.$this->visit->id.'/investigations', $changed)->assertConflict();
        }
        $this->postJson('/api/v1/encounters/'.$this->visit->id.'/investigations', $input)->assertUnprocessable()->assertJsonValidationErrors('idempotency_key');
        $this->assertDatabaseCount('investigation_orders', 0);
        $this->assertDatabaseCount('idempotency_requests', 0);
    }

    public function test_queue_and_register_filters_are_bounded_and_readers_cannot_mutate_items(): void
    {
        $order = $this->order();
        $this->order($this->data(InvestigationSchema::firstOrFail()));
        $this->getJson('/api/v1/investigations/orders?patient_id='.$this->visit->patient_id.'&per_page=1&page=2')->assertOk()->assertJsonPath('meta.total', 2)->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/investigations/queue?order_id='.$order['id'])->assertOk()->assertJsonPath('meta.total', 1);
        foreach (['per_page=101', 'page=0', 'status=released', 'department=unknown', 'priority=critical', 'active=wrong'] as $query) {
            $this->getJson('/api/v1/investigations/queue?'.$query)->assertUnprocessable();
        }
        $this->grantRolePermissions('nurse', ['patients.view', 'encounters.view', 'encounters.view_any', 'investigations.view']);
        $this->login($this->createStaffWithRole('nurse'))->getJson('/api/v1/investigations/orders/'.$order['id'])->assertOk();
        $this->postCommand($this->path($order).'/collect', $this->action($order) + ['specimen_ref' => 'Synthetic reference'])->assertForbidden();
        $this->postCommand($this->path($order).'/cancel', $this->action($order))->assertForbidden();
    }

    public function test_unchanged_confirmation_rechecks_revoked_request_authority(): void
    {
        $data = $this->data();
        $key = ['Idempotency-Key' => 'investigation-revoked-confirmation'];
        $this->order($data, $key);
        Role::where('code', 'doctor')->sole()->permissions()->detach(Permission::where('code', 'investigations.request')->sole()->id);
        $this->postCommand('/api/v1/encounters/'.$this->visit->id.'/investigations', $data, $key)->assertForbidden();
        $this->assertDatabaseCount('investigation_orders', 1);
        $this->assertDatabaseCount('investigation_revisions', 1);
    }

    public function test_unavailable_catalog_and_future_clock_events_do_not_create_requests(): void
    {
        $schema = $this->form();
        $data = $this->data($schema);
        $definition = $schema->schema_json;
        $definition['is_active'] = false;
        app(InvestigationCatalog::class)->publish($definition, $this->doctor->id, 1, 'Synthetic retirement', 'Retire obsolete test');
        $data['items'][0]['test_version'] = 2;
        $data['items'][0]['schema_id'] = $schema->test->fresh()->latestSchema->id;
        $this->postCommand('/api/v1/encounters/'.$this->visit->id.'/investigations', $data)->assertConflict()->assertJsonPath('code', 'investigation_definition_unavailable');
        $this->travelTo($this->assignment->assigned_at->subMinute());
        $this->postCommand('/api/v1/encounters/'.$this->visit->id.'/investigations', $data)->assertConflict()->assertJsonPath('code', 'investigation_clock_conflict');
        $this->assertDatabaseCount('investigation_orders', 0);
    }

    public function test_collection_validation_and_full_history_keep_original_private_values(): void
    {
        $order = $this->order();
        $this->login($this->diagnostics);
        $data = $this->action($order);
        $this->postCommand($this->path($order).'/collect', $data)->assertUnprocessable()->assertJsonValidationErrors('specimen_ref');
        $this->postCommand($this->path($order).'/collect', $data + ['specimen_ref' => 'Synthetic specimen', 'collected_by' => $this->doctor->id])->assertUnprocessable();
        $this->getJson('/api/v1/investigations/orders/'.$order['id'].'/history')->assertOk()->assertJsonPath('data.0.snapshot.clinical_context', 'Private investigation context');
        $this->assertDatabaseCount('investigation_revisions', 1);
        $this->assertSame('requested', InvestigationItem::sole()->status);
    }

    public function test_valid_multibyte_clinical_context_survives_the_saved_order_and_private_snapshot(): void
    {
        $data = $this->data();
        $data['clinical_context'] = str_repeat('🩺', 20000);
        $order = $this->order($data);
        $this->assertSame($data['clinical_context'], $order['clinical_context']);
        $this->assertSame($data['clinical_context'], InvestigationOrder::sole()->revisions->sole()->snapshot['clinical_context']);
        $this->assertAuditExcludes([substr($data['clinical_context'], 0, 40)]);
    }
}
