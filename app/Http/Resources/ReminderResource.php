<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ReminderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $revision=$this->resource->revisions()->where('version',$this->version)->firstOrFail();
        $content=$revision->content;
        return ['id'=>$this->id,'owner_id'=>$this->owner_id,'patient_id'=>$this->patient_id,'encounter_id'=>$this->encounter_id,
            'version'=>$this->version,'is_active'=>$this->is_active,'title'=>$content['title'],'body'=>$content['body'],
            'starts_at'=>$revision->starts_at->toISOString(),'timezone'=>$revision->timezone,'frequency'=>$revision->frequency,
            'interval'=>$revision->interval,'occurrence_count'=>$revision->occurrence_count,'ends_at'=>$revision->ends_at?->toISOString(),
            'recipient_ids'=>$revision->recipients()->orderBy('users.id')->pluck('users.id')->all(),
            'next_due_at'=>$this->next_due_at?->toISOString(),'next_sequence'=>$this->next_sequence,'created_at'=>$this->created_at->toISOString(),
            'updated_at'=>$this->updated_at->toISOString(),'delivery_channel'=>'in_app','delivery_pending'=>$this->is_active&&$this->next_due_at!==null];
    }
}
