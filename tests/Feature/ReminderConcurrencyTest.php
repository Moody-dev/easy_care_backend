<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;
use Tests\ReminderTestCase;

class ReminderConcurrencyTest extends ReminderTestCase
{
    use DatabaseTruncation { truncateDatabaseTables as private resetTables; }

    private bool $mysqlReady=false;

    protected function truncateDatabaseTables(): void
    {
        if (DB::getDriverName()!=='mysql') { $this->markTestSkipped('Concurrent delivery and durable queue verification require isolated MySQL.'); }
        $this->resetTables();
        $this->mysqlReady=true;
    }

    private function job(string $action,int $record=0,array $data=[]): array
    {
        return ['action'=>$action,'record'=>$record,'actor'=>$this->author->id,'key'=>fake()->uuid(),'data'=>$data];
    }

    public function test_two_workers_after_an_earlier_snapshot_deliver_each_occurrence_only_once(): void
    {
        $this->createReminder(['recipient_ids'=>[$this->author->id,$this->recipient->id]])->assertCreated();
        [$first,$second]=$this->race($this->job('deliver'),$this->job('deliver'));
        $this->assertSame(1,$first['processed']);
        $this->assertSame(0,$second['processed']);
        $this->assertDatabaseCount('reminder_occurrences',1);
        $this->assertDatabaseCount('staff_notifications',2);
    }

    public function test_cancellation_prevents_a_waiting_worker_from_delivering_the_old_schedule(): void
    {
        $root=$this->createReminder()->assertCreated()->json('data');
        [$first,$second]=$this->race($this->job('cancelled',$root['id'],['version'=>1]),$this->job('deliver'));
        $this->assertSame(200,$first['status']);
        $this->assertSame(0,$second['processed']);
        $this->assertDatabaseCount('staff_notifications',0);
        $this->assertDatabaseCount('reminder_occurrences',0);
    }

    public function test_competing_read_and_snooze_commands_cannot_overwrite_the_same_notification_version(): void
    {
        $this->createReminder()->assertCreated();
        app(\App\Services\ReminderDelivery::class)->run();
        $id=\App\Models\StaffNotification::sole()->id;
        [$first,$second]=$this->race($this->job('read',$id,['version'=>1]),
            $this->job('snoozed',$id,['version'=>1,'snooze_until'=>now('UTC')->addDay()->toISOString()]));
        $this->assertSame(200,$first['status']);
        $this->assertSame(409,$second['status']);
        $this->assertNotNull(\App\Models\StaffNotification::sole()->read_at);
        $this->assertSame(2,\App\Models\StaffNotification::sole()->version);
    }

    public function test_revocation_is_visible_to_a_worker_that_opened_a_snapshot_before_the_grant_changed(): void
    {
        $this->createReminder()->assertCreated();
        [$first,$second]=$this->race($this->job('revoke'),$this->job('deliver'));
        $this->assertSame(200,$first['status']);
        $this->assertSame(1,$second['processed']);
        $this->assertDatabaseCount('staff_notifications',0);
        $this->assertDatabaseHas('reminder_deliveries',['suppression_code'=>'recipient_permission_missing']);
    }

    public function test_scheduler_dispatch_is_durable_unique_and_a_real_worker_creates_the_inbox_record(): void
    {
        $this->createReminder()->assertCreated();
        $this->artisan('reminders:dispatch')->assertSuccessful();
        $this->artisan('reminders:dispatch')->assertSuccessful();
        $this->assertDatabaseCount('jobs',1);
        $this->assertDatabaseCount('staff_notifications',0);
        $this->artisan('queue:work',['connection'=>'reminders','--queue'=>'reminders','--once'=>true,'--sleep'=>0,'--tries'=>5,'--timeout'=>45])->assertSuccessful();
        $this->assertDatabaseCount('staff_notifications',1);
        $this->assertDatabaseCount('jobs',0);
        $this->assertDatabaseCount('failed_jobs',0);
        $this->artisan('reminders:dispatch')->assertSuccessful();
        $this->assertDatabaseCount('jobs',0);
    }

    private function race(array $first,array $second): array
    {
        $input=new InputStream;
        $early=$this->worker($first,true);
        $later=$this->worker($second,false);
        $later->setInput($input);
        try {
            $later->start();
            $this->assertTrue($later->waitUntil(fn (string $type,string $output): bool=>str_contains($output,'READY')),$later->getErrorOutput());
            $early->start();
            $this->assertTrue($early->waitUntil(fn (string $type,string $output): bool=>str_contains($output,'LOCKED')),$early->getErrorOutput().$early->getOutput());
            $input->write("continue\n");
            $early->wait(); $later->wait();
            $outcomes=[];
            foreach ([$early,$later] as $process) {
                $this->assertTrue($process->isSuccessful(),$process->getErrorOutput().$process->getOutput());
                $lines=explode("\n",trim($process->getOutput()));
                $outcomes[]=json_decode(end($lines),true,flags:JSON_THROW_ON_ERROR);
            }
            return $outcomes;
        } finally { $input->close(); $early->stop(0); $later->stop(0); }
    }

    private function worker(array $job,bool $hold): Process
    {
        $code= <<<'PHP'
require $argv[1].'/vendor/autoload.php';
$app=require $argv[1].'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (!$app->environment('testing') || $app->configurationIsCached() || config('database.default')!=='mysql'
    || config('database.connections.mysql.database')!=='easycare_test' || config('database.connections.mysql.username')!=='easycare_test'
    || !in_array(config('database.connections.mysql.host'),['localhost','127.0.0.1','::1'],true) || config('database.connections.mysql.url')) {
    throw new RuntimeException('Reminder workers require isolated local MySQL.');
}
$job=json_decode($argv[2],true,flags:JSON_THROW_ON_ERROR);
Illuminate\Support\Facades\DB::beginTransaction();
if ($argv[3]!=='hold') {
    Illuminate\Support\Facades\DB::table('staff_reminders')->count();
    echo "READY\n"; flush(); fgets(STDIN);
}
try {
    if ($job['action']==='deliver') {
        $result=['processed'=>$app->make(App\Services\ReminderDelivery::class)->run(1)];
    } elseif ($job['action']==='revoke') {
        App\Models\Permission::where('code','staff.manage')->lockForUpdate()->firstOrFail();
        App\Models\Role::where('code','frontdesk')->sole()->permissions()->detach(App\Models\Permission::where('code','notifications.view')->value('id'));
        $result=['status'=>200];
    } else {
        $notification=$job['action']!=='cancelled';
        $path=$notification?'/api/v1/notifications/'.$job['record'].'/'.($job['action']==='snoozed'?'snooze':$job['action'])
            :'/api/v1/reminders/'.$job['record'].'/cancel';
        $request=Illuminate\Http\Request::create($path,'POST',$job['data']);
        $request->headers->set('Idempotency-Key',$job['key']);
        $route=new Illuminate\Routing\Route('POST',ltrim($path,'/'),fn()=>null);
        $route->name('api.v1.'.($notification?'notifications.':'reminders.').$job['action']);
        $route->bind($request);
        $route->setParameter($notification?'notification':'reminder',(string)$job['record']);
        $request->setRouteResolver(fn()=>$route);
        $app->instance('request',$request);
        $actor=App\Models\User::findOrFail($job['actor']);
        Illuminate\Support\Facades\Auth::guard('web')->setUser($actor);
        $request->setUserResolver(fn()=>$actor);
        $response=$app->make(App\Services\IdempotentCommands::class)->run($request,fn()=>
            $app->make(App\Services\ReminderWorkflows::class)->execute($request,$actor,
                $app->make(App\Http\Requests\Api\V1\ReminderCommandRequest::class)->validated()));
        $result=['status'=>$response->getStatusCode()];
    }
    if ($argv[3]==='hold') { echo "LOCKED\n"; flush(); usleep(1200000); }
    Illuminate\Support\Facades\DB::commit();
    echo json_encode($result,JSON_THROW_ON_ERROR)."\n";
} catch (Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $exception) {
    Illuminate\Support\Facades\DB::rollBack();
    echo json_encode(['status'=>$exception->getStatusCode()])."\n";
}
PHP;
        return new Process([PHP_BINARY,'-r',$code,base_path(),json_encode($job,JSON_THROW_ON_ERROR),$hold?'hold':'run'],base_path(),
            ['APP_ENV'=>'testing','DB_CONNECTION'=>'mysql','DB_DATABASE'=>'easycare_test','DB_USERNAME'=>'easycare_test','DB_URL'=>''],timeout:40);
    }

    protected function tearDown(): void
    {
        try { if ($this->mysqlReady) { $this->truncateTablesForAllConnections(); } }
        finally { parent::tearDown(); }
    }
}
