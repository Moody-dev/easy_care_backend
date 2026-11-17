<?php

namespace App\Services;

use App\Exceptions\WriteConflict;
use App\Models\BillingAccount;
use App\Models\User;
use App\Support\BillingMoney;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use stdClass;

class HmoClaims
{
    public function __construct(private StockAuthority $authority,private HmoBalances $balances,private HmoCatalog $catalog) {}

    public function context(BillingAccount $account,int $claimId): array
    {
        $claim=DB::table('hmo_claims')->where('account_id',$account->id)->lockForUpdate()->find($claimId);
        abort_if($claim===null,404);
        $link=DB::table('hmo_invoice_links')->where('invoice_id',$claim->invoice_id)->lockForUpdate()->firstOrFail();
        $coverage=DB::table('hmo_coverages')->lockForUpdate()->find($link->coverage_id);
        $plan=$this->catalog->plan($coverage->plan_id,false);
        return ['claim'=>$claim,'link'=>$link,'coverage'=>$coverage]+$plan;
    }

    public function execute(Request $request,User $actor,BillingAccount $account,array $data,array $signature): int
    {
        $action=HmoWorkflows::action($request); $id=(int)$request->route('hmoRecord');
        $state=$this->balances->account($account);
        if ($action==='claim-submit') {
            $invoice=DB::table('billing_invoices')->where('account_id',$account->id)->lockForUpdate()->find($data['invoice_id']);
            abort_if($invoice===null,404);
            $link=DB::table('hmo_invoice_links')->where('invoice_id',$invoice->id)->lockForUpdate()->first();
            if ($link===null || ($state['invoiceData'][$invoice->id]??0)<=0) {
                $this->invalid('invoice_id','Select an HMO invoice with an insurer share.');
            }
            if (DB::table('hmo_claims')->where('invoice_id',$invoice->id)->lockForUpdate()->first()!==null) {
                throw new WriteConflict('hmo_claim_exists','This invoice already has a claim. Review its retained submissions.');
            }
            $id=DB::table('hmo_claims')->insertGetId(['account_id'=>$account->id,'invoice_id'=>$invoice->id]+$signature);
            $context=$this->context($account,$id);
            $this->submit($context,$data,$signature,1);
            return $id;
        }
        if ($action==='claim-resubmit') {
            $context=$this->context($account,$id);
            $claimState=$state['claimData'][$id];
            if ($claimState['latest_decision']===null || $claimState['pending_decisions']->isNotEmpty()
                || $claimState['disputed']===0) {
                throw new WriteConflict('hmo_resubmission_unavailable','Resubmit only an independently recorded partial/rejected claim with unresolved insurer liability.');
            }
            $previous=$claimState['submission'];
            if ($claimState['latest_decision']->submission_id!==$previous->id) {
                throw new WriteConflict('hmo_submission_pending','The current submission still awaits a reviewed payer response.');
            }
            $this->submit($context,$data,$signature,(int)$previous->sequence+1);
            return $id;
        }
        if ($action==='decision-record') {
            $context=$this->context($account,(int)$data['claim_id']);
            $claimState=$state['claimData'][$context['claim']->id];
            if ($claimState['pending_decisions']->isNotEmpty()) { throw new WriteConflict('hmo_decision_pending','Review the pending payer response first.'); }
            $submission=$claimState['submission'];
            $this->authority->version((int)$submission->id,$data['submission_id']);
            $occurred=\Carbon\CarbonImmutable::parse($data['occurred_at'])->utc();
            if ($occurred->greaterThan($signature['recorded_at']) || $occurred->lessThan($submission->submitted_at)) {
                $this->invalid('occurred_at','Record an actual payer response after submission and no later than now.');
            }
            $expected=$state['shares']->where('invoice_id',$context['claim']->invoice_id)->where('insurer_kobo','>',0)->keys()->sort()->values()->all();
            $provided=array_map('intval',array_column($data['lines'],'item_id')); sort($provided);
            if ($expected!==$provided) { $this->invalid('lines','Include each insurer-liability invoice line exactly once.'); }
            $id=DB::table('hmo_decisions')->insertGetId(['claim_id'=>$context['claim']->id,'submission_id'=>$submission->id,
                'reference'=>$data['reference'],'occurred_at'=>$occurred->format('Y-m-d H:i:s.u')]+$signature);
            foreach ($data['lines'] as $line) {
                $amount=HmoWorkflows::money($line['approved_amount']);
                if ($amount>$state['items'][$line['item_id']]['insurer']) { $this->invalid('lines','A payer approval cannot exceed the retained insurer share.'); }
                DB::table('hmo_decision_lines')->insert(['decision_id'=>$id,'item_id'=>$line['item_id'],'approved_kobo'=>$amount]+array_replace($signature,['reason'=>$line['reason']]));
            }
            $this->catalog->bind($context['provider']->id,'decision',$data['reference'],$context['claim']->id,$signature);
            return $id;
        }
        if ($action==='decision-review') {
            $decision=DB::table('hmo_decisions')->lockForUpdate()->find($id); abort_if($decision===null,404);
            $context=$this->context($account,$decision->claim_id);
            $this->catalog->reviewable('decision',$decision,$actor,$data['version']);
            if ($data['outcome']==='accepted') {
                $claimState=$state['claimData'][$decision->claim_id];
                if ($claimState['submission']->id!==$decision->submission_id
                    || $claimState['latest_decision']!==null && $decision->occurred_at<$claimState['latest_decision']->occurred_at) {
                    throw new WriteConflict('hmo_response_outdated','This response would replace a newer submission or payer decision.');
                }
                $lines=DB::table('hmo_decision_lines')->where('decision_id',$id)->lockForUpdate()->get();
                $expected=$state['shares']->where('invoice_id',$context['claim']->invoice_id)->where('insurer_kobo','>',0)->keys()->sort()->values()->all();
                if ($lines->pluck('item_id')->sort()->values()->all()!==$expected) {
                    throw new WriteConflict('hmo_decision_integrity','The retained response must include every insurer-liability line before acceptance.');
                }
                foreach ($lines as $line) {
                    $current=$state['items'][$line->item_id];
                    if ((int)$line->approved_kobo<$current['settled']+$current['reserved']) {
                        throw new WriteConflict('hmo_decision_below_settlement','Resolve recorded/reserved payer allocations before accepting a lower approval.');
                    }
                }
            }
            return DB::table('hmo_reviews')->insertGetId(['kind'=>'decision','record_id'=>$id,'provider_id'=>$context['provider']->id,
                'account_id'=>$account->id,'outcome'=>$data['outcome'],'published_version'=>null,'effective_at'=>null]+$signature);
        }
        if ($action==='allocation-record') {
            $context=$this->context($account,(int)$data['claim_id']);
            $claimState=$state['claimData'][$context['claim']->id];
            if ($claimState['pending_decisions']->isNotEmpty()) { throw new WriteConflict('hmo_decision_pending','Review the pending payer response before new allocations.'); }
            $receipt=$this->balances->remittance((int)$data['remittance_id']);
            if ($receipt['record']->provider_id!==$context['provider']->id) { abort(404); }
            $this->authority->version($receipt['version'],$data['remittance_version']);
            if ($receipt['review']?->outcome!=='accepted') { throw new WriteConflict('hmo_receipt_unreviewed','Independently confirm the outside remittance first.'); }
            $key=hash('sha256',mb_strtoupper(trim($data['reference'])));
            $old=DB::table('hmo_allocations')->where('remittance_id',$data['remittance_id'])->where('reference_key',$key)->lockForUpdate()->get();
            foreach ($old as $requestRow) {
                $review=DB::table('hmo_reviews')->where('kind','allocation')->where('record_id',$requestRow->id)->lockForUpdate()->first();
                if ($review?->outcome!=='rejected') { throw new WriteConflict('hmo_allocation_recorded','This remittance line is already retained or awaiting review.'); }
            }
            $lines=[]; $total=0;
            foreach ($data['lines'] as $line) {
                $share=$state['shares']->get($line['item_id']);
                abort_if($share===null || $share->invoice_id!==$context['claim']->invoice_id,404);
                $amount=HmoWorkflows::money($line['amount'],false); $current=$state['items'][$line['item_id']];
                if ($amount>$current['approved']-$current['settled']-$current['reserved']) {
                    throw new WriteConflict('hmo_allocation_exceeded','Allocate only independently approved, unpaid and unreserved insurer liability.');
                }
                $lines[]=['item_id'=>$line['item_id'],'amount_kobo'=>$amount]; $total+=$amount;
            }
            if ($total>$receipt['available'] || $total>BillingMoney::MAX) { throw new WriteConflict('hmo_receipt_exceeded','The remittance has insufficient available funds.'); }
            $id=DB::table('hmo_allocations')->insertGetId(['account_id'=>$account->id,'claim_id'=>$context['claim']->id,
                'remittance_id'=>$receipt['record']->id,'amount_kobo'=>$total,'reference'=>$data['reference'],'reference_key'=>$key]+$signature);
            foreach ($lines as $line) { DB::table('hmo_allocation_lines')->insert(['allocation_id'=>$id]+$line+$signature); }
            return $id;
        }
        $allocation=DB::table('hmo_allocations')->where('account_id',$account->id)->lockForUpdate()->find($id); abort_if($allocation===null,404);
        $context=$this->context($account,$allocation->claim_id);
        $this->catalog->reviewable('allocation',$allocation,$actor,$data['version']);
        $receipt=$this->balances->remittance((int)$allocation->remittance_id);
        $this->authority->version($receipt['version'],$data['remittance_version']);
        if ($data['outcome']==='accepted') {
            if ($receipt['review']?->outcome!=='accepted' || $receipt['available']<0) { throw new WriteConflict('hmo_receipt_exceeded','The remittance requires reconciliation.'); }
            $lines=DB::table('hmo_allocation_lines')->where('allocation_id',$id)->lockForUpdate()->get();
            if ($lines->isEmpty() || (int)$lines->sum('amount_kobo')!==(int)$allocation->amount_kobo) {
                throw new WriteConflict('hmo_allocation_integrity','The retained allocation lines must equal its recorded remittance amount before posting.');
            }
            foreach ($lines as $line) {
                $current=$state['items'][$line->item_id];
                if ($current['approved']<$current['settled']+$current['reserved']) {
                    throw new WriteConflict('hmo_allocation_exceeded','A payer approval changed below the reserved amount.');
                }
            }
            $this->catalog->bind($context['provider']->id,'allocation',$allocation->remittance_id.'|'.$allocation->reference,$id,$signature);
        }
        return DB::table('hmo_reviews')->insertGetId(['kind'=>'allocation','record_id'=>$id,'provider_id'=>$context['provider']->id,
            'account_id'=>$account->id,'outcome'=>$data['outcome'],'published_version'=>null,'effective_at'=>null]+$signature);
    }

    private function submit(array $context,array $data,array $signature,int $sequence): void
    {
        $time=\Carbon\CarbonImmutable::parse($data['submitted_at'])->utc();
        if ($time->greaterThan($signature['recorded_at'])) { $this->invalid('submitted_at','Record a submission that actually happened outside the platform.'); }
        $previous=DB::table('hmo_submissions')->where('claim_id',$context['claim']->id)->orderByDesc('sequence')->lockForUpdate()->first();
        if ($previous!==null && $time->lessThan($previous->submitted_at)) { $this->invalid('submitted_at','Submission history cannot move backwards.'); }
        $invoice=DB::table('billing_invoices')->lockForUpdate()->find($context['claim']->invoice_id);
        if ($time->lessThan($invoice->recorded_at)) { $this->invalid('submitted_at','A claim cannot be submitted before its invoice exists.'); }
        $items=DB::table('billing_invoice_items as i')->join('hmo_invoice_shares as s','s.item_id','=','i.id')
            ->where('i.invoice_id',$invoice->id)->where('s.insurer_kobo','>',0)
            ->select('i.id','i.description','i.amount_kobo','s.patient_kobo','s.insurer_kobo','s.preauth_id','s.benefit_reference')->lockForUpdate()->get();
        DB::table('hmo_submissions')->insert(['claim_id'=>$context['claim']->id,'sequence'=>$sequence,'reference'=>$data['reference'],
            'submitted_at'=>$time->format('Y-m-d H:i:s.u'),'snapshot'=>json_encode(['invoice_id'=>$invoice->id,'coverage_id'=>$context['coverage']->id,
                'plan_id'=>$context['plan']->id,'provider_id'=>$context['provider']->id,'items'=>$items->all()],JSON_THROW_ON_ERROR)]+$signature);
        $this->catalog->bind($context['provider']->id,'submission',$data['reference'],$context['claim']->id,$signature);
    }

    private function invalid(string $field,string $message): never { throw ValidationException::withMessages([$field=>[$message]]); }
}
