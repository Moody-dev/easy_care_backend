<?php

namespace App\Services;

use App\Exceptions\WriteConflict;
use App\Models\User;
use App\Models\BillingAccount;
use App\Support\ReportAmounts;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class FinancialReports
{
    public function __construct(private ReportAccess $access) {}

    private function accounts(User $actor, array $filters, ?array $ids=null): \Illuminate\Database\Eloquent\Builder
    {
        return $this->access->accounts($actor,$filters)->when($ids!==null,fn (\Illuminate\Database\Eloquent\Builder $q): \Illuminate\Database\Eloquent\Builder=>$q->whereIn('id',$ids));
    }

    private function allocationTotals(): Builder
    {
        $releases=DB::table('billing_allocation_releases')->select('allocation_id')->selectRaw('SUM(amount_kobo) AS released')->groupBy('allocation_id');
        return DB::table('billing_allocations as a')->leftJoinSub($releases,'r','r.allocation_id','=','a.id')
            ->select('a.invoice_id','a.receipt_id')->selectRaw('SUM(a.amount_kobo-COALESCE(r.released,0)) AS allocated')
            ->groupBy('a.invoice_id','a.receipt_id');
    }

    private function invoices(User $actor,array $filters,?array $ids=null): Builder
    {
        $shares=DB::table('hmo_invoice_shares as s')->join('billing_invoice_items as i','i.id','=','s.item_id')
            ->select('i.invoice_id')->selectRaw('SUM(s.insurer_kobo) AS insurer')->groupBy('i.invoice_id');
        $credits=DB::table('billing_changes as c')->join('billing_change_reviews as r','r.change_id','=','c.id')
            ->join('billing_invoice_items as i','i.id','=','c.item_id')->where('c.kind','credit')->where('r.decision','approved')
            ->select('i.invoice_id')->selectRaw('SUM(c.amount_kobo) AS credited')->groupBy('i.invoice_id');
        $allocations=DB::query()->fromSub($this->allocationTotals(),'a')->select('invoice_id')->selectRaw('SUM(allocated) AS allocated')->groupBy('invoice_id');
        return DB::table('billing_invoices as i')->leftJoin('billing_invoice_posts as p','p.invoice_id','=','i.id')
            ->leftJoinSub($shares,'s','s.invoice_id','=','i.id')->leftJoinSub($credits,'c','c.invoice_id','=','i.id')
            ->leftJoinSub($allocations,'a','a.invoice_id','=','i.id')->whereIn('i.account_id',$this->accounts($actor,$filters,$ids)->select('id'))
            ->select('i.id','i.account_id','i.total_kobo','i.recorded_at','p.id as post_id')
            ->selectRaw('COALESCE(s.insurer,0) AS insurer, COALESCE(c.credited,0) AS credited, COALESCE(a.allocated,0) AS allocated');
    }

    private function receipts(User $actor,array $filters,?array $ids=null): Builder
    {
        $allocations=DB::query()->fromSub($this->allocationTotals(),'a')->select('receipt_id')->selectRaw('SUM(allocated) AS allocated')->groupBy('receipt_id');
        $refunds=DB::table('billing_changes as c')->leftJoin('billing_change_reviews as r','r.change_id','=','c.id')->where('c.kind','refund')
            ->select('c.receipt_id')->selectRaw("SUM(CASE WHEN r.decision='approved' THEN c.amount_kobo ELSE 0 END) AS refunded,
                SUM(CASE WHEN r.id IS NULL THEN c.amount_kobo ELSE 0 END) AS reserved")->groupBy('c.receipt_id');
        return DB::table('billing_receipts as c')->leftJoinSub($allocations,'a','a.receipt_id','=','c.id')
            ->leftJoinSub($refunds,'r','r.receipt_id','=','c.id')->whereIn('c.account_id',$this->accounts($actor,$filters,$ids)->select('id'))
            ->select('c.id','c.account_id','c.amount_kobo','c.paid_at')
            ->selectRaw('COALESCE(a.allocated,0) AS allocated,COALESCE(r.refunded,0) AS refunded,COALESCE(r.reserved,0) AS reserved');
    }

    private function insurerItems(User $actor,array $filters,?array $ids=null): Builder
    {
        $latest=DB::table('hmo_reviews as r')->join('hmo_decisions as d','d.id','=','r.record_id')->where('r.kind','decision')->where('r.outcome','accepted')
            ->select('d.claim_id')->selectRaw('MAX(r.id) AS review_id')->groupBy('d.claim_id');
        $approved=DB::table('hmo_reviews as r')->joinSub(clone $latest,'l','l.review_id','=','r.id')
            ->join('hmo_decision_lines as d','d.decision_id','=','r.record_id')->select('d.item_id','d.approved_kobo');
        $allocated=DB::table('hmo_allocation_lines as l')->leftJoin('hmo_reviews as r',function (\Illuminate\Database\Query\JoinClause $join): void {
            $join->on('r.record_id','=','l.allocation_id')->where('r.kind','allocation');
        })->select('l.item_id')->selectRaw("SUM(CASE WHEN r.outcome='accepted' THEN l.amount_kobo ELSE 0 END) AS settled,
            SUM(CASE WHEN r.id IS NULL THEN l.amount_kobo ELSE 0 END) AS reserved")->groupBy('l.item_id');
        return DB::table('hmo_invoice_shares as s')->join('billing_invoice_items as i','i.id','=','s.item_id')
            ->join('billing_invoices as b','b.id','=','i.invoice_id')->leftJoin('hmo_claims as c','c.invoice_id','=','b.id')
            ->leftJoinSub($latest,'r','r.claim_id','=','c.id')->leftJoinSub($approved,'v','v.item_id','=','i.id')
            ->leftJoinSub($allocated,'a','a.item_id','=','i.id')->whereIn('b.account_id',$this->accounts($actor,$filters,$ids)->select('id'))
            ->select('s.id','b.account_id','s.insurer_kobo','r.review_id','v.approved_kobo')
            ->selectRaw('COALESCE(a.settled,0) AS settled,COALESCE(a.reserved,0) AS reserved');
    }

    private function collect(Builder $query,array $fields,callable $values,string $column='id',bool $byAccount=false): array
    {
        $empty=['totals'=>array_fill_keys($fields,'0'),'records'=>0];
        $groups=$byAccount?[]:['all'=>$empty];
        $query->chunkById(200,function (\Illuminate\Support\Collection $batch) use (&$groups,$empty,$values,$byAccount): void {
            foreach ($batch as $row) {
                $group=$byAccount?$row->account_id:'all';
                $groups[$group]??=$empty;
                $groups[$group]['records']++;
                foreach ($values($row) as $key=>$value) {
                    $groups[$group]['totals'][$key]=ReportAmounts::add($groups[$group]['totals'][$key],$value);
                }
            }
        },$column,'id');
        $result=array_map(fn (array $group): array=>['amounts'=>array_map(ReportAmounts::decimal(...),$group['totals']),'records'=>$group['records']],$groups);
        return $byAccount?$result:$result['all'];
    }

    private function invoiceValues(\stdClass $row): array
    {
        $gross=(int)$row->total_kobo; $patient=$gross-(int)$row->insurer;
        $due=$patient-(int)$row->credited-(int)$row->allocated;
        if ($row->post_id===null || $patient<0 || $due<0 || (int)$row->allocated<0) { $this->conflict(); }
        return ['gross_charges'=>$gross,'patient_charges'=>$patient,'credited'=>(int)$row->credited,
            'allocated'=>(int)$row->allocated,'outstanding'=>$due];
    }

    private function receiptValues(\stdClass $row): array
    {
        $available=(int)$row->amount_kobo-(int)$row->allocated-(int)$row->refunded-(int)$row->reserved;
        if ($available<0 || (int)$row->allocated<0) { $this->conflict(); }
        return ['received'=>(int)$row->amount_kobo,'refunded'=>(int)$row->refunded,'refund_reserved'=>(int)$row->reserved,'available_credit'=>$available];
    }

    private function hmoValues(\stdClass $row): array
    {
        if ($row->review_id!==null && $row->approved_kobo===null && (int)$row->insurer_kobo>0) { $this->conflict(); }
        $approved=(int)($row->approved_kobo??0); $liability=(int)$row->insurer_kobo;
        $settled=(int)$row->settled; $reserved=(int)$row->reserved;
        if ($approved>$liability || $settled+$reserved>$approved) { $this->conflict(); }
        return ['insurer_liability'=>$liability,'insurer_approved'=>$approved,'insurer_settled'=>$settled,'insurer_reserved'=>$reserved,
            'insurer_unreviewed'=>$row->review_id===null?$liability:0,'insurer_disputed'=>$row->review_id===null?0:$liability-$approved,
            'insurer_outstanding'=>$liability-$settled];
    }

    public function current(User $actor,array $filters,?array $ids=null,bool $includeHmo=false): array
    {
        $invoices=$this->collect($this->invoices($actor,$filters,$ids),['gross_charges','patient_charges','credited','allocated','outstanding'],$this->invoiceValues(...),'i.id');
        $receipts=$this->collect($this->receipts($actor,$filters,$ids),['received','refunded','refund_reserved','available_credit'],$this->receiptValues(...),'c.id');
        $data=['account_count'=>$this->accounts($actor,$filters,$ids)->count(),'invoice_count'=>$invoices['records'],'receipt_count'=>$receipts['records'],
            'amounts'=>$invoices['amounts']+$receipts['amounts']];
        if ($includeHmo) {
            $hmo=$this->collect($this->insurerItems($actor,$filters,$ids),['insurer_liability','insurer_approved','insurer_settled','insurer_reserved',
                'insurer_unreviewed','insurer_disputed','insurer_outstanding'],$this->hmoValues(...),'s.id');
            $data['amounts']+=$hmo['amounts'];
        }
        return $data;
    }

    private function amountRows(Builder $query,string $column): array
    {
        return $this->collect($query,['amount'],fn (\stdClass $row): array=>['amount'=>(string)$row->amount_kobo],$column);
    }

    public function billing(User $actor,ReportPeriod $period): array
    {
        $posted=$this->collect($period->apply($this->invoices($actor,$period->filters),'i.recorded_at'),
            ['gross_charges','patient_charges'],function (\stdClass $row): array {
                $values=$this->invoiceValues($row);
                return array_intersect_key($values,array_flip(['gross_charges','patient_charges']));
            },'i.id');
        $scope=$this->accounts($actor,$period->filters)->select('id');
        $receipts=$period->apply(DB::table('billing_receipts as c')->whereIn('c.account_id',clone $scope)->select('c.id','c.amount_kobo'),'c.paid_at');
        $refunds=$period->apply(DB::table('billing_change_reviews as r')->join('billing_changes as c','c.id','=','r.change_id')
            ->where('c.kind','refund')->where('r.decision','approved')->whereIn('c.account_id',clone $scope)->select('r.id','c.amount_kobo'),'r.payout_at');
        $credits=$period->apply(DB::table('billing_change_reviews as r')->join('billing_changes as c','c.id','=','r.change_id')
            ->where('c.kind','credit')->where('r.decision','approved')->whereIn('c.account_id',clone $scope)->select('r.id','c.amount_kobo'),'r.recorded_at');
        return ['currency'=>'NGN','period'=>['invoices_posted'=>$posted,'physical_payments_received'=>$this->amountRows($receipts,'c.id'),
            'physical_refunds_paid'=>$this->amountRows($refunds,'r.id'),'credits_approved'=>$this->amountRows($credits,'r.id')],
            'current'=>$this->current($actor,$period->filters),'balance_basis'=>'all retained transactions of visible patient accounts, regardless of selected dates',
            'payments_processed_by_platform'=>false];
    }

    public function hmo(User $actor,ReportPeriod $period): array
    {
        $accounts=$this->accounts($actor,$period->filters)->select('id');
        $claims=DB::table('hmo_claims as c')->whereIn('c.account_id',clone $accounts);
        $submissions=$period->apply(DB::table('hmo_submissions as s')->whereIn('s.claim_id',(clone $claims)->select('c.id')),'s.submitted_at');
        $settlements=$period->apply(DB::table('hmo_reviews as r')->join('hmo_allocations as a','a.id','=','r.record_id')

            ->where('r.kind','allocation')->where('r.outcome','accepted')->whereIn('a.account_id',clone $accounts)->select('r.id','a.amount_kobo'),'r.recorded_at');
        $current=$this->collect($this->insurerItems($actor,$period->filters),['insurer_liability','insurer_approved','insurer_settled','insurer_reserved',
            'insurer_unreviewed','insurer_disputed','insurer_outstanding'],$this->hmoValues(...),'s.id');
        return ['currency'=>'NGN','period'=>['submission_attempts'=>(clone $submissions)->count(),'distinct_claims_submitted'=>(clone $submissions)->distinct()->count('s.claim_id'),
            'settlement_allocations_posted'=>$this->amountRows($settlements,'r.id')],
            'current'=>['claim_count'=>$claims->count(),'amounts'=>$current['amounts']],
            'balance_basis'=>'all retained payer shares and reviewed outcomes of visible patient accounts',
            'provider_remittance_cashflow_included'=>false,'automatic_patient_debt_transfer'=>false];
    }

    public function accountsPage(User $actor,ReportPeriod $period,bool $includeHmo): array
    {
        $page=$this->accounts($actor,$period->filters)->orderBy('id')->paginate($period->filters['per_page']??25,['*'],'page',$period->filters['page']??1)->appends($period->filters);
        $totals=$this->accountTotals($actor,$period->filters,$page->getCollection()->modelKeys(),$includeHmo);
        $page->through(fn (BillingAccount $account): array=>['account_id'=>$account->id,'patient_id'=>$account->patient_id,
            'current'=>$totals[$account->id]]);
        return $page->toArray()+['currency'=>'NGN','basis'=>'current lifetime balances; the date range does not truncate account balances'];
    }

    private function accountTotals(User $actor,array $filters,array $ids,bool $includeHmo): array
    {
        if ($ids===[]) { return []; }
        $invoiceFields=['gross_charges','patient_charges','credited','allocated','outstanding'];
        $receiptFields=['received','refunded','refund_reserved','available_credit'];
        $hmoFields=['insurer_liability','insurer_approved','insurer_settled','insurer_reserved','insurer_unreviewed','insurer_disputed','insurer_outstanding'];
        $invoices=$this->collect($this->invoices($actor,$filters,$ids),$invoiceFields,$this->invoiceValues(...),'i.id',byAccount:true);
        $receipts=$this->collect($this->receipts($actor,$filters,$ids),$receiptFields,$this->receiptValues(...),'c.id',byAccount:true);
        $hmo=$includeHmo?$this->collect($this->insurerItems($actor,$filters,$ids),$hmoFields,$this->hmoValues(...),'s.id',byAccount:true):[];
        $out=[];
        foreach ($ids as $id) {
            $out[$id]=['account_count'=>1,'invoice_count'=>$invoices[$id]['records']??0,'receipt_count'=>$receipts[$id]['records']??0,
                'amounts'=>($invoices[$id]['amounts']??array_fill_keys($invoiceFields,'0.00'))
                    +($receipts[$id]['amounts']??array_fill_keys($receiptFields,'0.00'))
                    +($includeHmo?($hmo[$id]['amounts']??array_fill_keys($hmoFields,'0.00')):[])];
        }
        return $out;
    }

    private function conflict(): never
    {
        throw new WriteConflict('report_ledger_conflict','Financial records require reconciliation before these figures can be reported.');
    }
}
