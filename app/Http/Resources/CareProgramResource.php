<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CareProgramResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return $this->resource->only(['id','patient_id','encounter_id','type','status','version','started_by','start_reason',
            'completed_by','completed_encounter_id','completion_reason']) + ['started_at' => $this->started_at->toISOString(),
                'completed_at' => $this->completed_at?->toISOString()];
    }
}
