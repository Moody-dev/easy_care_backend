<?php

namespace App\Services;

use App\Exceptions\WriteConflict;
use App\Models\BillingAccount;
use App\Support\BillingMoney;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Collection;

class HmoBalances
{
    public function rows(Builder $query,bool $lock=true): Collection
    {
        return $query->when($lock,fn (Builder $query): Builder=>$query->lockForUpdate())->get()->keyBy('id');
    }

    /** @return array<string,mixed> */
    public function account(BillingAccount $account,bool $lock=true): array
    {
        $shares=$this->rows(DB::table('hmo_invoice_shares as s')->join('billing_invoice_items as i','i.id','=','s.item_id')
            ->join('billing_invoices as b','b.id','=','i.invoice_id')->where('b.account_id',$account->id)->select('s.*','i.invoice_id'),$lock)->keyBy('item_id');
        $claims=$this->rows(DB::table('hmo_claims')->where('account_id',$account->id),$lock);
        $submissions=$this->rows(DB::table('hmo_submissions')->whereIn('claim_id',$claims->keys()),$lock);
        $decisions=$this->rows(DB::table('hmo_decisions')->whereIn('claim_id',$claims->keys()),$lock);
        $decisionReviews=$this->rows(DB::table('hmo_reviews')->where('kind','decision')->whereIn('record_id',$decisions->keys()),$lock)->keyBy('record_id');
        $decisionLines=$this->rows(DB::table('hmo_decision_lines')->whereIn('decision_id',$decisions->keys()),$lock);
        $allocations=$this->rows(DB::table('hmo_allocations')->where('account_id',$account->id),$lock);
        $allocationReviews=$this->rows(DB::table('hmo_reviews')->where('kind','allocation')->whereIn('record_id',$allocations->keys()),$lock)->keyBy('record_id');
        $allocationLines=$this->rows(DB::table('hmo_allocation_lines')->whereIn('allocation_id',$allocations->keys()),$lock);
        $items=$invoiceData=$claimData=[]; $reviewedLiability=0;
        foreach ($shares as $item=>$share) {
            $items[$item]=['insurer'=>(int)$share->insurer_kobo,'patient'=>(int)$share->patient_kobo,'approved'=>0,'settled'=>0,'reserved'=>0];
            $invoiceData[$share->invoice_id]=($invoiceData[$share->invoice_id]??0)+(int)$share->insurer_kobo;
        }
        foreach ($claims as $claim) {
            $latest=null;
            foreach ($decisions->where('claim_id',$claim->id) as $decision) {
                $review=$decisionReviews->get($decision->id);
                if ($review?->outcome==='accepted' && ($latest===null || $review->id>$latest['review']->id)) {
                    $latest=['decision'=>$decision,'review'=>$review];
                }
            }
            if ($latest!==null) {
                $reviewedLiability+=$invoiceData[$claim->invoice_id]??0;
                foreach ($decisionLines->where('decision_id',$latest['decision']->id) as $line) { $items[$line->item_id]['approved']=(int)$line->approved_kobo; }
            }
            $latestSubmission=$submissions->where('claim_id',$claim->id)->sortByDesc('sequence')->first();
            $pending=$decisions->where('claim_id',$claim->id)->filter(fn (\stdClass $decision): bool=>!$decisionReviews->has($decision->id))->values();
            $claimData[$claim->id]=['submission'=>$latestSubmission,'latest_decision'=>$latest['decision']??null,'pending_decisions'=>$pending,
                'liability'=>$invoiceData[$claim->invoice_id]??0,'approved'=>0,'settled'=>0,'reserved'=>0,'disputed'=>0,'approved_unpaid'=>0];
        }
        foreach ($allocationLines as $line) {
            $review=$allocationReviews->get($line->allocation_id);
            if ($review?->outcome==='rejected') { continue; }
            $items[$line->item_id][$review?->outcome==='accepted' ? 'settled' : 'reserved']+=(int)$line->amount_kobo;
        }
        foreach ($claims as $claim) {
            foreach ($shares->where('invoice_id',$claim->invoice_id) as $share) {
                $state=$items[$share->item_id];
                foreach (['approved','settled','reserved'] as $field) { $claimData[$claim->id][$field]+=$state[$field]; }
            }
            $claimData[$claim->id]['disputed']=$claimData[$claim->id]['latest_decision']===null?0:$claimData[$claim->id]['liability']-$claimData[$claim->id]['approved'];
            $claimData[$claim->id]['approved_unpaid']=$claimData[$claim->id]['approved']-$claimData[$claim->id]['settled'];
        }
        $totals=['insurer_liability'=>array_sum(array_column($items,'insurer')),'insurer_approved'=>array_sum(array_column($items,'approved')),
            'insurer_settled'=>array_sum(array_column($items,'settled')),'insurer_reserved'=>array_sum(array_column($items,'reserved'))];
        $totals['insurer_unreviewed']=$totals['insurer_liability']-$reviewedLiability;
        $totals['insurer_disputed']=$reviewedLiability-$totals['insurer_approved'];
        $totals['insurer_outstanding']=$totals['insurer_liability']-$totals['insurer_settled'];
        foreach ($totals as $amount) {
            if (!is_int($amount) || $amount<0 || $amount>BillingMoney::MAX) {
                throw new WriteConflict('hmo_ledger_conflict','The payer ledger requires reconciliation.');
            }
        }

        return compact('shares','claims','submissions','decisions','decisionReviews','decisionLines','allocations','allocationReviews','allocationLines','items','invoiceData','claimData','totals');
    }

    public function remittance(int $id,bool $lock=true): array
    {
        $record=DB::table('hmo_remittances')->where('id',$id)->when($lock,fn (Builder $q): Builder=>$q->lockForUpdate())->first();
        abort_if($record===null,404);
        $review=DB::table('hmo_reviews')->where('kind','remittance')->where('record_id',$id)->when($lock,fn (Builder $q): Builder=>$q->lockForUpdate())->first();
        $allocations=$this->rows(DB::table('hmo_allocations')->where('remittance_id',$id),$lock);
        $reviews=$this->rows(DB::table('hmo_reviews')->where('kind','allocation')->whereIn('record_id',$allocations->keys()),$lock)->keyBy('record_id');
        $settled=$reserved=0;
        foreach ($allocations as $allocation) {
            $outcome=$reviews->get($allocation->id)?->outcome;
            if ($outcome==='accepted') { $settled+=(int)$allocation->amount_kobo; }
            elseif ($outcome===null) { $reserved+=(int)$allocation->amount_kobo; }
        }

        return ['record'=>$record,'review'=>$review,'version'=>1+($review===null?0:1)+$allocations->count()+$reviews->count(),
            'settled'=>$settled,'reserved'=>$reserved,'available'=>($review?->outcome==='accepted'?(int)$record->amount_kobo:0)-$settled-$reserved];
    }
}
