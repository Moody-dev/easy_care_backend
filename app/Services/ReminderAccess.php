<?php

namespace App\Services;

use App\Models\Encounter;
use App\Models\Patient;
use App\Models\Reminder;
use App\Models\StaffNotification;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class ReminderAccess
{
    public function roots(User $actor): Builder
    {
        return $this->scopeContext(Reminder::query()->where('owner_id', $actor->id), $actor, 'staff_reminders.');
    }

    public function notifications(User $actor, bool $current = true): Builder
    {
        $query=StaffNotification::query()->join('reminder_deliveries as d','d.id','=','staff_notifications.delivery_id')
            ->join('reminder_occurrences as o','o.id','=','d.occurrence_id')->join('staff_reminders as r','r.id','=','o.reminder_id')
            ->join('reminder_revisions as v','v.id','=','o.revision_id')->where('staff_notifications.recipient_id',$actor->id)
            ->select('staff_notifications.*');
        if ($current) { $query->where('r.is_active',true)->whereColumn('v.version','r.version'); }
        return $this->scopeContext($query,$actor,'r.');
    }

    private function scopeContext(Builder $query, User $actor, string $prefix): Builder
    {
        return $query->where(fn (Builder $q): Builder => $q->whereNull($prefix.'patient_id')
            ->orWhereIn($prefix.'patient_id',Patient::query()->visibleTo($actor)->select('patients.id')))
            ->where(fn (Builder $q): Builder => $q->whereNull($prefix.'encounter_id')
                ->orWhereIn($prefix.'encounter_id',Encounter::query()->visibleTo($actor)->select('encounters.id')));
    }

    public function context(User $actor, ?int $patientId, ?int $encounterId): ?string
    {
        if ($patientId!==null && (!$this->permission($actor,'patients.view')
            || Patient::query()->where('access_status','active')->whereKey($patientId)->lockForUpdate()->first()===null)) {
            return 'patient_hidden';
        }
        if ($encounterId!==null) {
            if (!$this->permission($actor,'encounters.view') || Encounter::query()->whereKey($encounterId)->where('patient_id',$patientId)->lockForUpdate()->first()===null) {
                return 'encounter_hidden';
            }
            if (!$this->permission($actor,'encounters.view_any')) {
                $assignment=DB::table('doctor_assignments')->where('encounter_id',$encounterId)->where('doctor_id',$actor->id)
                    ->where(fn (\Illuminate\Database\Query\Builder $q): \Illuminate\Database\Query\Builder=>$q->whereNull('ended_at')->orWhere('status','completed'))
                    ->lockForUpdate()->first();
                if ($assignment===null) { return 'encounter_hidden'; }
            }
        }
        return null;
    }

    public function permission(User $actor, string $code): bool
    {
        return $actor->is_active && array_key_exists($code,config('authorization.permissions'))
            && DB::table('user_roles as ur')->join('role_permissions as rp','rp.role_id','=','ur.role_id')
            ->join('permissions as p','p.id','=','rp.permission_id')->where('ur.user_id',$actor->id)->where('p.code',$code)
            ->select('p.id')->lockForUpdate()->first()!==null;
    }
}
