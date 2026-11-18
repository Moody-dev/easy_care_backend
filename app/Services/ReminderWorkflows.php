<?php

namespace App\Services;

use App\Exceptions\WriteConflict;
use App\Http\Resources\ReminderResource;
use App\Http\Resources\StaffNotificationResource;
use App\Models\Reminder;
use App\Models\ReminderDelivery;
use App\Models\ReminderRevision;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use stdClass;

class ReminderWorkflows
{
    public function __construct(private ReminderAccess $access, private ReminderSchedule $schedule, private StockAuthority $authority, private AuditTrail $audit) {}

    public static function action(Request $request): string
    {
        return substr($request->route()->getName(),strrpos($request->route()->getName(),'.')+1);
    }

    public static function isCommand(string $route): bool
    {
        return in_array($route,['api.v1.reminders.created','api.v1.reminders.updated','api.v1.reminders.cancelled',
            'api.v1.notifications.read','api.v1.notifications.unread','api.v1.notifications.snoozed'],true);
    }

    public static function isNotification(Request $request): bool
    {
        return str_starts_with($request->route()->getName(),'api.v1.notifications.');
    }

    public function authorize(Request $request, User $actor): void
    {
        $request->attributes->set('reminder_actor',$actor);
        if (self::isNotification($request)) {
            $this->authority->permission($actor,'notifications.view');
            $this->authority->permission($actor,'notifications.update');
            $notification=$this->access->notifications($actor,false)->where('staff_notifications.id',$request->route('notification'))->lockForUpdate()->firstOrFail();
            $request->attributes->set('reminder_notification',$notification);
            $root=ReminderDelivery::findOrFail($notification->delivery_id)->occurrence->reminder;
            abort_if($this->access->context($actor,$root->patient_id,$root->encounter_id)!==null,404);
        } else {
            $this->authority->permission($actor,'reminders.view');
            $this->authority->permission($actor,'reminders.manage');
            if (self::action($request)!=='created') {
                $reminder=$this->access->roots($actor)->whereKey($request->route('reminder'))->lockForUpdate()->firstOrFail();
                $request->attributes->set('staff_reminder',$reminder);
                abort_if($this->access->context($actor,$reminder->patient_id,$reminder->encounter_id)!==null,404);
            }
        }
    }

    public function execute(Request $request, User $actor, array $data): JsonResponse
    {
        $actor=$request->attributes->get('reminder_actor');
        $action=self::action($request);
        $now=CarbonImmutable::now('UTC');
        if (self::isNotification($request)) {
            $notification=$request->attributes->get('reminder_notification');
            $this->authority->version($notification->version,$data['version']);
            $this->clock($now,$notification->updated_at);
            $occurrence=ReminderDelivery::findOrFail($notification->delivery_id)->occurrence;
            $root=Reminder::query()->lockForUpdate()->findOrFail($occurrence->reminder_id);
            if ($action!=='read' && (!$root->is_active || $root->version!==$occurrence->revision->version)) {
                throw new WriteConflict('notification_superseded','The reminder was cancelled or replaced. Its earlier notifications remain in history.');
            }
            if ($action==='snoozed') {
                $until=CarbonImmutable::parse($data['snooze_until'])->utc();
                if (!$until->greaterThan($now)) { $this->invalid('snooze_until','Select a future time.'); }
                $notification->available_at=$until;
                $notification->read_at=null;
            } else {
                $notification->read_at=$action==='read'?$now:null;
            }
            $notification->forceFill(['version'=>$notification->version+1,'updated_at'=>$now])->save();
            $this->event($root->id,$occurrence->revision_id,$action,$notification->version,$now,$actor->id,$notification->id);
            $this->audit->record('notification.'.$action,$actor->id,'notification',$notification->id);
            return (new StaffNotificationResource($notification))->response();
        }
        $root=$request->attributes->get('staff_reminder');
        if ($action==='created') {
            $patient=isset($data['patient_id'])?(int)$data['patient_id']:null;
            $visit=isset($data['encounter_id'])?(int)$data['encounter_id']:null;
            abort_if($this->access->context($actor,$patient,$visit)!==null,404);
        } else {
            $this->authority->version($root->version,$data['version']);
            $this->clock($now,$root->updated_at);
            if (!$root->is_active) { throw new WriteConflict('reminder_cancelled','This retained reminder is cancelled. Create a new schedule if needed.'); }
            $patient=$root->patient_id; $visit=$root->encounter_id;
        }
        if ($action==='cancelled') {
            $old=$root->revisions()->where('version',$root->version)->firstOrFail();
            $values=$old->only(['starts_at','timezone','frequency','interval','occurrence_count','ends_at']);
            $values['content']=$old->content;
            $recipients=$old->recipients()->pluck('users.id')->all();
        } else {
            $recipients=$this->recipients($actor,$data['recipient_ids'],$patient,$visit);
            $values=['content'=>['title'=>trim($data['title']),'body'=>$data['body']??''],
                'starts_at'=>CarbonImmutable::parse($data['starts_at'])->utc(),'timezone'=>$data['timezone'],'frequency'=>$data['frequency'],
                'interval'=>$data['interval'],'occurrence_count'=>$data['occurrence_count'],
                'ends_at'=>isset($data['ends_at'])?CarbonImmutable::parse($data['ends_at'])->utc():null];
        }
        $version=$root===null?1:$root->version+1;
        $revision=new ReminderRevision;
        $revision->forceFill($values+['version'=>$version,'actor_id'=>$actor->id,'recorded_at'=>$now]);
        $this->schedule->validate($revision);
        $first=$this->schedule->due($revision,1);
        if ($root===null) {
            $root=new Reminder;
            $root->forceFill(['owner_id'=>$actor->id,'patient_id'=>$patient,'encounter_id'=>$visit,'version'=>1,'is_active'=>true,
                'next_sequence'=>1,'next_due_at'=>$first,'created_at'=>$now,'updated_at'=>$now])->save();
        }
        $revision->forceFill(['reminder_id'=>$root->id])->save();
        foreach ($recipients as $id) { DB::table('reminder_recipients')->insert(['revision_id'=>$revision->id,'user_id'=>$id]); }
        if ($action!=='created') {
            $root->forceFill(['version'=>$version,'is_active'=>$action!=='cancelled','next_sequence'=>1,
                'next_due_at'=>$action==='cancelled'?null:$first,'updated_at'=>$now])->save();
        }
        $this->event($root->id,$revision->id,$action,$version,$now,$actor->id);
        $this->audit->record('reminder.'.$action,$actor->id,'reminder',$root->id,['version'=>$version]);
        return (new ReminderResource($root))->response()->setStatusCode($action==='created'?201:200);
    }

    private function recipients(User $actor, array $ids, ?int $patient, ?int $visit): array
    {
        $ids=array_map('intval',$ids); sort($ids);
        foreach ($ids as $id) {
            if ($id===$actor->id) { $recipient=$actor; }
            else {
                $this->authority->permission($actor,'reminders.assign');
                $recipient=User::query()->visibleTo($actor)->whereKey($id)->lockForUpdate()->firstOrFail();
                abort_unless($this->access->permission($actor,'staff.view_any')
                    || ($this->access->permission($actor,'staff.view_department') && $actor->department_id!==null && $actor->department_id===$recipient->department_id),404);
            }
            if (!$recipient->is_active || !$this->access->permission($recipient,'notifications.view')
                || $this->access->context($recipient,$patient,$visit)!==null) {
                $this->invalid('recipient_ids','Each selected staff member needs current inbox and linked-record access.');
            }
        }
        return $ids;
    }

    public function replay(Request $request, User $actor, stdClass $record): JsonResponse
    {
        $resource=self::isNotification($request)?$request->attributes->get('reminder_notification')
            :$this->access->roots($actor)->whereKey($record->resource_id)->lockForUpdate()->firstOrFail();
        $this->authority->version($resource->version,$record->resource_version);
        return self::isNotification($request)?(new StaffNotificationResource($resource))->response():(new ReminderResource($resource))->response();
    }

    public function event(int $reminder, int $revision, string $action, int $version, CarbonImmutable $time, ?int $actor=null, ?int $notification=null): void
    {
        DB::table('reminder_events')->insert(['reminder_id'=>$reminder,'revision_id'=>$revision,'notification_id'=>$notification,
            'actor_id'=>$actor,'action'=>$action,'version'=>$version,'recorded_at'=>$time->format('Y-m-d H:i:s.u')]);
    }

    private function clock(CarbonImmutable $now, CarbonImmutable $previous): void
    {
        if ($now->lessThan($previous)) { throw new WriteConflict('reminder_clock_conflict','The server clock precedes the retained reminder state.'); }
    }

    private function invalid(string $field, string $message): never { throw ValidationException::withMessages([$field=>[$message]]); }
}
