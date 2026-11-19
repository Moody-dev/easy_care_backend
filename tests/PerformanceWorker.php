<?php

namespace Tests;

use App\Models\User;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Throwable;

class PerformanceWorker
{
    public static function run(string $root,string $json): int
    {
        $app=require $root.'/bootstrap/app.php';
        $kernel=$app->make(Kernel::class); $kernel->bootstrap();
        $settings=config('database.connections.mysql');
        if (!$app->environment('testing') || $app->configurationIsCached() || config('database.default')!=='mysql'
            || $settings['database']!=='easycare_test' || $settings['username']!=='easycare_test'
            || !in_array($settings['host'],['localhost','127.0.0.1','::1'],true) || $settings['url']) {
            fwrite(STDERR,"An isolated MySQL test environment is required.\n"); return 1;
        }
        $job=json_decode($json,true,flags:JSON_THROW_ON_ERROR);
        $queries=0; $sqlMs=0.0;
        DB::listen(function (QueryExecuted $event) use (&$queries,&$sqlMs): void { $queries++; $sqlMs+=$event->time; });
        $invoke=function (string $path,string $method='GET',array $body=[]) use ($app,$kernel,$job,&$queries,&$sqlMs): array {
            $queries=0; $sqlMs=0.0; $start=hrtime(true);
            $request=Request::create($path,$method,server:['HTTP_ACCEPT'=>'application/json','CONTENT_TYPE'=>'application/json','REMOTE_ADDR'=>'127.0.0.1'],content:json_encode($body,JSON_THROW_ON_ERROR));
            $app->instance('request',$request);
            Auth::forgetGuards(); Auth::guard('web')->setUser(User::query()->findOrFail($job['actor']));
            if ($method!=='GET') { $request->headers->set('Idempotency-Key',bin2hex(random_bytes(16))); }
            $response=$kernel->handle($request); $kernel->terminate($request,$response);
            $data=json_decode($response->getContent(),true,flags:JSON_THROW_ON_ERROR);
            return ['path'=>$path,'status'=>$response->getStatusCode(),'duration_ms'=>(hrtime(true)-$start)/1000000,
                'queries'=>$queries,'sql_ms'=>$sqlMs,'bytes'=>strlen($response->getContent()),
                'total'=>$data['meta']['total']??$data['data']['total']??null,'rows'=>isset($data['data']['data'])?count($data['data']['data']):(isset($data['data'])&&array_is_list($data['data'])?count($data['data']):null),
                'version'=>$data['data']['version']??null,'code'=>$data['code']??null];
        };
        try {
            $warm=$invoke('/api/v1/patients/'.$job['patient']);
            if ($warm['status']!==200) { throw new \RuntimeException('Warmup was rejected.'); }
            echo "READY\n"; flush();
            $deadline=microtime(true)+120;
            while (!is_file($job['start_file'])) {
                if (microtime(true)>$deadline) { throw new \RuntimeException('The workload start signal timed out.'); }
                usleep(5000); clearstatcache(true,$job['start_file']);
            }
            $startedAt=microtime(true);
            $samples=[]; $version=1;
            for ($round=0;$round<$job['rounds'];$round++) {
                if ($job['mode']==='cashier') {
                    $sample=$invoke('/api/v1/billing/patients/'.$job['patient'].'/receipts','POST',[
                        'account_version'=>$version,'amount'=>'1.01','kind'=>'deposit','method'=>'cash','payer_name'=>'Synthetic workload payer',
                        'paid_at'=>now('UTC')->toISOString(),'externally_confirmed'=>false,'transaction_details'=>['receipt_number'=>'Synthetic workload voucher'],
                        'reason'=>'Synthetic performance verification','current_password'=>PerformanceFixtures::PASSWORD]);
                    $version=$sample['version'];
                } else { $sample=$invoke($job['paths'][$round%count($job['paths'])]); }
                $samples[]=$sample;
            }
            echo json_encode(['samples'=>$samples,'peak_memory_bytes'=>memory_get_peak_usage(true),'started_at'=>$startedAt,'finished_at'=>microtime(true)],JSON_THROW_ON_ERROR)."\n";
            return 0;
        } catch (Throwable $exception) {
            fwrite(STDERR,'The authenticated workload worker failed: '.get_class($exception).'; location='.basename($exception->getFile()).':'.$exception->getLine().'; stage='.($sample['status']??$warm['status']??'request').".\n");
            return 1;
        }
    }
}
