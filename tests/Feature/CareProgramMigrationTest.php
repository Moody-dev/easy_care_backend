<?php

namespace Tests\Feature;

use App\Models\Birth;
use App\Models\BirthPatientLink;
use App\Models\CareRecordLink;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CareProgramMigrationTest extends TestCase
{
    use DatabaseMigrations;
    public function test_upgrade_and_rollback_preserve_existing_visits_records_and_grants(): void
    {
        $this->seed(DatabaseSeeder::class);
        $link=CareRecordLink::factory()->create(); $birth=Birth::factory()->create();
        $identity = BirthPatientLink::factory()->create(['birth_id'=>$birth->id]);
        $this->assertTrue($link->program->patient->carePrograms->contains($link->program));
        $this->assertTrue($link->program->encounter->carePrograms->contains($link->program));
        $this->assertSame($birth->encounter_id, $birth->encounter->id);
        $this->assertTrue($identity->patient->birthIdentity->is($identity));
        $migration=require database_path('migrations/2026_11_15_024759_create_care_program_records.php');
        $this->assertTablesUnchanged(['users','patients','encounters','clinical_records','permissions','role_permissions','permission_baselines'], function () use ($migration): void {
            $migration->down(); $migration->up();
        });
        $this->assertDatabaseCount('care_programs',0);
        $this->assertDatabaseCount('permissions',99);
        $this->assertDatabaseCount('role_permissions',21);
        $this->assertNotNull($link->record->fresh());
    }
    public function test_subject_links_are_immutable_and_births_belong_to_delivery_programs(): void
    {
        $this->seed(DatabaseSeeder::class); $link=CareRecordLink::factory()->create(); $birth=Birth::factory()->create();
        foreach ([
            fn ()=>DB::table('care_record_links')->where('id',$link->id)->delete(),
            fn ()=>DB::table('births')->where('id',$birth->id)->update(['birth_order'=>2]),
            fn ()=>Birth::factory()->create(['delivery_id'=>$link->care_program_id]),
        ] as $operation) {
            try { DB::transaction($operation); $this->fail('Invalid relational change succeeded.'); }
            catch (QueryException) { $this->addToAssertionCount(1); }
        }
    }
    protected function tearDown(): void { parent::tearDown(); RefreshDatabaseState::$migrated=false; }
}
