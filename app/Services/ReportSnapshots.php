<?php

namespace App\Services;

use App\Models\Encounter;
use App\Models\Patient;
use App\Models\Permission;
use App\Models\User;
use Closure;
use Illuminate\Support\Facades\DB;

class ReportSnapshots
{
    public function __construct(private WriteTransactions $transactions,private StockAuthority $authority,private ReportAccess $access,private AuditTrail $audit) {}

    public static function reportPermission(string $kind): string
    {
        return match ($kind) {
            'inventory','positions','movements'=>'reports.inventory','billing','accounts'=>'reports.billing','hmo'=>'reports.hmo',default=>'reports.activity',
        };
    }

    public function available(User $actor): array
    {
        return array_values(array_filter($this->access->available($actor),fn (string $module): bool=>$actor->hasPermission(self::reportPermission($module))));
    }

    public function run(User $requestActor,string $kind,array $filters,Closure $operation): array
    {
        return $this->transactions->run(function () use ($requestActor,$kind,$filters,$operation): array {
            foreach (['staff.manage','patients.create'] as $code) {
                abort_if(Permission::query()->where('code',$code)->lockForUpdate()->first()===null,503);
            }
            $actor=User::query()->lockForUpdate()->findOrFail($requestActor->id);
            abort_unless($actor->is_active,403);
            if ($kind==='dashboard') { $this->authority->permission($actor,'dashboard.view'); }
            elseif ($kind==='options') { abort_if($this->available($actor)===[],403); }
            else { $this->authority->permission($actor,self::reportPermission($kind)); }
            $available=$this->available($actor);
            if (in_array($kind,['inventory','positions','movements'],true)) { abort_unless(in_array('inventory',$available,true),403); }
            if (in_array($kind,['billing','accounts','hmo'],true)) {
                abort_unless(in_array($kind==='hmo'?'hmo':'billing',$available,true),403);
            }
            if (in_array($kind,['activity','trend'],true)) {
                abort_if(array_intersect($available,ReportAccess::ACTIVITY)===[],403);
            }
            if (isset($filters['patient_id'])) { Patient::query()->visibleTo($actor)->lockForUpdate()->findOrFail($filters['patient_id']); }
            if (isset($filters['encounter_id'])) {
                Encounter::query()->visibleTo($actor)->when(isset($filters['patient_id']),fn (\Illuminate\Database\Eloquent\Builder $q): \Illuminate\Database\Eloquent\Builder=>$q->where('patient_id',$filters['patient_id']))
                    ->lockForUpdate()->findOrFail($filters['encounter_id']);
            }
            if (isset($filters['store_id'])) { $this->access->stores($actor)->where('store_id',$filters['store_id'])->lockForUpdate()->firstOrFail(); }
            $period=new ReportPeriod($filters); $now=$period->asOf;
            $data=$operation($actor,$period,$available);
            $this->audit->record($kind==='dashboard'?'dashboard.viewed':'report.viewed',$actor->id,'business_report',summary:['report_key'=>$kind]);
            return ['data'=>$data,'meta'=>['generated_at'=>$now->toISOString(),'period'=>$period->metadata(),'filters'=>$filters,
                'scope'=>'current staff permissions and record visibility','stored_totals'=>false,'snapshot'=>'one fenced database transaction']];
        });
    }
}
