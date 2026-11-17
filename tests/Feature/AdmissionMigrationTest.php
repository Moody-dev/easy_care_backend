<?php

namespace Tests\Feature;

use App\Models\Admission;
use App\Models\BedAllocation;
use App\Models\ClinicalEntry;
use App\Models\CurrentBedOccupancy;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AdmissionMigrationTest extends TestCase
{
    use DatabaseMigrations;

    public function test_admission_upgrade_and_rollback_preserve_existing_patient_visit_clinical_and_grant_data(): void
    {
        $this->seed(DatabaseSeeder::class);
        $admissionHistory = require database_path('migrations/2026_11_15_015445_create_admission_revision_records.php');
        $entry = ClinicalEntry::factory()->create();
        $migration = require database_path('migrations/2026_11_14_213944_create_admission_storage_tables.php');
        $tables = ['users', 'patients', 'patient_identifiers', 'roles', 'permissions', 'role_permissions', 'permission_baselines',
            'appointments', 'encounters', 'doctor_assignments', 'encounter_revisions', 'clinical_records', 'clinical_entries',
            'clinical_revisions', 'clinical_templates', 'clinical_template_versions', 'files', 'audit_events', 'idempotency_requests'];
        $this->assertTablesUnchanged($tables, function () use ($migration, $admissionHistory): void {
            $admissionHistory->down();
            $migration->down();
            foreach (['wards', 'beds', 'admissions', 'bed_allocations'] as $table) {
                $this->assertFalse(Schema::hasTable($table));
            }
            $this->assertFalse(Schema::hasView('current_bed_occupancies'));
            $migration->up();
            $admissionHistory->up();
            foreach (['wards', 'beds', 'admissions', 'bed_allocations', 'current_bed_occupancies'] as $table) {
                $this->assertDatabaseCount($table, 0);
            }
        });
        $admission = Admission::factory()->forEncounter($entry->record->encounter)->create();
        BedAllocation::factory()->create(['admission_id' => $admission->id]);
        $this->assertSame(1, CurrentBedOccupancy::count());
        $this->assertTablesUnchanged($tables, function () use ($migration, $admissionHistory): void {
            $admissionHistory->down();
            $migration->down();
            $migration->up();
            $admissionHistory->up();
        });
        $this->assertDatabaseCount('wards', 0);
        $this->assertDatabaseCount('bed_allocations', 0);
        $this->seed(DatabaseSeeder::class);
        $this->assertDatabaseCount('permissions', 99);
        $this->assertDatabaseCount('role_permissions', 21);
        $this->assertDatabaseCount('permission_baselines', 4);
        $this->assertSame('entry', $entry->record->fresh()->kind);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        RefreshDatabaseState::$migrated = false;
    }
}
