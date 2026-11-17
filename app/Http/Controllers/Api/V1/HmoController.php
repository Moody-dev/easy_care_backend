<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\HmoCommandRequest;
use App\Models\BillingAccount;
use App\Models\Patient;
use App\Services\AuditTrail;
use App\Services\HmoBalances;
use App\Services\HmoCatalog;
use App\Services\HmoTariffCsv;
use App\Services\HmoWorkflows;
use App\Services\IdempotentCommands;
use App\Support\BillingMoney;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

class HmoController extends Controller
{
    public function options(): JsonResponse
    {
        return response()->json(['data'=>['currency'=>'NGN','timezone'=>'Africa/Lagos','csv_header'=>HmoTariffCsv::HEADER,
            'max_csv_bytes'=>1048576,'max_tariffs'=>2000,'automatic_eligibility'=>false,'insurer_communication_automatic'=>false,
            'payments_processed_by_platform'=>false,'automatic_patient_debt_transfer'=>false,'independent_publication_decisions_and_settlements'=>true]]);
    }

    public function validateCsv(Request $request,HmoTariffCsv $csv): JsonResponse
    {
        $data=$request->validate(['csv_content'=>['required','string','max:1048576']]);
        $rows=DB::transaction(fn (): array=>$csv->parse($data['csv_content']));
        return response()->json(['data'=>['valid'=>true,'row_count'=>count($rows),'sha256'=>hash('sha256',$data['csv_content'])]]);
    }

    public function catalog(Request $request,HmoCatalog $catalog,AuditTrail $audit): JsonResponse
    {
        $kind=$request->route('catalogKind');
        $data=$request->validate(['page'=>['sometimes','integer','min:1'],'per_page'=>['sometimes','integer','min:1','max:100'],
            'provider_id'=>['sometimes','integer','min:1'],'plan_id'=>['sometimes','integer','min:1']]);
        $table=['providers'=>'hmo_providers','plans'=>'hmo_plans','imports'=>'hmo_tariff_imports','remittances'=>'hmo_remittances'][$kind];
        $query=DB::table($table);
        if (isset($data['provider_id']) && in_array($kind,['plans','remittances'],true)) { $query->where('provider_id',$data['provider_id']); }
        if (isset($data['plan_id']) && $kind==='imports') { $query->where('plan_id',$data['plan_id']); }
        if (in_array($kind,['imports','remittances'],true)) {
            $reviewKind=$kind==='imports'?'tariff':'remittance';
            $reviewPermission=$kind==='imports'?'hmo.tariffs.publish':'hmo.settlements.approve';
            if (!$request->user('web')->hasPermission($reviewPermission)) {
                $query->where(function (\Illuminate\Database\Query\Builder $q) use ($request,$table,$reviewKind): void {
                    $q->where('actor_id',$request->user('web')->id)->orWhereExists(fn (\Illuminate\Database\Query\Builder $q): \Illuminate\Database\Query\Builder=>$q->selectRaw('1')->from('hmo_reviews as r')
                        ->where('r.kind',$reviewKind)->where('r.outcome','accepted')->whereColumn('r.record_id',$table.'.id'));
                });
            }
        }
        $page=$query->orderByDesc('id')->paginate($data['per_page']??25);
        $resourceKind=['providers'=>'provider','plans'=>'plan','imports'=>'tariff','remittances'=>'remittance'][$kind];
        $page->through(fn (\stdClass $row): array => $catalog->response($resourceKind,(int)$row->id)->getData(true)['data']);
        $audit->record('hmo.catalog_viewed',$request->user('web')->id,'hmo_catalog');
        return response()->json($page);
    }

    public function catalogShow(Request $request,HmoCatalog $catalog,AuditTrail $audit): JsonResponse
    {
        $kind=$request->route('resourceKind'); $id=(int)$request->route('hmoRecord');
        if ($kind==='tariff'||$kind==='remittance') {
            $row=DB::table($kind==='tariff'?'hmo_tariff_imports':'hmo_remittances')->find($id); abort_if($row===null,404);
            $review=DB::table('hmo_reviews')->where('kind',$kind)->where('record_id',$id)->first();
            abort_unless($review?->outcome==='accepted' || $row->actor_id===$request->user('web')->id
                || $request->user('web')->hasPermission($kind==='tariff'?'hmo.tariffs.publish':'hmo.settlements.approve'),404);
        }
        $audit->record('hmo.catalog_viewed',$request->user('web')->id,'hmo_catalog',$id);
        return DB::transaction(fn (): JsonResponse=>$catalog->response($kind,$id));
    }

    public function tariffRows(Request $request,AuditTrail $audit): JsonResponse
    {
        $data=$request->validate(['page'=>['sometimes','integer','min:1'],'per_page'=>['sometimes','integer','min:1','max:100']]);
        $id=(int)$request->route('hmoRecord');
        $import=DB::table('hmo_tariff_imports')->find($id); abort_if($import===null,404);
        $review=DB::table('hmo_reviews')->where('kind','tariff')->where('record_id',$id)->first();
        abort_unless($review?->outcome==='accepted'||$import->actor_id===$request->user('web')->id||$request->user('web')->hasPermission('hmo.tariffs.publish'),404);
        $audit->record('hmo.catalog_viewed',$request->user('web')->id,'hmo_catalog',$id);
        return response()->json(DB::table('hmo_tariffs')->where('import_id',$id)->orderBy('id')->paginate($data['per_page']??25)
            ->through(fn (\stdClass $row): array=>HmoCatalog::format((array)$row)));
    }

    public function csvSource(Request $request,AuditTrail $audit): \Symfony\Component\HttpFoundation\Response
    {
        $row=DB::table('hmo_tariff_imports')->find($request->route('hmoRecord')); abort_if($row===null,404);
        abort_unless($request->user('web')->id===$row->actor_id||$request->user('web')->hasPermission('hmo.tariffs.publish'),404);
        $csv=Crypt::decryptString($row->csv_ciphertext);
        abort_unless(hash('sha256',$csv)===$row->sha256 && strlen($csv)===(int)$row->byte_size,409);
        $audit->record('hmo.csv_downloaded',$request->user('web')->id,'hmo_catalog',$row->id);
        return response($csv,200,['Content-Type'=>'text/csv; charset=UTF-8','Content-Disposition'=>'attachment; filename="'.$row->filename.'"',
            'X-Content-Type-Options'=>'nosniff','Cache-Control'=>'no-store, private']);
    }

    public function records(Request $request,AuditTrail $audit): JsonResponse
    {
        $data=$request->validate(['page'=>['sometimes','integer','min:1'],'per_page'=>['sometimes','integer','min:1','max:100'],'claim_id'=>['sometimes','integer','min:1']]);
        Patient::query()->visibleTo($request->user('web'))->findOrFail($request->route('patient'));
        $account=BillingAccount::query()->where('patient_id',$request->route('patient'))->firstOrFail();
        $kind=$request->route('kind');
        if ($kind==='submissions'||$kind==='decisions') {
            $table=$kind==='submissions'?'hmo_submissions':'hmo_decisions';
            $query=DB::table($table.' as r')->join('hmo_claims as c','c.id','=','r.claim_id')->where('c.account_id',$account->id)->select('r.*');
            if (isset($data['claim_id'])) { $query->where('r.claim_id',$data['claim_id']); }
            $order='r.id';
        } elseif ($kind==='shares') {
            $query=DB::table('hmo_invoice_shares as r')->join('billing_invoice_items as i','i.id','=','r.item_id')
                ->join('billing_invoices as b','b.id','=','i.invoice_id')->where('b.account_id',$account->id)->select('r.*');
            $order='r.id';
        } else {
            $table=['coverages'=>'hmo_coverages','preauthorizations'=>'hmo_preauthorizations','revocations'=>'hmo_revocations','claims'=>'hmo_claims',
                'allocations'=>'hmo_allocations','reviews'=>'hmo_reviews'][$kind];
            $query=DB::table($table)->where('account_id',$account->id); $order='id';
        }
        $audit->record('hmo.viewed',$request->user('web')->id,'hmo_account',$account->id);
        return response()->json($query->orderByDesc($order)->paginate($data['per_page']??25)->through(function (\stdClass $row) use ($kind): array {
            $record=HmoCatalog::format((array)$row);
            if (in_array($kind,['decisions','allocations'],true)) {
                $reviewKind=$kind==='decisions'?'decision':'allocation';
                $table=$kind==='decisions'?'hmo_decision_lines':'hmo_allocation_lines';
                $foreign=$kind==='decisions'?'decision_id':'allocation_id';
                $record['lines']=DB::table($table)->where($foreign,$row->id)->orderBy('id')->get()
                    ->map(fn (\stdClass $line): array => HmoCatalog::format((array)$line))->all();
                $review=DB::table('hmo_reviews')->where('kind',$reviewKind)->where('record_id',$row->id)->first();
                $record['version']=$review===null?1:2;
                $record['review']=$review===null?null:HmoCatalog::format((array)$review);
            }
            return $record;
        }));
    }

    public function claim(Request $request,HmoBalances $balances,AuditTrail $audit): JsonResponse
    {
        return DB::transaction(function () use ($request,$balances,$audit): JsonResponse {
            Patient::query()->visibleTo($request->user('web'))->lockForUpdate()->findOrFail($request->route('patient'));
            $account=BillingAccount::query()->where('patient_id',$request->route('patient'))->lockForUpdate()->firstOrFail();
            $state=$balances->account($account); $id=(int)$request->route('hmoRecord'); $claim=$state['claims']->get($id); abort_if($claim===null,404);
            $status=$state['claimData'][$id]; $details=[];
            foreach (['liability','approved','settled','reserved','disputed','approved_unpaid'] as $key) { $details[$key]=BillingMoney::format($status[$key]); }
            $details['submission']=$status['submission']===null?null:HmoCatalog::format((array)$status['submission']);
            $details['latest_decision']=$status['latest_decision']===null?null:HmoCatalog::format((array)$status['latest_decision']);
            $details['pending_decision_ids']=$status['pending_decisions']->pluck('id')->all();
            $details['lines']=$state['shares']->where('invoice_id',$claim->invoice_id)->map(function (\stdClass $share) use ($state): array {
                return ['item_id'=>$share->item_id]+array_map(BillingMoney::format(...),$state['items'][$share->item_id]);
            })->values()->all();
            $audit->record('hmo.viewed',$request->user('web')->id,'hmo_account',$account->id);
            return response()->json(['data'=>HmoCatalog::format((array)$claim)+$details]);
        });
    }

    public function reconciliation(Request $request,HmoBalances $balances,AuditTrail $audit): JsonResponse
    {
        return DB::transaction(function () use ($request,$balances,$audit): JsonResponse {
            Patient::query()->visibleTo($request->user('web'))->lockForUpdate()->findOrFail($request->route('patient'));
            $account=BillingAccount::query()->where('patient_id',$request->route('patient'))->lockForUpdate()->firstOrFail();
            $state=$balances->account($account); $issues=[];
            foreach ($state['items'] as $id=>$line) {
                if ($line['approved']>$line['insurer'] || $line['settled']+$line['reserved']>$line['approved']) { $issues[]=['item_id'=>$id]; }
            }
            foreach (DB::table('hmo_invoice_links as l')->join('billing_invoices as i','i.id','=','l.invoice_id')
                ->where('i.account_id',$account->id)->select('l.*','i.item_count')->get() as $link) {
                $shares=$state['shares']->where('link_id',$link->id);
                if ($shares->count()!==(int)$link->item_count) { $issues[]=['kind'=>'invoice_shares','invoice_id'=>$link->invoice_id]; }
                foreach ($shares as $share) {
                    $item=DB::table('billing_invoice_items')->find($share->item_id);
                    if ((int)$share->patient_kobo+(int)$share->insurer_kobo!==(int)$item->amount_kobo) {
                        $issues[]=['kind'=>'invoice_split','item_id'=>$share->item_id];
                    }
                }
            }
            foreach ($state['allocations'] as $allocation) {
                $sum=(int)$state['allocationLines']->where('allocation_id',$allocation->id)->sum('amount_kobo');
                if ($sum!==(int)$allocation->amount_kobo) { $issues[]=['kind'=>'allocation_lines','allocation_id'=>$allocation->id]; }
            }
            foreach ($state['allocations']->pluck('remittance_id')->unique() as $id) {
                if ($balances->remittance($id)['available']<0) { $issues[]=['remittance_id'=>$id]; }
            }
            $audit->record('hmo.reconciled',$request->user('web')->id,'hmo_account',$account->id);
            return response()->json(['data'=>['id'=>$account->id,'balanced'=>$issues===[],'issues'=>$issues,
                'totals'=>array_map(BillingMoney::format(...),$state['totals'])]]);
        });
    }

    public function write(Request $request,HmoWorkflows $service,IdempotentCommands $commands): JsonResponse
    {
        return $commands->run($request,fn (): JsonResponse=>$service->execute($request,$request->user('web'),app(HmoCommandRequest::class)->validated()));
    }
}
