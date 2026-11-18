<?php

namespace Tests\Feature;

use App\Models\DoctorAssignment;
use App\Models\Encounter;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReportApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_current_queues_include_old_pending_visits_while_the_period_cohort_remains_date_filtered(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->grantRolePermissions('doctor',['reports.activity','patients.view','encounters.view','encounters.view_any']);
        $actor=$this->createStaffWithRole('doctor');
        Encounter::factory()->create(['opened_at'=>now('UTC')->subDays(3)]);
        $this->actingAs($actor)->getJson('/api/v1/reports/activity')->assertOk()->assertJsonPath('data.encounters.count',0)
            ->assertJsonPath('data.encounters.current.pending_or_active_count',1);
    }

    public function test_other_clinicians_drafts_are_excluded_and_a_report_does_not_copy_clinical_content(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->grantRolePermissions('doctor',['reports.activity','patients.view','encounters.view','encounters.view_any','clinical.view']);
        $actor=$this->createStaffWithRole('doctor');
        \App\Models\ClinicalRecord::factory()->create(['authored_by'=>$actor->id]);
        \App\Models\ClinicalRecord::factory()->create();
        $this->actingAs($actor)->getJson('/api/v1/reports/activity')->assertOk()->assertJsonPath('data.clinical.count',1)
            ->assertJsonPath('data.clinical.current.pending_or_active_count',1)->assertJsonMissingPath('data.clinical.records');
    }

    public function test_report_database_failures_do_not_expose_private_filters_or_sql_bindings(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->grantRolePermissions('doctor',['reports.activity','patients.view']);
        $this->actingAs($this->createStaffWithRole('doctor'));
        \Illuminate\Support\Facades\Log::spy();
        $this->mock(\App\Services\AuditTrail::class)->shouldReceive('record')->andThrow(new \Illuminate\Database\QueryException(
            'mysql','private report filter sentinel',[],new \Exception('private record sentinel')));
        $response=$this->getJson('/api/v1/reports/activity')->assertStatus(500);
        $this->assertStringNotContainsString('sentinel',$response->getContent());
        \Illuminate\Support\Facades\Log::shouldHaveReceived('error')->with('A reporting database operation failed.');
    }

    public function test_all_authorized_empty_modules_report_zero_without_inventing_demo_figures(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->grantRolePermissions('admin',array_keys(config('authorization.permissions')));
        $actor=$this->createStaffWithRole('admin');
        $this->actingAs($actor)->getJson('/api/v1/dashboard')->assertOk()->assertJsonPath('data.sections.activity.staff.count',1)
            ->assertJsonPath('data.sections.activity.registry.count',0)->assertJsonPath('data.sections.inventory.current.positions',0)
            ->assertJsonPath('data.sections.billing.current.amounts.outstanding','0.00')->assertJsonPath('data.sections.hmo.current.amounts.insurer_outstanding','0.00');
    }

    public function test_report_permissions_do_not_grant_access_to_patient_or_inventory_sources(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->grantRolePermissions('frontdesk',['dashboard.view','reports.activity','reports.inventory','reports.billing','reports.hmo']);
        $actor=$this->createStaffWithRole('frontdesk');
        $this->actingAs($actor)->getJson('/api/v1/dashboard')->assertOk()->assertJsonMissingPath('data.sections.billing')
            ->assertJsonMissingPath('data.sections.inventory')->assertJsonMissingPath('data.sections.activity.registry');
        $this->getJson('/api/v1/reports/billing')->assertForbidden();
        $this->getJson('/api/v1/reports/hmo')->assertForbidden();
        $this->getJson('/api/v1/reports/inventory')->assertForbidden();
    }

    public function test_invalid_unrelated_and_unbounded_filters_are_rejected(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->grantRolePermissions('doctor',['reports.activity','patients.view','encounters.view']);
        $this->actingAs($this->createStaffWithRole('doctor'));
        foreach (['?from_date=2026-01-01','?from_date=2026-01-02&to_date=2026-01-01',
            '?from_date=2024-01-01&to_date=2026-01-01','?timezone=UTC','?currency=USD','?per_page=1000'] as $query) {
            $this->getJson('/api/v1/reports/activity'.$query)->assertUnprocessable();
        }
        $this->getJson('/api/v1/reports/trend?module=inventory')->assertUnprocessable();
    }

    public function test_restricted_and_merged_patients_do_not_affect_counts_or_trends(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->grantRolePermissions('doctor',['reports.activity','patients.view','encounters.view','encounters.view_any']);
        $actor=$this->createStaffWithRole('doctor');
        Encounter::factory()->create();
        $hidden=Encounter::factory()->create();
        $hidden->patient->forceFill(['access_status'=>'restricted','version'=>2])->save();
        $this->actingAs($actor)->getJson('/api/v1/reports/activity')->assertOk()->assertJsonPath('data.encounters.count',1);
        $this->getJson('/api/v1/reports/trend?module=encounters')->assertOk()->assertJsonPath('data.series.0.count',1);
        $this->getJson('/api/v1/reports/activity?patient_id='.$hidden->patient_id)->assertNotFound();
        $this->getJson('/api/v1/reports/activity?encounter_id='.$hidden->id)->assertNotFound();
    }

    public function test_dashboard_requires_explicit_grants_and_uses_existing_clinician_visibility(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->grantRolePermissions('doctor',['dashboard.view','reports.activity','patients.view','encounters.view']);
        $doctor=$this->createStaffWithRole('doctor');
        $own=Encounter::factory()->create();
        DoctorAssignment::factory()->create(['encounter_id'=>$own->id,'doctor_id'=>$doctor->id]);
        Encounter::factory()->create();
        $this->actingAs($doctor)->getJson('/api/v1/dashboard')->assertOk()->assertJsonPath('data.sections.activity.encounters.count',1)
            ->assertJsonMissingPath('data.sections.billing')->assertJsonMissingPath('data.sections.inventory');
        $this->getJson('/api/v1/reports/activity')->assertOk()->assertJsonPath('data.encounters.count',1);
        $this->getJson('/api/v1/reports/inventory')->assertForbidden();
        $admin=$this->createStaffWithRole('admin');
        $this->flushSession(); $this->actingAs($admin);
        $this->getJson('/api/v1/dashboard')->assertForbidden();
    }

    public function test_lagos_day_boundaries_and_zero_filled_trend_use_the_same_cohort(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->grantRolePermissions('doctor',['reports.activity','patients.view','encounters.view','encounters.view_any']);
        $doctor=$this->createStaffWithRole('doctor');
        Encounter::factory()->create(['opened_at'=>'2026-11-17 22:59:59.999999']);
        Encounter::factory()->create(['opened_at'=>'2026-11-17 23:00:00.000000']);
        Encounter::factory()->create(['opened_at'=>'2026-11-18 22:59:59.999999']);
        Encounter::factory()->create(['opened_at'=>'2026-11-18 23:00:00.000000']);
        $this->actingAs($doctor);
        $query='?from_date=2026-11-18&to_date=2026-11-18';
        $this->getJson('/api/v1/reports/activity'.$query)->assertOk()->assertJsonPath('data.encounters.count',2);
        $this->getJson('/api/v1/reports/trend'.$query.'&module=encounters')->assertOk()->assertJsonPath('data.series.0.count',2);
    }
}
