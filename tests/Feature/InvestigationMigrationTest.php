<?php

namespace Tests\Feature;

use App\Models\ClinicalRecord;
use App\Models\InvestigationSchema;
use App\Models\InvestigationTest;
use App\Models\Service;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class InvestigationMigrationTest extends TestCase
{
    use DatabaseMigrations;

    public function test_upgrade_and_rollback_preserve_existing_care_patients_and_staff_grants(): void
    {
        $this->seed(DatabaseSeeder::class);
        $results = require database_path('migrations/2026_11_16_034753_create_investigation_result_records.php');
        $requests = require database_path('migrations/2026_11_16_023634_create_investigation_request_records.php');
        $clinical = ClinicalRecord::factory()->create();
        $schema = InvestigationSchema::factory()->create();
        $this->assertSame($schema->test_id, $schema->test->id);
        $this->assertSame($schema->test->service_id, $schema->test->service->id);
        $this->assertTrue($schema->test->service->investigationTests->contains($schema->test));
        $this->assertTrue($schema->test->schemas->contains($schema));
        $migration = require database_path('migrations/2026_11_16_000353_create_investigation_catalogs.php');
        $this->assertTablesUnchanged(['users', 'patients', 'encounters', 'clinical_records', 'care_programs', 'permissions', 'role_permissions', 'permission_baselines'], function () use ($migration, $requests, $results): void {
            $results->down();
            $requests->down();
            $migration->down();
            $migration->up();
            $requests->up();
            $results->up();
        });
        foreach (['services', 'investigation_tests', 'investigation_schemas'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->assertNotNull($clinical->fresh());
        $this->assertDatabaseCount('permissions', 125);
        $this->assertDatabaseCount('role_permissions', 21);
    }

    public function test_database_guards_retain_published_definitions_and_prevent_rebinding_or_skipping_versions(): void
    {
        $schema = InvestigationSchema::factory()->create();
        $test = $schema->test;
        $other = Service::factory()->create();
        foreach ([
            fn () => DB::table('investigation_schemas')->where('id', $schema->id)->update(['schema_json' => '{}']),
            fn () => DB::table('investigation_schemas')->where('id', $schema->id)->delete(),
            fn () => DB::table('investigation_tests')->where('id', $test->id)->delete(),
            fn () => DB::table('services')->where('id', $test->service_id)->delete(),
            fn () => DB::table('services')->where('id', $test->service_id)->update(['code' => 'rebound']),
            fn () => DB::table('investigation_tests')->where('id', $test->id)->update(['code' => strtoupper($test->code), 'version' => 2]),
            fn () => DB::table('investigation_tests')->where('id', $test->id)->update(['service_id' => $other->id, 'version' => 2]),
            fn () => DB::table('investigation_tests')->where('id', $test->id)->update(['version' => 3]),
            fn () => DB::table('investigation_tests')->where('id', $test->id)->update(['name' => 'Unversioned name']),
            fn () => InvestigationSchema::factory()->create(['test_id' => $test->id, 'version' => 2]),
            fn () => InvestigationTest::factory()->create(['department' => 'radiology', 'service_id' => $other->id]),
        ] as $operation) {
            try {
                DB::transaction($operation);
                $this->fail('An invalid catalog mutation succeeded.');
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame($schema->content_hash, $schema->fresh()->content_hash);
        $this->assertSame(1, $test->fresh()->version);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        RefreshDatabaseState::$migrated = false;
    }
}
