<?php

namespace Tests\Feature;

use App\Models\Patient;
use App\Services\PostgresSchema;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PostgresIntegrityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        if (($_ENV['DB_CONNECTION'] ?? getenv('DB_CONNECTION')) !== 'pgsql') {
            $this->markTestSkipped('Requires the isolated PostgreSQL test database.');
        }
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_private_schema_contains_the_retained_tables_and_database_guards(): void
    {
        $this->assertSame('easycare', DB::selectOne('SELECT current_schema() AS name')->name);
        $manifest = app(PostgresSchema::class)->manifest();
        foreach ($manifest['tables'] as $table) {
            $this->assertTrue(DB::getSchemaBuilder()->hasTable($table), $table);
        }
        $guards = DB::selectOne("SELECT count(*) AS total FROM pg_trigger t JOIN pg_class c ON c.oid = t.tgrelid JOIN pg_namespace n ON n.oid = c.relnamespace WHERE n.nspname = 'easycare' AND NOT t.tgisinternal");
        $this->assertSame($manifest['triggers'] + 7, (int) $guards->total);
        $this->assertTrue(DB::getSchemaBuilder()->hasTable('patient_number_sequences'));
        $this->assertTrue(DB::getSchemaBuilder()->hasTable('patient_number_reservations'));
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('permissions', 125);
    }

    public function test_test_database_wipes_cannot_include_managed_or_extension_schemas(): void
    {
        DB::statement('CREATE TABLE extensions.wipe_sentinel (id integer)');
        DB::beginTransaction();
        try {
            DB::getSchemaBuilder()->dropAllTables();
            $this->assertFalse(DB::getSchemaBuilder()->hasTable('patients'));
            $this->assertTrue(DB::getSchemaBuilder()->hasTable('extensions.wipe_sentinel'));
        } finally {
            DB::rollBack();
        }
        $this->assertTrue(DB::getSchemaBuilder()->hasTable('patients'));
    }

    public function test_case_insensitive_identifier_matching_is_preserved(): void
    {
        $patient = Patient::factory()->create(['mrn' => 'TEST-CASE-123']);
        $this->assertSame($patient->id, Patient::query()->where('mrn', 'test-case-123')->sole()->id);
    }

    public function test_public_supabase_roles_cannot_use_the_application_schema_or_tables(): void
    {
        foreach (DB::select("SELECT rolname FROM pg_roles WHERE rolname IN ('anon', 'authenticated', 'service_role')") as $role) {
            $access = DB::selectOne("SELECT has_schema_privilege(?, 'easycare', 'USAGE') AS schema_access,
                has_table_privilege(?, 'easycare.patients', 'SELECT') AS patient_access", [$role->rolname, $role->rolname]);
            $this->assertFalse($access->schema_access);
            $this->assertFalse($access->patient_access);
        }
    }

    public function test_original_mysql_unsigned_version_limits_are_not_weakened(): void
    {
        $staff = \App\Models\User::factory()->create();
        try {
            DB::transaction(fn () => DB::table('users')->where('id', $staff->id)->update(['version' => 4294967296]));
            $this->fail('PostgreSQL must retain the original MySQL unsigned integer range.');
        } catch (QueryException $exception) {
            $this->assertSame('23514', $exception->errorInfo[0]);
        }
        $this->assertSame(1, $staff->fresh()->version);
    }

    public function test_reserved_patient_identifiers_cannot_be_rewritten_with_raw_sql(): void
    {
        $patient = Patient::factory()->create();
        try {
            DB::transaction(fn () => DB::table('patient_identifiers')->where('patient_id', $patient->id)->update(['identifier' => 'FORGED']));
            $this->fail('Retained identifiers must reject raw writes.');
        } catch (QueryException $exception) {
            $this->assertSame('23514', $exception->errorInfo[0]);
        }
        $this->assertDatabaseHas('patient_identifiers', ['patient_id' => $patient->id, 'identifier' => $patient->mrn]);
    }
}
