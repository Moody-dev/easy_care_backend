<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ReminderCommandRequest;
use App\Http\Resources\ReminderResource;
use App\Http\Resources\StaffNotificationResource;
use App\Models\ReminderRevision;
use App\Models\User;
use App\Services\AuditTrail;
use App\Services\IdempotentCommands;
use App\Services\ReminderAccess;
use App\Services\ReminderWorkflows;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class ReminderController extends Controller
{
    public function options(): JsonResponse
    {
        return response()->json(['data'=>['channel'=>'in_app','timezone'=>'Africa/Lagos','frequencies'=>['once','daily','weekly','monthly'],
            'maximum_occurrences'=>366,'maximum_recipients'=>50,'external_messages_enabled'=>false,'automatic_clinical_alerts'=>false,
            'missed_occurrences'=>'oldest_first_bounded_batches','background_delivery_required'=>true]]);
    }

    public function index(Request $request, ReminderAccess $access, AuditTrail $audit): AnonymousResourceCollection
    {
        $data=$request->validate(['page'=>['sometimes','integer','min:1'],'per_page'=>['sometimes','integer','min:1','max:100'],'is_active'=>['sometimes','boolean']]);
        $query=$access->roots($request->user('web'));
        if (isset($data['is_active'])) { $query->where('is_active',$data['is_active']); }
        $audit->record('reminder.viewed',$request->user('web')->id,'reminder');
        return ReminderResource::collection($query->orderByDesc('id')->paginate($data['per_page']??25));
    }

    public function show(Request $request, ReminderAccess $access, AuditTrail $audit): ReminderResource
    {
        $root=$access->roots($request->user('web'))->findOrFail($request->route('reminder'));
        $audit->record('reminder.viewed',$request->user('web')->id,'reminder',$root->id);
        return new ReminderResource($root);
    }

    public function recipients(Request $request): JsonResponse
    {
        $data=$request->validate(['page'=>['sometimes','integer','min:1'],'per_page'=>['sometimes','integer','min:1','max:100']]);
        $users=User::query()->visibleTo($request->user('web'))->where('is_active',true)
            ->whereHas('roles.permissions',fn (Builder $q): Builder=>$q->where('code','notifications.view'))
            ->select('users.id','users.name')->orderBy('users.id')->paginate($data['per_page']??25);
        return response()->json($users);
    }

    public function history(Request $request, ReminderAccess $access, AuditTrail $audit): JsonResponse
    {
        $data=$request->validate(['page'=>['sometimes','integer','min:1'],'per_page'=>['sometimes','integer','min:1','max:100']]);
        $root=$access->roots($request->user('web'))->findOrFail($request->route('reminder'));
        $kind=$request->route('kind');
        if ($kind==='revisions') {
            $query=ReminderRevision::query()->where('reminder_id',$root->id);
            $page=$query->orderByDesc('id')->paginate($data['per_page']??25)->through(function (ReminderRevision $revision): array {
                return $revision->toArray()+['title'=>$revision->content['title'],'body'=>$revision->content['body'],
                    'recipient_ids'=>$revision->recipients()->orderBy('users.id')->pluck('users.id')->all()];
            });
        } else {
            $query=DB::table($kind==='occurrences'?'reminder_occurrences':'reminder_events')->where('reminder_id',$root->id);
            $page=$query->orderByDesc('id')->paginate($data['per_page']??25)->through(function (\stdClass $row) use ($kind): array {
                $record=$this->dates((array)$row);
                if ($kind==='occurrences') {
                    $record['deliveries']=DB::table('reminder_deliveries')->where('occurrence_id',$row->id)->orderBy('id')->get()
                        ->map(fn (\stdClass $delivery): array=>$this->dates((array)$delivery))->all();
                }
                return $record;
            });
        }
        $audit->record('reminder.viewed',$request->user('web')->id,'reminder',$root->id);
        return response()->json($page);
    }

    public function notifications(Request $request, ReminderAccess $access, AuditTrail $audit): AnonymousResourceCollection
    {
        $data=$request->validate(['page'=>['sometimes','integer','min:1'],'per_page'=>['sometimes','integer','min:1','max:100'],
            'state'=>['sometimes',\Illuminate\Validation\Rule::in(['inbox','unread','read','snoozed','all'])],'include_inactive'=>['sometimes','boolean'],
            'reminder_id'=>['sometimes','integer','min:1']]);
        $query=$access->notifications($request->user('web'),!($data['include_inactive']??false));
        if (isset($data['reminder_id'])) { $query->where('r.id',$data['reminder_id']); }
        $state=$data['state']??'inbox';
        if (in_array($state,['inbox','unread','read'],true)) { $query->where('staff_notifications.available_at','<=',now('UTC')->format('Y-m-d H:i:s.u')); }
        if ($state==='unread') { $query->whereNull('staff_notifications.read_at'); }
        if ($state==='read') { $query->whereNotNull('staff_notifications.read_at'); }
        if ($state==='snoozed') { $query->where('staff_notifications.available_at','>',now('UTC')->format('Y-m-d H:i:s.u')); }
        $audit->record('notification.viewed',$request->user('web')->id,'notification');
        return StaffNotificationResource::collection($query->orderBy('o.due_at')->orderBy('staff_notifications.id')->paginate($data['per_page']??25));
    }

    public function notification(Request $request, ReminderAccess $access, AuditTrail $audit): StaffNotificationResource
    {
        $notification=$access->notifications($request->user('web'),false)->where('staff_notifications.id',$request->route('notification'))->firstOrFail();
        $audit->record('notification.viewed',$request->user('web')->id,'notification',$notification->id);
        return new StaffNotificationResource($notification);
    }

    public function notificationHistory(Request $request, ReminderAccess $access, AuditTrail $audit): JsonResponse
    {
        $data=$request->validate(['page'=>['sometimes','integer','min:1'],'per_page'=>['sometimes','integer','min:1','max:100']]);
        $notification=$access->notifications($request->user('web'),false)->where('staff_notifications.id',$request->route('notification'))->firstOrFail();
        $audit->record('notification.viewed',$request->user('web')->id,'notification',$notification->id);
        return response()->json(DB::table('reminder_events')->where('notification_id',$notification->id)->orderBy('id')->paginate($data['per_page']??25)
            ->through(fn (\stdClass $row): array=>$this->dates((array)$row)));
    }

    public function summary(Request $request, ReminderAccess $access): JsonResponse
    {
        $base=$access->notifications($request->user('web'));
        return response()->json(['data'=>['unread_count'=>(clone $base)->whereNull('staff_notifications.read_at')
            ->where('staff_notifications.available_at','<=',now('UTC')->format('Y-m-d H:i:s.u'))->count(),
            'snoozed_count'=>(clone $base)->where('staff_notifications.available_at','>',now('UTC')->format('Y-m-d H:i:s.u'))->count(),
            'delivery_channel'=>'in_app']]);
    }

    public function write(Request $request, ReminderWorkflows $service, IdempotentCommands $commands): JsonResponse
    {
        return $commands->run($request,fn (): JsonResponse=>$service->execute($request,$request->user('web'),app(ReminderCommandRequest::class)->validated()));
    }

    private function dates(array $record): array
    {
        foreach ($record as $key=>$value) {
            if ($value!==null && str_ends_with($key,'_at')) { $record[$key]=CarbonImmutable::parse($value,'UTC')->toISOString(); }
        }
        return $record;
    }
}
