<?php

namespace App\Http\Resources;

use App\Models\Patient;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BirthResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $link = $this->patientLink;
        $child = $link === null ? null : Patient::query()->visibleTo($request->user('web'))->find($link->child_patient_id);
        return $this->resource->only(['id','delivery_id','encounter_id','birth_order','recorded_by','reason']) + [
            'version' => $link === null ? 1 : 2, 'recorded_at' => $this->recorded_at->toISOString(),
            'link_exists' => $link !== null, 'child_patient_id' => $child?->id,
            'link' => $child === null ? null : ['encounter_id' => $link->encounter_id, 'verified_by' => $link->verified_by, 'verified_at' => $link->verified_at->toISOString(), 'reason' => $link->reason]];
    }
}
