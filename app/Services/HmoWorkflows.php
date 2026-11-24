<?php

namespace App\Services;

use App\Exceptions\WriteConflict;
use App\Models\BillingAccount;
use App\Models\Encounter;
use App\Models\Patient;
use App\Models\User;
use App\Support\BillingMoney;
use App\Support\FixedPoint;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use stdClass;

class HmoWorkflows
{
    public const PERMISSIONS=[
        'provider-create'=>'hmo.catalog.manage','provider-update'=>'hmo.catalog.manage','plan-create'=>'hmo.catalog.manage','plan-update'=>'hmo.catalog.manage',
        'tariff-import'=>'hmo.tariffs.import','tariff-review'=>'hmo.tariffs.publish','remittance-create'=>'hmo.settlements.record','remittance-review'=>'hmo.settlements.approve',
        'coverage-create'=>'hmo.coverages.verify','coverage-revoke'=>'hmo.coverages.verify','preauth-create'=>'hmo.preauthorizations.record','preauth-revoke'=>'hmo.preauthorizations.record',
        'invoice'=>'hmo.invoices.post','claim-submit'=>'hmo.claims.submit','claim-resubmit'=>'hmo.claims.submit',
        'decision-record'=>'hmo.decisions.record','decision-review'=>'hmo.decisions.approve',
        'allocation-record'=>'hmo.settlements.record','allocation-review'=>'hmo.settlements.approve',
    ];
    public const CATALOG=['provider-create','provider-update','plan-create','plan-update','tariff-import','tariff-review','remittance-create','remittance-review'];

    public function __construct(private StockAuthority $authority,private HmoCatalog $catalog,private HmoBalances $balances,
        private BillingWorkflows $billing,private HmoClaims $claims,private AuditTrail $audit) {}

    public static function action(Request $request): string { return substr($request->route()->getName(),strrpos($request->route()->getName(),'.')+1); }
    public static function isCommand(string $route): bool { return str_starts_with($route,'api.v1.hmo.') && isset(self::PERMISSIONS[substr($route,strrpos($route,'.')+1)]); }
    public static function isCatalog(Request $request): bool { return in_array(self::action($request),self::CATALOG,true); }
    public static function catalogKind(Request $request): string
    {
        $action=self::action($request);
        return str_starts_with($action,'provider')?'provider':(str_starts_with($action,'plan')?'plan':(str_starts_with($action,'tariff')?'tariff':'remittance'));
    }

    public function authorize(Request $request,User $actor): void
    {
        $action=self::action($request);
        $this->authority->permission($actor,self::PERMISSIONS[$action]);
        $this->authority->permission($actor,str_starts_with($action,'provider')||str_starts_with($action,'plan')||str_starts_with($action,'tariff')?'hmo.catalog.view':'hmo.view');
        $this->authority->password($request,$actor);
        if (self::isCatalog($request)) { return; }
        foreach (['hmo.view','billing.view','patients.view'] as $permission) { $this->authority->permission($actor,$permission); }
        $patient=Patient::query()->visibleTo($actor)->lockForUpdate()->findOrFail($request->route('patient'));
        $account=BillingAccount::query()->where('patient_id',$patient->id)->lockForUpdate()->first();
        $request->attributes->set('hmo_patient',$patient); $request->attributes->set('hmo_account',$account);
        if (in_array($action,['decision-review','allocation-review'],true)) {
            $id=(int)$request->route('hmoRecord');
            if ($action==='allocation-review') { $row=DB::table('hmo_allocations')->where('account_id',$account?->id)->lockForUpdate()->find($id); }
            else {
                $row=DB::table('hmo_decisions as d')->join('hmo_claims as c','c.id','=','d.claim_id')->where('c.account_id',$account?->id)
                    ->where('d.id',$id)->select('d.*')->lockForUpdate()->first();
            }
            abort_if($row===null,404); abort_if($row->actor_id===$actor->id,403);
        }
    }

    public function execute(Request $request,User $actor,array $data): JsonResponse
    {
        if (self::isCatalog($request)) { return $this->catalog->execute($request,$actor,$data); }
        $action=self::action($request);
        $account=$request->attributes->get('hmo_account');
        $last=$account===null?null:DB::table('billing_events')->where('account_id',$account->id)->orderByDesc('version')->lockForUpdate()->first();
        $version=(int)($last->version??0); $this->authority->version($version,$data['account_version']);
        $time=self::time($last?->recorded_at); $signature=['actor_id'=>$actor->id,'recorded_at'=>$time->format('Y-m-d H:i:s.u'),'reason'=>$data['reason']];
        if ($account===null) {
            $account=new BillingAccount;
            $account->forceFill(['patient_id'=>$request->attributes->get('hmo_patient')->id,'currency'=>'NGN']+$signature)->save();
        }
        $id=(int)$request->route('hmoRecord');
        if ($action==='coverage-create') {
            $intake = null;
            if (isset($data['verify_registration_insurance'])) {
                $intake = DB::table('patient_insurance_intakes')->where('patient_id', $account->patient_id)->lockForUpdate()->first();
                if ($intake === null || $intake->coverage_id !== null) {
                    throw new WriteConflict('hmo_registration_insurance_unavailable', 'The patient has no pending registration insurance.');
                }
                if ((int) $intake->plan_id !== (int) $data['plan_id']
                    || mb_strtolower($intake->member_number) !== mb_strtolower($data['member_number'])) {
                    $this->invalid('verify_registration_insurance', 'The verified plan and member number must match the registration insurance.');
                }
            }
            $context=$this->catalog->plan((int)$data['plan_id']);
            $existing=DB::table('hmo_coverages')->where('account_id',$account->id)->where('plan_id',$data['plan_id'])
                ->where('valid_from','<=',$data['valid_until'])->where('valid_until','>=',$data['valid_from'])->lockForUpdate()->get();
            foreach ($existing as $old) { if (!$this->revoked('coverage',$old->id)) { throw new WriteConflict('hmo_coverage_overlap','Reconcile the overlapping membership before recording another verification.'); } }
            $id=DB::table('hmo_coverages')->insertGetId(['account_id'=>$account->id,'plan_id'=>$data['plan_id'],'member_number'=>$data['member_number'],
                'valid_from'=>$data['valid_from'],'valid_until'=>$data['valid_until'],'group_number'=>$data['group_number']??null,'employer_name'=>$data['employer_name']??null,
                'verification_reference'=>$data['verification_reference'],'snapshot'=>json_encode($context,JSON_THROW_ON_ERROR)]+$signature);
            if ($intake !== null) {
                DB::table('patient_insurance_intakes')->where('patient_id', $account->patient_id)->update(['coverage_id' => $id]);
            }
        } elseif ($action==='coverage-revoke'||$action==='preauth-revoke') {
            $kind=$action==='coverage-revoke'?'coverage':'preauth'; $table=$kind==='coverage'?'hmo_coverages':'hmo_preauthorizations';
            $record=DB::table($table)->where('account_id',$account->id)->lockForUpdate()->find($id); abort_if($record===null,404);
            if ($this->revoked($kind,$id)) { throw new WriteConflict('hmo_already_revoked','This verification is already revoked.'); }
            $id=DB::table('hmo_revocations')->insertGetId(['account_id'=>$account->id,'kind'=>$kind,'record_id'=>$id]+$signature);
        } elseif ($action==='preauth-create') {
            $coverage=$this->coverage($account,(int)$data['coverage_id']);
            $context=$this->catalog->plan($coverage->plan_id);
            $this->authority->permission($actor,'encounters.view');
            $visit=Encounter::query()->visibleTo($actor)->where('patient_id',$account->patient_id)->lockForUpdate()->findOrFail($data['encounter_id']);
            $this->authority->version($visit->version,$data['encounter_version']);
            $subject=$data['subject_type']==='service'?DB::table('services')->find($data['subject_id']):DB::table('drugs')->find($data['subject_id']);
            abort_if($subject===null,404);
            $approved=CarbonImmutable::parse($data['approved_at'])->utc();
            if ($approved->greaterThan($time)) { $this->invalid('approved_at','Record the actual external decision time, no later than now.'); }
            $limit=self::money($data['limit']);
            if ($data['status']==='denied' && $limit!==0) { $this->invalid('limit','A denied authorization has no approved allowance.'); }
            $id=DB::table('hmo_preauthorizations')->insertGetId(['account_id'=>$account->id,'coverage_id'=>$coverage->id,'encounter_id'=>$visit->id,
                'service_id'=>$data['subject_type']==='service'?$subject->id:null,'drug_id'=>$data['subject_type']==='drug'?$subject->id:null,
                'status'=>$data['status'],'limit_kobo'=>$limit,'reference'=>$data['reference'],'approved_at'=>$approved->format('Y-m-d H:i:s.u'),
                'valid_from'=>CarbonImmutable::parse($data['valid_from'])->utc()->format('Y-m-d H:i:s.u'),
                'valid_until'=>CarbonImmutable::parse($data['valid_until'])->utc()->format('Y-m-d H:i:s.u')]+$signature);
            $this->catalog->bind($context['provider']->id,'preauth',$data['reference'],$id,$signature);
        } elseif ($action==='invoice') {
            $coverage=$this->coverage($account,(int)$data['coverage_id']);
            $context=$this->catalog->plan($coverage->plan_id);
            foreach (['encounters.view','billing.invoices.post'] as $permission) { $this->authority->permission($actor,$permission); }
            $visit=Encounter::query()->visibleTo($actor)->where('patient_id',$account->patient_id)->lockForUpdate()->findOrFail($data['encounter_id']);
            $this->authority->version($visit->version,$data['encounter_version']);
            $lines=$splits=[]; $bundle=null; $preauthUsed=[];
            foreach ($data['sources'] as $source) {
                $facts=$this->billing->sourceFacts($account,$visit,$source['type'],(int)$source['id']);
                $day=CarbonImmutable::parse($facts['occurred'],'UTC')->setTimezone('Africa/Lagos')->toDateString();
                if ($day<$coverage->valid_from || $day>$coverage->valid_until) { throw new WriteConflict('hmo_coverage_expired','The verified membership does not cover the actual service date.'); }
                $published=$this->catalog->published($coverage->plan_id,$facts['occurred']);
                if ($published===null) { throw new WriteConflict('hmo_tariff_missing','No approved tariff bundle applies at the actual service time.'); }
                if ($bundle!==null && $bundle->record_id!==$published->record_id) { $this->invalid('sources','Use a separate invoice for sources covered by a different tariff bundle.'); }
                $bundle=$published;
                $key=$facts['serviceId']!==null?'service|'.$facts['serviceId']:'drug|'.$facts['drugId'];
                $tariff=DB::table('hmo_tariffs')->where('import_id',$bundle->record_id)->where('subject_key',$key)->lockForUpdate()->first();
                if ($tariff===null) { throw new WriteConflict('hmo_tariff_missing','This subject is absent from the applicable full tariff bundle.'); }
                $this->authority->version((int)$tariff->id,$source['tariff_id']);
                $total=BillingMoney::charge(FixedPoint::format((int)$tariff->amount_micros),FixedPoint::format($facts['quantity']));
                $patient=self::money($source['patient_amount']); $insurer=self::money($source['insurer_amount']);
                if ($patient+$insurer!==$total) { $this->invalid('sources','The explicit patient and HMO amounts must sum exactly to the rounded charge.'); }
                $preauth=null; $required=$tariff->preauth_required||$context['pr']->preauth_required||$context['vr']->preauth_required;
                if ($insurer>0 && ($required || isset($source['preauth_id']))) {
                    $preauth=DB::table('hmo_preauthorizations')->where('account_id',$account->id)->lockForUpdate()->find($source['preauth_id']??0);
                    if ($preauth===null || $preauth->coverage_id!==$coverage->id || $preauth->encounter_id!==$visit->id
                        || $preauth->service_id!==$facts['serviceId'] || $preauth->drug_id!==$facts['drugId'] || $preauth->status!=='approved'
                        || $this->revoked('preauth',$preauth->id) || $facts['occurred']<$preauth->valid_from || $facts['occurred']>$preauth->valid_until) {
                        throw new WriteConflict('hmo_preauth_invalid','Select the verified, valid authorization for this patient, coverage, visit and subject.');
                    }
                    $used=(int)DB::table('hmo_invoice_shares')->where('preauth_id',$preauth->id)->lockForUpdate()->get()->sum('insurer_kobo');
                    $preauthUsed[$preauth->id]=($preauthUsed[$preauth->id]??0)+$insurer;
                    if ($used+$preauthUsed[$preauth->id]>(int)$preauth->limit_kobo) { throw new WriteConflict('hmo_preauth_exceeded','The cumulative HMO share exceeds the external authorization allowance.'); }
                }
                $label=json_decode($tariff->snapshot,true,flags:JSON_THROW_ON_ERROR);
                $lines[]=['source_type'=>$source['type'],'source_id'=>$source['id'],'source_key'=>$facts['key'],'service_price_id'=>null,'drug_price_id'=>null,
                    'tariff_id'=>$tariff->id,'corrects_item_id'=>null,'quantity_micros'=>$facts['quantity'],'unit_price_micros'=>(int)$tariff->amount_micros,
                    'amount_kobo'=>$total,'description'=>mb_substr($label['name'],0,191),
                    'snapshot'=>array_replace($facts['snapshot'],['coverage_id'=>$coverage->id,'plan_id'=>$coverage->plan_id,
                        'tariff_id'=>$tariff->id,'tariff_version'=>$bundle->published_version,'currency'=>'NGN','rounding'=>'line_half_up_2dp','self_pay_confirmed'=>false])];
                $splits[]=['tariff_id'=>$tariff->id,'preauth_id'=>$preauth?->id,'patient_kobo'=>$patient,'insurer_kobo'=>$insurer,'benefit_reference'=>$source['benefit_reference']];
            }
            $id=$this->billing->postInvoice($account,$visit->id,$lines,$signature);
            $link=DB::table('hmo_invoice_links')->insertGetId(['invoice_id'=>$id,'coverage_id'=>$coverage->id,'import_id'=>$bundle->record_id,
                'snapshot'=>json_encode(['provider_id'=>$context['provider']->id,'plan_id'=>$coverage->plan_id,'coverage_id'=>$coverage->id,
                    'provider_version'=>$context['vr']->version,'plan_version'=>$context['pr']->version],JSON_THROW_ON_ERROR)]+$signature);
            $items=DB::table('billing_invoice_items')->where('invoice_id',$id)->orderBy('id')->lockForUpdate()->get();
            foreach ($items as $index=>$item) { DB::table('hmo_invoice_shares')->insert(['link_id'=>$link,'item_id'=>$item->id]+$splits[$index]+$signature); }
        } else { $id=$this->claims->execute($request,$actor,$account,$data,$signature); }
        $event=DB::table('billing_events')->insertGetId(['account_id'=>$account->id,'version'=>$version+1,'action'=>'hmo.'.$action,'record_id'=>$id]+$signature);
        $this->balances->account($account);
        $this->audit->record('hmo.'.$action,$actor->id,'hmo_account',$account->id,['version'=>$version+1,'record_id'=>$event]);
        return $this->billing->response($account)->setStatusCode(201);
    }

    public function coverage(BillingAccount $account,int $id): stdClass
    {
        $row=DB::table('hmo_coverages')->where('account_id',$account->id)->lockForUpdate()->find($id); abort_if($row===null,404);
        if ($this->revoked('coverage',$id)) { throw new WriteConflict('hmo_coverage_revoked','The selected verification has been revoked.'); }
        return $row;
    }

    private function revoked(string $kind,int $id): bool
    {
        return DB::table('hmo_revocations')->where('kind',$kind)->where('record_id',$id)->lockForUpdate()->first()!==null;
    }

    public function replay(Request $request,User $actor,stdClass $receipt): JsonResponse
    {
        if (self::isCatalog($request)) {
            $response=$this->catalog->response(self::catalogKind($request),(int)$receipt->resource_id);
            $this->authority->version((int)$response->getData(true)['data']['version'],$receipt->resource_version);
            return $response;
        }
        $account=BillingAccount::query()->where('patient_id',$request->attributes->get('hmo_patient')->id)->lockForUpdate()->findOrFail($receipt->resource_id);
        $last=DB::table('billing_events')->where('account_id',$account->id)->orderByDesc('version')->lockForUpdate()->firstOrFail();
        $this->authority->version((int)$last->version,$receipt->resource_version);
        return $this->billing->response($account);
    }

    public static function money(mixed $value,bool $zero=true): int
    {
        try {
            $amount=BillingMoney::parse($value);
            if (!$zero && $amount===0) { throw new \InvalidArgumentException; }
            return $amount;
        } catch (\InvalidArgumentException) { throw ValidationException::withMessages(['amount'=>['Use an exact NGN amount at the supported kobo precision.']]); }
    }

    public static function time(mixed ...$previous): CarbonImmutable
    {
        $time=CarbonImmutable::now('UTC');
        foreach ($previous as $value) { if ($value!==null && $time->lessThan($value)) { throw new WriteConflict('hmo_clock_conflict','The server clock precedes a saved HMO event.'); } }
        return $time;
    }

    private function invalid(string $field,string $message): never { throw ValidationException::withMessages([$field=>[$message]]); }
}
