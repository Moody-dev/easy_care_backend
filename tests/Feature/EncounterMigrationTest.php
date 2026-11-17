<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Encounter;
use App\Models\EncounterRevision;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class EncounterMigrationTest extends TestCase
{
    use DatabaseMigrations;

    public function test_workflow_upgrade_and_rollback_preserve_existing_patient_visit_booking_and_grant_data(): void
    {
        $this->seed(DatabaseSeeder::class);
        $results = require database_path('migrations/2026_11_16_034753_create_investigation_result_records.php');
        $requests = require database_path('migrations/2026_11_16_023634_create_investigation_request_records.php');
        Appointment::factory()->create();
        $visit = Encounter::factory()->create();
        $migration = require database_path('migrations/2026_11_12_023554_create_encounter_workflow_records.php');
        $clinical = require database_path('migrations/2026_11_12_042242_create_clinical_storage_tables.php');
        $review = require database_path('migrations/2026_11_12_052540_create_clinical_revision_records.php');
        $this->assertTablesUnchanged(['users', 'patients', 'roles', 'permissions', 'role_permissions', 'appointments',
            'appointment_revisions', 'appointment_follow_ups', 'encounters', 'doctor_assignments', 'audit_events', 'idempotency_requests'], function () use ($migration, $clinical, $review, $requests, $results): void {
            $results->down();
            $requests->down();
            $review->down();
            $clinical->down();
            $migration->down();
            $this->assertFalse(Schema::hasTable('encounter_revisions'));
            $migration->up();
            $clinical->up();
            $review->up();
            $this->assertDatabaseCount('encounter_revisions', 0);
            $requests->up();
            $results->up();
        });
        $revision = EncounterRevision::factory()->for($visit)->create();
        $this->assertTrue($revision->encounter->is($visit));
        $this->assertDatabaseCount('permissions', 113);
        $this->assertDatabaseCount('role_permissions', 21);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        RefreshDatabaseState::$migrated = false;
    }
}
