<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ReportRequest;
use App\Models\User;
use App\Services\FinancialReports;
use App\Services\InventoryReports;
use App\Services\OperationalReports;
use App\Services\ReportAccess;
use App\Services\ReportPeriod;
use App\Services\ReportSnapshots;
use Illuminate\Http\JsonResponse;

class ReportController extends Controller
{
    public function __construct(private ReportSnapshots $snapshots,private OperationalReports $operations,
        private InventoryReports $inventory,private FinancialReports $finance) {}

    public function show(ReportRequest $request): JsonResponse
    {
        $kind=$request->route()->getName()==='api.v1.dashboard'?'dashboard':$request->route('kind');
        $response=$this->snapshots->run($request->user('web'),$kind,$request->validated(),function (User $actor,ReportPeriod $period,array $available) use ($kind): array {
            return match ($kind) {
                'options'=>['available_modules'=>$available,'timezone'=>'Africa/Lagos','default_period'=>'today',
                    'maximum_calendar_days'=>366,'currency'=>'NGN','balances_are_current'=>true,'exports_enabled'=>$actor->hasPermission('exports.generate')],
                'activity'=>$this->operations->summary($actor,$period),
                'trend'=>$this->operations->trend($actor,$period,$period->filters['module']),
                'inventory'=>$this->inventory->summary($actor,$period),
                'positions'=>$this->inventory->positions($actor,$period),
                'movements'=>$this->inventory->movements($actor,$period),
                'billing'=>$this->finance->billing($actor,$period),
                'hmo'=>$this->finance->hmo($actor,$period),
                'accounts'=>$this->finance->accountsPage($actor,$period,in_array('hmo',$available,true)),
                'dashboard'=>$this->dashboard($actor,$period,$available),
            };
        });
        return response()->json($response);
    }

    private function dashboard(User $actor,ReportPeriod $period,array $available): array
    {
        $sections=[];
        if ($actor->hasPermission('reports.activity') && array_intersect($available,ReportAccess::ACTIVITY)!==[]) {
            $sections['activity']=$this->operations->summary($actor,$period);
        }
        if (in_array('inventory',$available,true)) { $sections['inventory']=$this->inventory->summary($actor,$period); }
        if (in_array('billing',$available,true)) { $sections['billing']=$this->finance->billing($actor,$period); }
        if (in_array('hmo',$available,true)) { $sections['hmo']=$this->finance->hmo($actor,$period); }
        return ['available_modules'=>$available,'sections'=>(object)$sections];
    }
}
