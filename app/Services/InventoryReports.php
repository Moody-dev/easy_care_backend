<?php

namespace App\Services;

use App\Models\User;
use App\Support\FixedPoint;
use App\Support\ReportAmounts;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class InventoryReports
{
    public function __construct(private ReportAccess $access) {}

    private function filter(Builder $query, User $actor, array $filters, string $prefix): Builder
    {
        $query->whereIn($prefix.'store_id',$this->access->stores($actor));
        foreach (['store_id','drug_id'] as $key) {
            if (isset($filters[$key])) { $query->where($prefix.$key,$filters[$key]); }
        }
        return $query;
    }

    private function positionsQuery(User $actor, array $filters): Builder
    {
        return $this->filter(DB::table('stock_balances as p')->join('drug_batches as b','b.id','=','p.batch_id')
            ->join('drugs as d','d.id','=','p.drug_id')->join('stock_units as u','u.id','=','d.base_unit_id')
            ->join('stock_stores as s','s.id','=','p.store_id'),$actor,$filters,'p.');
    }

    private function movementsQuery(User $actor, ReportPeriod $period): Builder
    {
        return $period->apply($this->filter(DB::table('stock_movements as m'),$actor,$period->filters,'m.'),'m.recorded_at');
    }

    public function summary(User $actor, ReportPeriod $period): array
    {
        $positions=$this->positionsQuery($actor,$period->filters);
        $movements=$this->movementsQuery($actor,$period);
        $today=$period->asOf->setTimezone('Africa/Lagos')->toDateString();
        $data=['current'=>['positions'=>(clone $positions)->count(),'positive_positions'=>(clone $positions)->where('p.quantity_micros','>',0)->count(),
            'zero_positions'=>(clone $positions)->where('p.quantity_micros',0)->count(),
            'expired_or_expiring_today_positive_positions'=>(clone $positions)->where('p.quantity_micros','>',0)->where('b.expires_on','<=',$today)->count()],
            'period'=>['movement_entries'=>(clone $movements)->count(),'period_field'=>'recorded_at',
                'by_kind'=>(object)(clone $movements)->select('m.kind')->selectRaw('COUNT(*) AS aggregate')->groupBy('m.kind')->pluck('aggregate','kind')->map(fn (int|string $value): int=>(int)$value)->all()],
            'quantity_totals_across_drugs'=>null,'valuation_configured'=>false];
        if (isset($period->filters['drug_id'])) {
            $in=$out='0';
            (clone $movements)->select('m.id','m.quantity_micros')->chunkById(200,function (\Illuminate\Support\Collection $rows) use (&$in,&$out): void {
                foreach ($rows as $row) {
                    if ((int)$row->quantity_micros>0) { $in=ReportAmounts::add($in,(string)$row->quantity_micros); }
                    else { $out=ReportAmounts::add($out,(string)abs((int)$row->quantity_micros)); }
                }
            },'m.id','id');
            $data['period']['selected_drug_quantities']=['incoming'=>ReportAmounts::decimal($in,6),'outgoing'=>ReportAmounts::decimal($out,6),
                'net_change'=>ReportAmounts::decimal(ReportAmounts::add($in,'-'.$out),6)];
        }
        return $data;
    }

    public function positions(User $actor, ReportPeriod $period): array
    {
        $page=$this->positionsQuery($actor,$period->filters)->select('p.*','d.code as drug_code','d.name as drug_name','u.code as unit_code',
            's.name as store_name','b.batch_number','b.expires_on')->orderBy('p.store_id')->orderBy('p.drug_id')->orderBy('p.batch_id')
            ->paginate($period->filters['per_page']??25)->appends($period->filters);
        $page->through(fn (\stdClass $row): array=>['store_id'=>(int)$row->store_id,'store_name'=>$row->store_name,'drug_id'=>(int)$row->drug_id,
            'drug_code'=>$row->drug_code,'drug_name'=>$row->drug_name,'base_unit'=>$row->unit_code,'batch_id'=>(int)$row->batch_id,
            'batch_number'=>$row->batch_number,'expires_on'=>$row->expires_on,'quantity'=>FixedPoint::format((int)$row->quantity_micros),
            'movement_count'=>(int)$row->movement_count,'last_changed_at'=>CarbonImmutable::parse($row->last_changed_at,'UTC')->toISOString()]);
        return $page->toArray()+['basis'=>'current lifetime ledger balances; date range applies only to movement reports'];
    }

    public function movements(User $actor, ReportPeriod $period): array
    {
        $page=$this->movementsQuery($actor,$period)->select('m.id','m.store_id','m.drug_id','m.batch_id','m.kind','m.quantity_micros','m.recorded_at')
            ->orderByDesc('m.id')->paginate($period->filters['per_page']??25)->appends($period->filters);
        $page->through(fn (\stdClass $row): array=>['id'=>(int)$row->id,'store_id'=>(int)$row->store_id,'drug_id'=>(int)$row->drug_id,'batch_id'=>(int)$row->batch_id,
            'kind'=>$row->kind,'quantity'=>FixedPoint::format((int)$row->quantity_micros),'recorded_at'=>CarbonImmutable::parse($row->recorded_at,'UTC')->toISOString()]);
        return $page->toArray();
    }
}
