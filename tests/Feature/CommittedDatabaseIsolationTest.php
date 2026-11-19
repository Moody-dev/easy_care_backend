<?php

namespace Tests\Feature;

use App\Models\DocumentExport;
use App\Models\User;
use App\Services\AuditTrail;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\CommittedDatabase;
use Tests\TestCase;

class CommittedDatabaseIsolationTest extends TestCase
{
    use CommittedDatabase;

    public function test_cleanup_removes_committed_immutable_fixtures_and_preserves_migration_history_and_guards(): void
    {
        if (DB::getDriverName()!=='mysql') { $this->markTestSkipped('Committed fixture truncation is MySQL-specific.'); }
        $this->seed(DatabaseSeeder::class);
        $actor=User::factory()->create();
        DocumentExport::factory()->create(['creator_id'=>$actor->id]);
        app(AuditTrail::class)->record('export.generated',$actor->id,'document_export',1);
        $migrations=DB::table('migrations')->orderBy('id')->get()->toJson();
        $triggers=DB::table('information_schema.TRIGGERS')->where('TRIGGER_SCHEMA',DB::connection()->getDatabaseName())
            ->orderBy('TRIGGER_NAME')->pluck('TRIGGER_NAME')->all();
        $this->assertNotEmpty($triggers);
        $this->assertSame(0,DB::transactionLevel());
        $this->clearCommittedTestData();
        foreach (['users','document_exports','audit_events','permissions','role_permissions'] as $table) { $this->assertDatabaseCount($table,0); }
        $this->assertSame($migrations,DB::table('migrations')->orderBy('id')->get()->toJson());
        $this->assertSame($triggers,DB::table('information_schema.TRIGGERS')->where('TRIGGER_SCHEMA',DB::connection()->getDatabaseName())
            ->orderBy('TRIGGER_NAME')->pluck('TRIGGER_NAME')->all());
        $this->seed(DatabaseSeeder::class);
        $this->assertDatabaseCount('permissions',125); $this->assertDatabaseCount('role_permissions',21);
        $actor=User::factory()->create();
        $document=DocumentExport::factory()->create(['creator_id'=>$actor->id]);
        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('document_exports')->where('id',$document->id)->update(['byte_size'=>1]);
    }

    public function test_cleanup_rejects_a_development_database_setting_before_issuing_any_queries(): void
    {
        $connection=config('database.default'); $setting=config('database.connections.mysql.database');
        config(['database.default'=>'mysql','database.connections.mysql.database'=>'easycare_dev']);
        try {
            $this->clearCommittedTestData();
            $this->fail('Committed cleanup must reject development databases.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('isolated test database',$exception->getMessage());
        } finally {
            config(['database.default'=>$connection,'database.connections.mysql.database'=>$setting]);
        }
    }
}
