<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Encounter;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\Schema;
use Tests\CommittedDatabase;
use Tests\TestCase;

class ReadIndexMigrationTest extends TestCase
{
    use CommittedDatabase;

    public function test_read_indexes_round_trip_without_changing_populated_records_or_permissions(): void
    {
        $this->seed(DatabaseSeeder::class);
        Appointment::factory()->create(); Encounter::factory()->create();
        $migration=require database_path('migrations/2026_11_19_022314_add_read_path_indexes.php');
        $indexes=['patients'=>['patients_visible_name_order',['access_status','last_name','id']],
            'appointments'=>['appointments_start_order',['starts_at','id']],
            'encounters'=>['encounters_opened_order',['opened_at','id']]];
        $this->assertTablesUnchanged(['users','patients','patient_identifiers','appointments','encounters','permissions','role_permissions'],function () use ($migration,$indexes): void {
            $migration->down();
            foreach ($indexes as $table=>[$name,$columns]) { $this->assertFalse(Schema::hasIndex($table,$name)); }
            $migration->up();
            foreach ($indexes as $table=>[$name,$columns]) {
                $definition=collect(Schema::getIndexes($table))->firstWhere('name',$name);
                $this->assertSame($columns,$definition['columns']);
            }
        });
    }
}