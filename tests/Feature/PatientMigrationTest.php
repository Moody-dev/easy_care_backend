<?php

namespace Tests\Feature;

use App\Models\Patient;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PatientMigrationTest extends TestCase
{
    use DatabaseMigrations;

    public function test_patient_foundation_rolls_back_and_reapplies_without_changing_existing_staff_or_files(): void
    {
        $this->seed(DatabaseSeeder::class);
        $billing = require database_path('migrations/2026_11_17_115716_create_billing_foundations.php');
        $medications = require database_path('migrations/2026_11_17_110715_create_medication_fulfillment_records.php');
        $results = require database_path('migrations/2026_11_16_034753_create_investigation_result_records.php');
        $requests = require database_path('migrations/2026_11_16_023634_create_investigation_request_records.php');
        $admissionHistory = require database_path('migrations/2026_11_15_015445_create_admission_revision_records.php');
        $care = require database_path('migrations/2026_11_15_024759_create_care_program_records.php');
        $staff = User::factory()->create();
        $migration = require database_path('migrations/2026_11_11_171342_create_patient_foundation_tables.php');
        $lookup = require database_path('migrations/2026_11_11_173615_add_patient_lookup_indexes.php');
        $records = require database_path('migrations/2026_11_11_183300_create_patient_record_management_tables.php');
        $visits = require database_path('migrations/2026_11_11_225842_create_visit_foundation_tables.php');
        $bookings = require database_path('migrations/2026_11_12_012918_create_appointment_booking_records.php');
        $workflow = require database_path('migrations/2026_11_12_023554_create_encounter_workflow_records.php');
        $clinical = require database_path('migrations/2026_11_12_042242_create_clinical_storage_tables.php');
        $review = require database_path('migrations/2026_11_12_052540_create_clinical_revision_records.php');
        $admissions = require database_path('migrations/2026_11_14_213944_create_admission_storage_tables.php');
        $this->assertTablesUnchanged(['users', 'roles', 'permissions', 'role_permissions', 'files', 'audit_events'], function () use ($migration, $lookup, $records, $visits, $bookings, $workflow, $clinical, $review, $admissions, $admissionHistory, $care, $requests, $results, $medications, $billing): void {
            $billingWorkflow = require database_path('migrations/2026_11_17_121111_add_billing_workflow_guards.php');
            $hmoMigration = require database_path('migrations/2026_11_17_152355_create_hmo_workflow_records.php');
            $hmoMigration->down();
            $billingWorkflow->down();
            $billing->down();
            $medications->down();
            $results->down();
            $requests->down();
            $care->down();
            $admissionHistory->down();
            $admissions->down();
            $review->down();
            $clinical->down();
            $workflow->down();
            $bookings->down();
            $visits->down();
            $records->down();
            $lookup->down();
            $migration->down();
            foreach (['patient_categories', 'patients', 'patient_identifiers', 'patient_contacts', 'patient_relationships'] as $table) {
                $this->assertFalse(Schema::hasTable($table));
            }
            $migration->up();
            $lookup->up();
            $records->up();
            $visits->up();
            $bookings->up();
            $workflow->up();
            $clinical->up();
            $review->up();
            $admissions->up();
            $admissionHistory->up();
            $care->up();
            $this->seed(DatabaseSeeder::class);
            $requests->up();
            $results->up();
            $medications->up();
            $billing->up();
            $billingWorkflow->up();
            $hmoMigration->up();
        });
        $patient = Patient::factory()->create(['created_by' => $staff->id]);
        $this->assertDatabaseHas('patient_identifiers', ['patient_id' => $patient->id, 'identifier' => $patient->mrn]);
        $this->assertDatabaseHas('users', ['id' => $staff->id, 'username' => $staff->username]);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        RefreshDatabaseState::$migrated = false;
    }
}
