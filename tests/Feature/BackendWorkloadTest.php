<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\CommittedDatabase;
use Tests\PerformanceFixtures;
use Tests\TestCase;

class BackendWorkloadTest extends TestCase
{
    use CommittedDatabase { runDatabaseMigrations as private prepareCommittedData; }

    public function runDatabaseMigrations(): void
    {
        if (env('RUN_BACKEND_PERFORMANCE')!=='1' || DB::getDriverName()!=='mysql') {
            $this->markTestSkipped('Enable RUN_BACKEND_PERFORMANCE=1 on isolated MySQL for the 10,000-patient workload.');
        }
        $this->prepareCommittedData();
    }

    public function test_authenticated_worker_can_read_real_routes_before_the_large_workload(): void
    {
        $this->seed(DatabaseSeeder::class); $this->grantRolePermissions('frontdesk',['patients.view']);
        $actor=$this->createStaffWithRole('frontdesk');
        $patient=\App\Models\Patient::factory()->create(['created_by'=>$actor->id]);
        $result=$this->burst([['actor'=>$actor->id,'patient'=>$patient->id,'mode'=>'read','rounds'=>2,'paths'=>['/api/v1/patients?per_page=25']]]);
        foreach ($result['samples'] as $sample) { $this->assertSame(200,$sample['status']); }
    }

    public function test_provisional_ten_staff_workload_keeps_pagination_scope_and_cashier_records_correct(): void
    {
        $database=DB::selectOne('SELECT VERSION() AS version, @@innodb_flush_log_at_trx_commit AS commit_flush, @@innodb_doublewrite AS doublewrite_enabled');
        $database->redo_enabled=DB::selectOne("SHOW GLOBAL STATUS LIKE 'Innodb_redo_log_enabled'")?->Value;
        $this->assertSame(1,(int)$database->commit_flush);
        $this->assertSame('ON',$database->doublewrite_enabled);
        $this->assertSame('ON',$database->redo_enabled);
        $this->seed(DatabaseSeeder::class);
        $this->grantRolePermissions('frontdesk',['patients.view','appointments.view','encounters.view','encounters.view_any','encounters.assign',
            'reports.activity','reports.billing','billing.view','billing.receipts.record']);
        $actors=$doctors=[];
        for ($index=0;$index<10;$index++) {
            $actors[]=$this->createStaffWithRole('frontdesk'); $doctors[]=$this->createStaffWithRole('doctor');
        }
        $dataset=app(PerformanceFixtures::class)->build($actors,$doctors);
        $this->assertDatabaseCount('patients',10000); $this->assertDatabaseCount('patient_identifiers',10000);
        DB::select('ANALYZE TABLE patients,patient_identifiers,appointments,encounters,doctor_assignments,billing_accounts,billing_receipts');
        $this->actingAs($actors[0]);
        $profiles=$plans=[];
        foreach (['patients_first'=>'/api/v1/patients?per_page=25','patients_exact'=>'/api/v1/patients?mrn=PERF-000010',
            'patients_contains'=>'/api/v1/patients?q=Cohort050&per_page=25','patients_deep'=>'/api/v1/patients?per_page=25&page=390',
            'appointments'=>'/api/v1/appointments?per_page=25','queues'=>'/api/v1/queues?per_page=25',
            'accounts_1'=>'/api/v1/reports/accounts?per_page=1','accounts_25'=>'/api/v1/reports/accounts?per_page=25',
            'accounts_100'=>'/api/v1/reports/accounts?per_page=100'] as $name=>$path) {
            DB::flushQueryLog(); DB::enableQueryLog(); $start=hrtime(true);
            try { $response=$this->getJson($path)->assertOk(); $queries=DB::getQueryLog(); }
            finally { DB::disableQueryLog(); DB::flushQueryLog(); }
            $profiles[$name]=['duration_ms'=>(hrtime(true)-$start)/1000000,'queries'=>count($queries),'bytes'=>strlen($response->getContent()),
                'total'=>$response->json('meta.total')??$response->json('data.total')];
            $plans[$name]=[];
            foreach ($queries as $query) {
                if (!preg_match('/^select /i',$query['query']) || !preg_match('/from [`"]?(patients|appointments|encounters|billing_accounts)[`"]?(?:\s|$)/i',$query['query'])) { continue; }
                $row=DB::select('EXPLAIN FORMAT=JSON '.$query['query'],$query['bindings']);
                $plan=json_decode(array_values((array)$row[0])[0],true,flags:JSON_THROW_ON_ERROR);
                $plans[$name]=array_merge($plans[$name],$this->planTables($plan));
            }
        }
        $this->assertSame(9900,$profiles['patients_first']['total']);
        $this->assertSame(1,$profiles['patients_exact']['total']);
        $this->assertSame(1000,$profiles['accounts_100']['total']);
        $jobs=[];
        $readPaths=['/api/v1/patients?per_page=25','/api/v1/patients?mrn=PERF-000010','/api/v1/patients?q=Cohort050&per_page=25',
            '/api/v1/patients?per_page=25&page=390','/api/v1/appointments?per_page=25','/api/v1/queues?per_page=25',
            '/api/v1/reports/accounts?per_page=25','/api/v1/reports/accounts?per_page=100','/api/v1/reports/activity'];
        foreach ($actors as $index=>$actor) { $jobs[]=['actor'=>$actor->id,'patient'=>$index+1,'mode'=>'read','rounds'=>9,'paths'=>$readPaths]; }
        $reads=$this->burst($jobs);
        $jobs=[];
        foreach ($actors as $index=>$actor) { $jobs[]=['actor'=>$actor->id,'patient'=>$index+1,'mode'=>$index<5?'cashier':'read','rounds'=>3,'paths'=>['/api/v1/reports/billing']]; }
        $mixed=$this->burst($jobs);
        $report=['scope'=>'pre-authenticated HTTP-kernel requests; excludes browser, LAN/TLS/proxy, login and web-server worker capacity',
            'dataset'=>$dataset,'database'=>$database,
            'php'=>PHP_VERSION,'cache'=>'database','sessions'=>'array','profiles'=>$profiles,'plans'=>$plans,'reads'=>$reads,'mixed'=>$mixed];
        file_put_contents(base_path('../.tools/task50-workload.json'),json_encode($report,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
        foreach (array_merge($reads['samples'],$mixed['samples']) as $sample) {
            $this->assertContains($sample['status'],[200,201],$sample['path'].' '.json_encode($sample));
            if ($sample['rows']!==null) { $this->assertLessThanOrEqual(100,$sample['rows']); }
        }
        $this->assertDatabaseCount('billing_receipts',1015);
        $this->assertSame('1001515',(string)DB::table('billing_receipts')->sum('amount_kobo'));
    }

    private function planTables(array $plan): array
    {
        $tables=[];
        if (isset($plan['using_filesort'])) { $tables[]=['using_filesort'=>$plan['using_filesort']]; }
        if (isset($plan['table_name'],$plan['access_type'])) { $tables[]=array_intersect_key($plan,array_flip(['table_name','access_type','possible_keys','key','rows_examined_per_scan','rows_produced_per_join'])); }
        foreach ($plan as $value) { if (is_array($value)) { $tables=array_merge($tables,$this->planTables($value)); } }
        return $tables;
    }

    private function burst(array $jobs): array
    {
        $processes=[]; $start=hrtime(true);
        $barrier=storage_path('framework/testing/workload-start-'.bin2hex(random_bytes(8)).'.signal');
        \Illuminate\Support\Facades\File::ensureDirectoryExists(dirname($barrier));
        try {
            foreach ($jobs as $job) {
                $job['start_file']=$barrier;
                $process=new Process([PHP_BINARY,'-r','require $argv[1]."/vendor/autoload.php"; exit(Tests\PerformanceWorker::run($argv[1],$argv[2]));',base_path(),json_encode($job,JSON_THROW_ON_ERROR)],base_path(),
                    ['APP_ENV'=>'testing','APP_DEBUG'=>'false','DB_CONNECTION'=>'mysql','DB_DATABASE'=>'easycare_test','DB_USERNAME'=>'easycare_test','DB_URL'=>'','CACHE_STORE'=>'database','SESSION_DRIVER'=>'array','BCRYPT_ROUNDS'=>'12'],timeout:180);
                $process->start(); $processes[]=$process;
            }
            foreach ($processes as $process) { $this->assertTrue($process->waitUntil(fn (string $type,string $output): bool=>str_contains($output,'READY')),$process->getErrorOutput()); }
            $start=hrtime(true); file_put_contents($barrier,'start');
            $samples=[]; $peak=0; $starts=$finishes=[];
            foreach ($processes as $process) {
                $process->wait(); $this->assertTrue($process->isSuccessful(),$process->getErrorOutput());
                $lines=explode("\n",trim($process->getOutput())); $result=json_decode(end($lines),true,flags:JSON_THROW_ON_ERROR);
                array_push($samples,...$result['samples']); $peak=max($peak,$result['peak_memory_bytes']);
                $starts[]=$result['started_at']; $finishes[]=$result['finished_at'];
            }
            $elapsed=(hrtime(true)-$start)/1000000000; $times=array_column($samples,'duration_ms'); sort($times,SORT_NUMERIC);
            $this->assertLessThan(min($finishes),max($starts),'All workload workers must overlap after the shared start signal.');
            return ['staff'=>count($jobs),'requests'=>count($samples),'elapsed_seconds'=>$elapsed,'requests_per_second'=>count($samples)/$elapsed,
                'worker_start_spread_ms'=>(max($starts)-min($starts))*1000,'all_workers_overlap'=>true,
                'p50_ms'=>$times[(int)ceil(count($times)*.5)-1],'p95_ms'=>$times[(int)ceil(count($times)*.95)-1],
                'max_ms'=>max($times),'peak_worker_bytes'=>$peak,'samples'=>$samples];
        } finally { foreach ($processes as $process) { $process->stop(0); } if (is_file($barrier)) { unlink($barrier); } }
    }
}
