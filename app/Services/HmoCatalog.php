<?php

namespace App\Services;

use App\Exceptions\WriteConflict;
use App\Models\User;
use App\Support\BillingMoney;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use stdClass;

class HmoCatalog
{
    public function __construct(private StockAuthority $authority,private HmoTariffCsv $csv,private HmoBalances $balances,private AuditTrail $audit) {}

    public function revision(string $kind,int $id): stdClass
    {
        return DB::table('hmo_catalog_revisions')->where('kind',$kind)->where('record_id',$id)->orderByDesc('version')->lockForUpdate()->firstOrFail();
    }

    public function plan(int $id,bool $active=true): array
    {
        $plan=DB::table('hmo_plans')->lockForUpdate()->find($id);
        abort_if($plan===null,404);
        $provider=DB::table('hmo_providers')->lockForUpdate()->find($plan->provider_id);
        $pr=$this->revision('plan',$id); $vr=$this->revision('provider',$provider->id);
        if ($active && (!$pr->is_active || !$vr->is_active)) { throw new WriteConflict('hmo_catalog_inactive','The selected provider or plan is inactive.'); }

        return compact('plan','provider','pr','vr');
    }

    public function published(int $planId,?string $at=null): ?stdClass
    {
        $q=DB::table('hmo_reviews as r')->join('hmo_tariff_imports as i','i.id','=','r.record_id')
            ->where('r.kind','tariff')->where('r.outcome','accepted')->where('i.plan_id',$planId);
        if ($at!==null) { $q->where('r.effective_at','<=',$at); }
        return $q->select('r.*','i.plan_id')->orderByDesc('r.effective_at')->orderByDesc('r.published_version')->lockForUpdate()->first();
    }

    public function bind(int $provider,string $kind,string $reference,int $owner,array $signature): void
    {
        $key=hash('sha256',mb_strtoupper(trim($reference)));
        $old=DB::table('hmo_reference_bindings')->where('provider_id',$provider)->where('kind',$kind)->where('reference_key',$key)->lockForUpdate()->first();
        if ($old!==null) {
            if ((int)$old->owner_id!==$owner) { throw new WriteConflict('hmo_reference_used','That provider reference already belongs to another saved record.'); }
            return;
        }
        DB::table('hmo_reference_bindings')->insert(['provider_id'=>$provider,'kind'=>$kind,'reference_key'=>$key,'owner_id'=>$owner]+$signature);
    }

    public function execute(Request $request,User $actor,array $data): JsonResponse
    {
        $action=HmoWorkflows::action($request); $id=(int)$request->route('hmoRecord');
        $time=CarbonImmutable::now('UTC');
        $signature=['actor_id'=>$actor->id,'recorded_at'=>$time->format('Y-m-d H:i:s.u'),'reason'=>$data['reason']];
        if (in_array($action,['provider-create','plan-create','provider-update','plan-update'],true)) {
            $kind=str_starts_with($action,'provider')?'provider':'plan';
            $table=$kind==='provider'?'hmo_providers':'hmo_plans';
            $create=str_ends_with($action,'create');
            if ($create) {
                $values=['code'=>$data['code'],'name'=>$data['name']];
                if ($kind==='plan') { $values['provider_id']=$data['provider_id']; $this->revision('provider',(int)$data['provider_id']); }
                if (DB::table($table)->where('code',$data['code'])->when($kind==='plan',fn (\Illuminate\Database\Query\Builder $q): \Illuminate\Database\Query\Builder=>$q->where('provider_id',$data['provider_id']))->lockForUpdate()->first()!==null) {
                    throw new WriteConflict('hmo_code_exists','This identity code is already retained.');
                }
                $id=DB::table($table)->insertGetId($values+$signature);
                $version=1;
            } else {
                abort_if(DB::table($table)->lockForUpdate()->find($id)===null,404);
                $previous=$this->revision($kind,$id);
                $this->authority->version((int)$previous->version,$data['version']);
                HmoWorkflows::time($previous->recorded_at);
                $version=(int)$previous->version+1;
            }
            DB::table('hmo_catalog_revisions')->insert(['kind'=>$kind,'record_id'=>$id,'version'=>$version,
                'is_active'=>$data['is_active'],'preauth_required'=>$data['preauth_required'],'details'=>json_encode((object)($data['details']??[]),JSON_THROW_ON_ERROR)]+$signature);
        } elseif ($action==='tariff-import') {
            $context=$this->plan((int)$data['plan_id']);
            $previous=$this->published($context['plan']->id);
            $this->authority->version((int)($previous->published_version??0),$data['expected_version']);
            $rows=$this->csv->parse($data['csv_content']);
            $effective=isset($data['effective_at'])?CarbonImmutable::parse($data['effective_at'])->utc():$time;
            if ($effective->lessThan($time)) { $this->invalid('effective_at','A new tariff cannot be backdated.'); }
            $id=DB::table('hmo_tariff_imports')->insertGetId(['plan_id'=>$context['plan']->id,'expected_version'=>$data['expected_version'],
                'proposed_effective_at'=>$effective->format('Y-m-d H:i:s.u'),'filename'=>$data['filename'],'sha256'=>hash('sha256',$data['csv_content']),
                'byte_size'=>strlen($data['csv_content']),'row_count'=>count($rows),'csv_ciphertext'=>Crypt::encryptString($data['csv_content']),
                'approval_reference'=>$data['approval_reference']]+$signature);
            foreach ($rows as $row) {
                $row['snapshot']=json_encode($row['snapshot'],JSON_THROW_ON_ERROR);
                DB::table('hmo_tariffs')->insert(['import_id'=>$id]+$row+$signature);
            }
            $kind='tariff';
        } elseif ($action==='tariff-review') {
            $import=DB::table('hmo_tariff_imports')->lockForUpdate()->find($id); abort_if($import===null,404);
            $context=$this->plan($import->plan_id);
            $this->reviewable('tariff',$import,$actor,$data['version']);
            $values=['published_version'=>null,'effective_at'=>null];
            if ($data['outcome']==='accepted') {
                $csv=Crypt::decryptString($import->csv_ciphertext);
                if (hash('sha256',$csv)!==$import->sha256 || strlen($csv)!==(int)$import->byte_size) {
                    throw new WriteConflict('hmo_import_integrity','The retained CSV source failed its checksum.');
                }
                $parsed=$this->csv->parse($csv);
                $saved=DB::table('hmo_tariffs')->where('import_id',$id)->orderBy('id')->lockForUpdate()->get();
                if ($saved->count()!==count($parsed)) { throw new WriteConflict('hmo_import_integrity','The saved tariff row count changed.'); }
                foreach ($parsed as $index=>$line) {
                    if ($line['subject_key']!==$saved[$index]->subject_key || $line['amount_micros']!==(int)$saved[$index]->amount_micros
                        || $line['preauth_required']!==(bool)$saved[$index]->preauth_required) { throw new WriteConflict('hmo_import_integrity','The saved tariffs differ from their CSV.'); }
                }
                $previous=$this->published($import->plan_id);
                $this->authority->version((int)($previous->published_version??0),$import->expected_version);
                $effective=CarbonImmutable::parse($import->proposed_effective_at,'UTC')->max($time);
                if ($previous!==null && $effective->lessThan($previous->effective_at)) { $this->invalid('effective_at','Publish after the previously scheduled bundle.'); }
                $values=['published_version'=>(int)($previous->published_version??0)+1,'effective_at'=>$effective->format('Y-m-d H:i:s.u')];
            }
            DB::table('hmo_reviews')->insert(['kind'=>'tariff','record_id'=>$id,'provider_id'=>$context['provider']->id,'account_id'=>null,'outcome'=>$data['outcome']]+$values+$signature);
            $kind='tariff';
        } elseif ($action==='remittance-create') {
            $provider=DB::table('hmo_providers')->lockForUpdate()->find($data['provider_id']); abort_if($provider===null,404);
            $amount=HmoWorkflows::money($data['amount'],false);
            $received=CarbonImmutable::parse($data['received_at'])->utc();
            if ($received->greaterThan($time)) { $this->invalid('received_at','Record an outside payment that has actually been received.'); }
            $details=$data['transaction_details'];
            if ($data['method']!=='cash' && empty($details['provider']) || $data['method']==='other' && empty($details['method_name'])) {
                $this->invalid('transaction_details','Record the external method and financial provider.');
            }
            $key=hash('sha256',mb_strtoupper(trim($data['reference'])));
            $existing=DB::table('hmo_remittances')->where('provider_id',$provider->id)->where('reference_key',$key)->lockForUpdate()->get();
            foreach ($existing as $old) {
                $review=DB::table('hmo_reviews')->where('kind','remittance')->where('record_id',$old->id)->lockForUpdate()->first();
                if ($review?->outcome!=='rejected') { throw new WriteConflict('hmo_payment_recorded','That outside payment is already retained or awaiting review.'); }
            }
            $id=DB::table('hmo_remittances')->insertGetId(['provider_id'=>$provider->id,'amount_kobo'=>$amount,'reference'=>$data['reference'],
                'reference_key'=>$key,'method'=>$data['method'],'payer_name'=>$data['payer_name'],'received_at'=>$received->format('Y-m-d H:i:s.u'),
                'transaction_details'=>json_encode((object)$details,JSON_THROW_ON_ERROR)]+$signature);
            $kind='remittance';
        } else {
            $state=$this->balances->remittance($id);
            $this->reviewable('remittance',$state['record'],$actor,$data['version']);
            if ($data['outcome']==='accepted') { $this->bind($state['record']->provider_id,'remittance',$state['record']->reference,$id,$signature); }
            DB::table('hmo_reviews')->insert(['kind'=>'remittance','record_id'=>$id,'provider_id'=>$state['record']->provider_id,
                'account_id'=>null,'outcome'=>$data['outcome'],'published_version'=>null,'effective_at'=>null]+$signature);
            $kind='remittance';
        }
        $this->audit->record('hmo.'.$action,$actor->id,'hmo_catalog',$id);
        return $this->response($kind,$id)->setStatusCode(201);
    }

    public function reviewable(string $kind,stdClass $record,User $actor,int $version): void
    {
        abort_if($record->actor_id===$actor->id,403);
        $existing=DB::table('hmo_reviews')->where('kind',$kind)->where('record_id',$record->id)->lockForUpdate()->first();
        $this->authority->version(1+($existing===null?0:1),$version);
        if ($existing!==null) { throw new WriteConflict('hmo_already_reviewed','This saved request already has an independent decision.'); }
        HmoWorkflows::time($record->recorded_at);
    }

    public function response(string $kind,int $id): JsonResponse
    {
        $table=['provider'=>'hmo_providers','plan'=>'hmo_plans','tariff'=>'hmo_tariff_imports','remittance'=>'hmo_remittances'][$kind];
        $row=DB::table($table)->lockForUpdate()->find($id); abort_if($row===null,404);
        $data=(array)$row; unset($data['csv_ciphertext']);
        if ($kind==='provider'||$kind==='plan') {
            $revision=$this->revision($kind,$id);
            $data+=['version'=>(int)$revision->version,'is_active'=>(bool)$revision->is_active,'preauth_required'=>(bool)$revision->preauth_required,
                'details'=>json_decode($revision->details,true,flags:JSON_THROW_ON_ERROR)];
        } elseif ($kind==='tariff') {
            $review=DB::table('hmo_reviews')->where('kind','tariff')->where('record_id',$id)->lockForUpdate()->first();
            $data+=['version'=>1+($review===null?0:1),'review'=>$review,'status'=>$review?->outcome??'pending'];
        } else {
            $state=$this->balances->remittance($id);
            $data['transaction_details']=json_decode($row->transaction_details,true,flags:JSON_THROW_ON_ERROR);
            $data+=['version'=>$state['version'],'review'=>$state['review'],'status'=>$state['review']?->outcome??'pending',
                'allocated'=>BillingMoney::format($state['settled']),'reserved'=>BillingMoney::format($state['reserved']),
                'available'=>BillingMoney::format($state['available']),'payments_processed_by_platform'=>false];
        }
        $data['resource_kind']=$kind;
        return response()->json(['data'=>self::format($data)]);
    }

    public static function format(array $data): array
    {
        foreach (array_keys($data) as $key) {
            $value=$data[$key];
            if ($value===null) { continue; }
            if (str_ends_with($key,'_kobo')) { $data[substr($key,0,-5)]=BillingMoney::format((int)$value); unset($data[$key]); }
            elseif (str_ends_with($key,'_micros')) { $data[substr($key,0,-7)]=\App\Support\FixedPoint::format((int)$value); unset($data[$key]); }
            elseif (is_string($value) && (str_ends_with($key,'_at') || (in_array($key,['valid_from','valid_until'],true) && strlen($value)>10))) {
                $data[$key]=CarbonImmutable::parse($value,'UTC')->toISOString();
            }
            elseif (in_array($key,['snapshot','details','transaction_details'],true) && is_string($value)) { $data[$key]=json_decode($value,true,flags:JSON_THROW_ON_ERROR); }
            elseif ($key==='review' && is_object($value)) { $data[$key]=self::format((array)$value); }
            elseif (in_array($key,['is_active','preauth_required'],true)) { $data[$key]=(bool)$value; }
        }
        return $data;
    }

    private function invalid(string $field,string $message): never { throw ValidationException::withMessages([$field=>[$message]]); }
}
