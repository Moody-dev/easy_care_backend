<?php

namespace Tests\Feature;

use App\Models\InvestigationTest;
use App\Models\Role;
use App\Services\InvestigationCatalog;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvestigationCatalogTest extends TestCase
{
    use RefreshDatabase;

    private int $publisher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->grantRolePermissions('diagnostics-and-radiologist', ['investigations.catalog.view', 'investigations.catalog.publish']);
        $actor = $this->createStaffWithRole('diagnostics-and-radiologist');
        $this->publisher = $actor->id;
        $this->actingAs($actor);
    }

    private function publish(string $code = 'synthetic-panel', string $department = 'laboratory'): InvestigationTest
    {
        $schema = app(InvestigationCatalog::class)->publish(['code' => $code, 'name' => 'Synthetic '.$code, 'department' => $department,
            'specimen' => null, 'is_active' => true, 'title' => 'Approved synthetic result form',
            'service' => ['code' => 'service-'.$code, 'name' => 'Synthetic service'],
            'fields' => ['observation' => ['type' => 'text', 'label' => 'Observation', 'required' => true]]],
            $this->publisher, 0, 'Synthetic hospital approval', 'Initial approved publication');

        return $schema->test;
    }

    public function test_catalog_is_opt_in_and_no_clinical_defaults_or_default_grants_are_created(): void
    {
        $admin = $this->createStaffWithRole('admin');
        $this->actingAs($admin)->getJson('/api/v1/investigations/catalog')->assertForbidden()->assertHeader('Cache-Control', 'no-store, private');
        $this->actingAs($admin)->getJson('/api/v1/investigations/catalog/options')->assertForbidden();
        $this->assertDatabaseCount('investigation_tests', 0);
        $this->assertDatabaseCount('investigation_schemas', 0);
        $this->assertDatabaseCount('services', 0);
        $this->assertDatabaseCount('permissions', 99);
        $this->assertDatabaseCount('permission_baselines', 4);
        $this->assertFalse($admin->hasPermission('investigations.catalog.publish'));
    }

    public function test_authenticated_catalog_access_is_independent_of_patient_and_result_permissions(): void
    {
        $test = $this->publish();
        $this->assertFalse(auth()->user()->hasPermission('patients.view'));
        $this->getJson('/api/v1/investigations/catalog/'.$test->id)->assertOk()->assertJsonPath('data.id', $test->id)
            ->assertJsonPath('data.version', 1)->assertJsonPath('data.service.id', $test->service_id)
            ->assertJsonPath('data.latest_schema.version', 1)->assertJsonMissingPath('data.patient_id')->assertJsonMissingPath('data.amount');
        $this->getJson('/api/v1/investigations/catalog/options')->assertOk()->assertJsonPath('data.reference_ranges_are_descriptive', true)
            ->assertJsonPath('data.prices_are_configured', false);
        $this->assertAuditEventRecorded('investigation.viewed', $this->publisher, 'investigation_test', $test->id);
    }

    public function test_guests_and_inactive_staff_cannot_read_approved_definitions(): void
    {
        $test = $this->publish();
        $actor = auth()->user();
        auth()->guard('web')->logout();
        $this->getJson('/api/v1/investigations/catalog')->assertUnauthorized()->assertHeader('Cache-Control', 'no-store, private');
        $actor->forceFill(['is_active' => false])->save();
        $this->actingAs($actor)->getJson('/api/v1/investigations/catalog/'.$test->id.'/schemas/'.$test->latestSchema->id)->assertUnauthorized();
    }

    public function test_search_department_active_filter_and_pagination_use_saved_catalogs(): void
    {
        $a = $this->publish('panel-a');
        $this->publish('panel-b', 'radiology');
        $c = $this->publish('panel-c');
        $input = $c->latestSchema->schema_json;
        $input['is_active'] = false;
        app(InvestigationCatalog::class)->publish($input, $this->publisher, 1, 'Synthetic approval', 'Retire approved entry');
        $this->getJson('/api/v1/investigations/catalog?department=laboratory&active=1&per_page=1')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $a->id)->assertJsonPath('meta.total', 1);
        $this->getJson('/api/v1/investigations/catalog?department=radiology')->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson('/api/v1/investigations/catalog?q=panel-c&active=0')->assertOk()->assertJsonPath('data.0.id', $c->id)->assertJsonPath('data.0.version', 2);
        $this->getJson('/api/v1/investigations/catalog?per_page=1&page=2')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.current_page', 2)->assertJsonPath('meta.total', 3);
        $this->getJson('/api/v1/investigations/catalog?q=missing')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_retired_tests_retain_exact_old_approved_definitions_and_reject_cross_test_schema_ids(): void
    {
        $test = $this->publish();
        $original = $test->latestSchema;
        $other = $this->publish('other-panel');
        $input = $original->schema_json;
        $input['is_active'] = false;
        $input['fields']['observation']['label'] = 'Revised observation';
        app(InvestigationCatalog::class)->publish($input, $this->publisher, 1, 'Synthetic second approval', 'Retire obsolete entry');
        $base = '/api/v1/investigations/catalog/'.$test->id.'/schemas';
        $this->getJson($base.'?per_page=1')->assertOk()->assertJsonPath('data.0.version', 2)->assertJsonPath('meta.total', 2);
        $this->getJson($base.'/'.$original->id)->assertOk()->assertJsonPath('data.version', 1)
            ->assertJsonPath('data.definition.fields.observation.label', 'Observation')->assertJsonPath('data.published_by', $this->publisher)
            ->assertJsonPath('data.approval_reference', 'Synthetic hospital approval');
        $this->getJson($base.'/'.$other->latestSchema->id)->assertNotFound();
        $this->assertAuditExcludes(['Synthetic hospital approval', 'Revised observation']);
    }

    public function test_revoked_catalog_access_clears_permission_to_read_known_versions(): void
    {
        $test = $this->publish();
        Role::where('code', 'diagnostics-and-radiologist')->sole()->permissions()->detach();
        $this->getJson('/api/v1/investigations/catalog')->assertForbidden();
        $this->getJson('/api/v1/investigations/catalog/'.$test->id.'/schemas/'.$test->latestSchema->id)->assertForbidden();
        $this->assertAuditEventRecorded('investigation.schemas_viewed', $this->publisher, 'investigation_test', $test->id, 'denied');
    }

    public function test_invalid_filters_and_ids_return_json_without_disclosing_catalog_content(): void
    {
        $test = $this->publish();
        foreach (['per_page=101', 'per_page=0', 'page=0', 'department=unknown', 'active=wrong', 'q[]=wrong'] as $query) {
            $this->getJson('/api/v1/investigations/catalog?'.$query)->assertUnprocessable()->assertHeader('Cache-Control', 'no-store, private');
        }
        foreach (['0', '-1', 'wrong', '999999999999999999'] as $id) {
            $this->getJson('/api/v1/investigations/catalog/'.$id)->assertNotFound();
        }
        $this->postJson('/api/v1/investigations/catalog', ['code' => 'unapproved'])->assertMethodNotAllowed();
        $this->assertDatabaseCount('investigation_tests', 1);
    }

    public function test_investigation_audit_events_can_be_filtered_by_the_admin(): void
    {
        $test = $this->publish();
        $admin = $this->createStaffWithRole('admin');
        $this->actingAs($admin)->getJson('/api/v1/audit-events?subject_type=investigation_test&subject_id='.$test->id.'&action=investigation.published')
            ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.channel', 'console')->assertJsonPath('data.0.safe_summary.version', 1);
    }
}
