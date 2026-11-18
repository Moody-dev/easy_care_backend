<?php

namespace App\Http\Resources;

use App\Models\Reminder;
use App\Models\ReminderDelivery;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StaffNotificationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $delivery=ReminderDelivery::findOrFail($this->delivery_id);
        $occurrence=$delivery->occurrence;
        $revision=$occurrence->revision;
        $reminder=Reminder::findOrFail($occurrence->reminder_id);
        $content=$revision->content;
        return ['id'=>$this->id,'recipient_id'=>$this->recipient_id,'version'=>$this->version,'reminder_id'=>$reminder->id,
            'revision_id'=>$revision->id,'occurrence_id'=>$occurrence->id,'sequence'=>$occurrence->sequence,
            'patient_id'=>$reminder->patient_id,'encounter_id'=>$reminder->encounter_id,'title'=>$content['title'],'body'=>$content['body'],
            'due_at'=>$occurrence->due_at->toISOString(),'available_at'=>$this->available_at->toISOString(),'read_at'=>$this->read_at?->toISOString(),
            'is_read'=>$this->read_at!==null,'is_snoozed'=>$this->available_at->isFuture(),'is_current'=>$reminder->is_active&&$revision->version===$reminder->version,
            'created_at'=>$this->created_at->toISOString(),'updated_at'=>$this->updated_at->toISOString(),'delivery_channel'=>'in_app'];
    }
}
