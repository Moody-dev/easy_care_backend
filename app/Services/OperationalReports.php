<?php

namespace App\Services;

use App\Models\Admission;
use App\Models\Appointment;
use App\Models\CareProgram;
use App\Models\ClinicalRecord;
use App\Models\Encounter;
use App\Models\InvestigationItem;
use App\Models\InvestigationResult;
use App\Models\MedicationAuthorization;
use App\Models\Patient;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

class OperationalReports
{
    public const DEFINITIONS=[
        'registry'=>['created_at','access_status','patient registrations'],
        'appointments'=>['starts_at','status','bookings by scheduled start'],
        'encounters'=>['opened_at','status','visits by arrival'],
        'admissions'=>['admitted_at','status','admission episodes'],
        'clinical'=>['recorded_at','status','clinical record versions'],
        'prescriptions'=>['recorded_at','status','prescription record versions'],
        'care'=>['started_at','status','care program episodes'],
        'diagnostics'=>['q.requested_at','status','requested investigation items'],
        'results'=>['created_at','status','result versions'],
        'medications'=>['recorded_at',null,'catalog prescription authorizations'],
        'staff'=>['created_at','is_active','visible staff registrations'],
        'reminders'=>['created_at','is_active','owned reminder schedules'],
        'notifications'=>['created_at',null,'own delivered notifications'],
    ];

    public function __construct(private ReportAccess $access, private ReminderAccess $reminders) {}

    public function modules(User $actor, array $filters): array
    {
        return array_values(array_filter(ReportAccess::ACTIVITY,function (string $module) use ($actor,$filters): bool {
            if (!$this->access->allowed($actor,$module)) { return false; }
            if (isset($filters['patient_id']) && $module==='staff') { return false; }
            if (isset($filters['encounter_id']) && in_array($module,['staff','registry'],true)) { return false; }
            if (isset($filters['doctor_id']) && in_array($module,['staff','registry','reminders','notifications'],true)) { return false; }
            return true;
        }));
    }

    public function base(User $actor, string $module, array $filters): Builder
    {
        $query=match ($module) {
            'registry'=>Patient::query()->visibleTo($actor),
            'appointments'=>Appointment::query()->visibleTo($actor),
            'encounters'=>Encounter::query()->visibleTo($actor),
            'admissions'=>Admission::query()->visibleTo($actor),
            'clinical'=>ClinicalRecord::query()->visibleTo($actor),
            'prescriptions'=>ClinicalRecord::query()->prescriptionsVisibleTo($actor),
            'care'=>CareProgram::query()->visibleTo($actor),
            'diagnostics'=>InvestigationItem::query()->visibleTo($actor)->join('investigation_orders as q','q.id','=','investigation_items.order_id'),
            'results'=>InvestigationResult::query()->visibleTo($actor),
            'medications'=>MedicationAuthorization::query()->visibleTo($actor),
            'staff'=>User::query()->visibleTo($actor),
            'reminders'=>$this->reminders->roots($actor),
            'notifications'=>$this->reminders->notifications($actor,false),
        };
        if (isset($filters['patient_id'])) {
            if ($module==='registry') { $query->whereKey($filters['patient_id']); }
            elseif ($module==='notifications') { $query->where('r.patient_id',$filters['patient_id']); }
            elseif ($module==='medications') {
                $query->whereHas('item.prescription.record',fn (Builder $q): Builder=>$q->where('patient_id',$filters['patient_id']));
            } else { $query->where($query->getModel()->qualifyColumn('patient_id'),$filters['patient_id']); }
        }
        if (isset($filters['encounter_id']) || isset($filters['doctor_id'])) {
            $visits=Encounter::query()->visibleTo($actor);
            if (isset($filters['encounter_id'])) { $visits->whereKey($filters['encounter_id']); }
            if (isset($filters['doctor_id'])) {
                $visits->whereHas('assignments',fn (Builder $q): Builder=>$q->where('doctor_id',$filters['doctor_id'])
                    ->where(fn (Builder $s): Builder=>$s->whereNull('ended_at')->orWhere('status','completed')));
            }
            if ($module==='encounters') { $query->whereIn('encounters.id',$visits->select('encounters.id')); }
            elseif ($module==='appointments') {
                if (isset($filters['doctor_id'])) { $query->where('appointments.doctor_id',$filters['doctor_id']); }
                if (isset($filters['encounter_id'])) {
                    $query->whereIn('appointments.id',Encounter::query()->visibleTo($actor)->whereKey($filters['encounter_id'])->select('appointment_id'));
                }
            } elseif ($module==='medications') {
                $query->whereHas('item.prescription.record',fn (Builder $q): Builder=>$q->whereIn('encounter_id',$visits->select('encounters.id')));
            } else {
                $column=$module==='notifications'?'r.encounter_id':$query->getModel()->qualifyColumn('encounter_id');
                $query->whereIn($column,$visits->select('encounters.id'));
            }
        }
        return $query;
    }

    public function column(Builder $query, string $module): string
    {
        $column=self::DEFINITIONS[$module][0];
        return str_contains($column,'.')?$column:$query->getModel()->qualifyColumn($column);
    }

    public function summary(User $actor, ReportPeriod $period): array
    {
        $out=[];
        foreach ($this->modules($actor,$period->filters) as $module) {
            $current=$this->base($actor,$module,$period->filters);
            $query=clone $current;
            $period->apply($query,$this->column($query,$module));
            $status=self::DEFINITIONS[$module][1];
            $data=['count'=>(clone $query)->count(),'unit'=>self::DEFINITIONS[$module][2],
                'period_field'=>self::DEFINITIONS[$module][0],'states'=>'current state of the selected cohort'];
            if ($status!==null) {
                $column=$query->getModel()->qualifyColumn($status);
                $data['by_status']=(object)(clone $query)->reorder()->select($column)->selectRaw('COUNT(*) AS aggregate')
                    ->groupBy($column)->get()->mapWithKeys(fn (\Illuminate\Database\Eloquent\Model $row): array=>[(string)$row->getRawOriginal($status)=>(int)$row->aggregate])->all();
            }
            $pending=match ($module) {
                'appointments'=>['booked','confirmed'],'encounters'=>['open','in_progress'],'admissions'=>['admitted','discharge_authorized'],
                'clinical','prescriptions'=>['draft'],'care'=>['active'],'diagnostics'=>['requested','collected','processing'],
                'results'=>['draft','submitted','verified'],default=>null,
            };
            if ($pending!==null) {
                $data['current']=['pending_or_active_count'=>$current->whereIn($current->getModel()->qualifyColumn($status),$pending)->count(),
                    'included_statuses'=>$pending,'basis'=>'current visible records across all dates'];
            }
            if ($module==='reminders') {
                $data['current']=['scheduled_count'=>(clone $current)->where('is_active',true)->whereNotNull('next_due_at')->count(),
                    'due_count'=>(clone $current)->where('is_active',true)->where('next_due_at','<=',$period->asOf->format('Y-m-d H:i:s.u'))->count(),
                    'basis'=>'current owned schedules across all dates'];
            }
            if (in_array($module,['registry','staff'],true)) {
                $data['current']=['visible_count'=>(clone $current)->count(),'basis'=>'current record visibility across all dates'];
                if ($module==='staff') { $data['current']['active_count']=(clone $current)->where('is_active',true)->count(); }
            }
            if ($module==='notifications') {
                $data['current']=['ready_unread_count'=>$current->where('r.is_active',true)->whereColumn('v.version','r.version')
                    ->whereNull('staff_notifications.read_at')->where('staff_notifications.available_at','<=',$period->asOf->format('Y-m-d H:i:s.u'))->count(),
                    'basis'=>'current accessible inbox across all dates'];
            }
            $out[$module]=$data;
        }
        return $out;
    }

    public function trend(User $actor, ReportPeriod $period, string $module): array
    {
        abort_unless(in_array($module,$this->modules($actor,$period->filters),true),403);
        $buckets=[];
        for ($day=$period->start->setTimezone('Africa/Lagos');$day->lessThan($period->end);$day=$day->addDay()) { $buckets[$day->toDateString()]=0; }
        $query=$this->base($actor,$module,$period->filters);
        $column=$this->column($query,$module);
        $period->apply($query,$column);
        $id=$query->getModel()->qualifyColumn('id');
        $query->reorder()->selectRaw($id.' AS report_id, '.$column.' AS report_time')->chunkById(200,function (\Illuminate\Support\Collection $rows) use (&$buckets): void {
            foreach ($rows as $row) {
                $day=CarbonImmutable::parse($row->report_time,'UTC')->setTimezone('Africa/Lagos')->toDateString();
                $buckets[$day]++;
            }
        },$id,'report_id');
        return ['module'=>$module,'unit'=>self::DEFINITIONS[$module][2],'period_field'=>self::DEFINITIONS[$module][0],
            'series'=>array_map(fn (string $day,int $count): array=>['date'=>$day,'count'=>$count],array_keys($buckets),array_values($buckets))];
    }
}
