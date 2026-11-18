<?php

namespace App\Services;

use App\Models\Patient;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ExportReports
{
    public const KINDS=['activity','trend','inventory','positions','movements','billing','accounts','hmo'];
    public const MAX_RECORDS=5000;

    public function __construct(private ReportSnapshots $snapshots,private OperationalReports $operations,
        private InventoryReports $inventory,private FinancialReports $finance,private ReportAccess $access,private ExportRendering $rendering) {}

    public function snapshot(User $actor,array $input): array
    {
        $kind=$input['report_key']; $filters=$input['filters'];
        $snapshot=$this->snapshots->run($actor,$kind,$filters,function (User $viewer,ReportPeriod $period,array $available) use ($kind): array {
            $data=match ($kind) {
                'activity'=>$this->operations->summary($viewer,$period),
                'trend'=>$this->operations->trend($viewer,$period,$period->filters['module']),
                'inventory'=>$this->inventory->summary($viewer,$period),
                'positions','movements','accounts'=>$this->pages($viewer,$period,$kind,in_array('hmo',$available,true)),
                'billing'=>$this->finance->billing($viewer,$period),
                'hmo'=>$this->finance->hmo($viewer,$period),
            };
            $versions=[];
            if (in_array($kind,['activity','trend'],true)) {
                $modules=$kind==='trend'?[$period->filters['module']]:$this->operations->modules($viewer,$period->filters);
                foreach ($modules as $module) {
                    abort_unless(in_array($module,$this->operations->modules($viewer,$period->filters),true),403);
                    $query=$this->operations->base($viewer,$module,$period->filters); $model=$query->getModel();
                    $columns=[$model->qualifyColumn('id')];
                    if (Schema::hasColumn($model->getTable(),'version')) { $columns[]=$model->qualifyColumn('version'); }
                    $rows=$query->reorder()->select($columns)->orderBy($model->qualifyColumn('id'))->limit(self::MAX_RECORDS+1)->get()->toArray();
                    if (count($rows)>self::MAX_RECORDS) { $this->rendering->tooLarge(); }
                    $versions[$module]=$rows;
                }
            } elseif (in_array($kind,['billing','accounts','hmo'],true)) {
                $accounts=$this->access->accounts($viewer,$period->filters)->orderBy('id')->limit(self::MAX_RECORDS+1)->get();
                if ($accounts->count()>self::MAX_RECORDS) { $this->rendering->tooLarge(); }
                foreach ($accounts as $account) {
                    $versions[]=['account'=>$account->id,'patient_version'=>Patient::query()->whereKey($account->patient_id)->value('version'),
                        'ledger_version'=>DB::table('billing_events')->where('account_id',$account->id)->max('version')];
                }
            } else {
                $versions=$this->pages($viewer,$period,'positions',false);
            }
            return ['report_key'=>$kind,'filters'=>$period->filters,'period'=>$period->metadata(),'data'=>$data,'source_versions'=>$versions];
        });
        $state=$snapshot['data'];
        $fingerprint=hash('sha256',json_encode($state,JSON_THROW_ON_ERROR));
        unset($state['source_versions']);
        return ['data'=>$state,'fingerprint'=>$fingerprint];
    }

    private function pages(User $actor,ReportPeriod $period,string $kind,bool $includeHmo): array
    {
        $records=[]; $page=1;
        do {
            $paged=new ReportPeriod($period->filters+['page'=>$page,'per_page'=>100]);
            $result=match ($kind) {
                'positions'=>$this->inventory->positions($actor,$paged),
                'movements'=>$this->inventory->movements($actor,$paged),
                'accounts'=>$this->finance->accountsPage($actor,$paged,$includeHmo),
            };
            if ($result['total']>self::MAX_RECORDS) { $this->rendering->tooLarge(); }
            array_push($records,...$result['data']); $page++;
        } while ($page<=$result['last_page']);
        return ['total'=>count($records),'records'=>$records]+array_intersect_key($result,array_flip(['basis','currency']));
    }
}
