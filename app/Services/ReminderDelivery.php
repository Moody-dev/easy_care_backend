<?php

namespace App\Services;

use App\Models\Permission;
use App\Models\Reminder;
use App\Models\ReminderDelivery as Delivery;
use App\Models\ReminderOccurrence;
use App\Models\StaffNotification;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ReminderDelivery
{
    public function __construct(private WriteTransactions $transactions, private ReminderAccess $access,
        private ReminderSchedule $schedule, private ReminderWorkflows $workflows, private AuditTrail $audit) {}

    public function run(int $limit=100): int
    {
        $limit=max(1,min(100,$limit)); $processed=0;
        while ($processed<$limit && $this->one()) { $processed++; }
        return $processed;
    }

    private function one(): bool
    {
        return $this->transactions->run(function (): bool {
            foreach (['staff.manage','patients.create'] as $code) {
                if (Permission::query()->where('code',$code)->lockForUpdate()->first()===null) {
                    throw new RuntimeException('Reminder delivery requires the initialized permission catalog.');
                }
            }
            $now=CarbonImmutable::now('UTC');
            $root=Reminder::query()->where('is_active',true)->where('next_due_at','<=',$now->format('Y-m-d H:i:s.u'))
                ->orderBy('next_due_at')->orderBy('id')->lockForUpdate()->first();
            if ($root===null) { return false; }
            if ($now->lessThan($root->updated_at)) { throw new RuntimeException('Reminder delivery cannot move its recording clock backwards.'); }
            $revision=$root->revisions()->where('version',$root->version)->lockForUpdate()->firstOrFail();
            $due=$this->schedule->due($revision,$root->next_sequence);
            if ($due===null || !$due->equalTo($root->next_due_at)) { throw new RuntimeException('The retained reminder cursor requires reconciliation.'); }
            $occurrence=new ReminderOccurrence;
            $occurrence->forceFill(['reminder_id'=>$root->id,'revision_id'=>$revision->id,'sequence'=>$root->next_sequence,
                'due_at'=>$due,'processed_at'=>$now])->save();
            $author=User::query()->lockForUpdate()->findOrFail($root->owner_id);
            $authorAllowed=$author->is_active && $this->access->permission($author,'reminders.view') && $this->access->permission($author,'reminders.manage')
                && $this->access->context($author,$root->patient_id,$root->encounter_id)===null;
            $delivered=0; $suppressed=0;
            foreach ($revision->recipients()->orderBy('users.id')->lockForUpdate()->get() as $recipient) {
                $code=null;
                if (!$authorAllowed) { $code='author_unavailable'; }
                elseif ($recipient->id!==$author->id && !$this->access->permission($author,'reminders.assign')) { $code='author_assignment_missing'; }
                elseif (!$recipient->is_active) { $code='recipient_inactive'; }
                elseif (!$this->access->permission($recipient,'notifications.view')) { $code='recipient_permission_missing'; }
                else { $code=$this->access->context($recipient,$root->patient_id,$root->encounter_id); }
                $delivery=new Delivery;
                $delivery->forceFill(['occurrence_id'=>$occurrence->id,'recipient_id'=>$recipient->id,'outcome'=>$code===null?'delivered':'suppressed',
                    'suppression_code'=>$code,'recorded_at'=>$now])->save();
                if ($code!==null) { $suppressed++; continue; }
                $notification=new StaffNotification;
                $notification->forceFill(['delivery_id'=>$delivery->id,'recipient_id'=>$recipient->id,'version'=>1,'available_at'=>$now,
                    'read_at'=>null,'created_at'=>$now,'updated_at'=>$now])->save();
                $this->workflows->event($root->id,$revision->id,'delivered',1,$now,notification:$notification->id);
                $delivered++;
            }
            if ($suppressed>0) { $this->workflows->event($root->id,$revision->id,'suppressed',$root->version,$now); }
            $next=$root->next_sequence+1;
            $root->forceFill(['next_sequence'=>$next,'next_due_at'=>$this->schedule->due($revision,$next),'updated_at'=>$now])->save();
            $this->audit->record('reminder.processed',null,'reminder',$root->id,['delivered_count'=>$delivered,'suppressed_count'=>$suppressed],channel:'console');
            return true;
        });
    }
}
