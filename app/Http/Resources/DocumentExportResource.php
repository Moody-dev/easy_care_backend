<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DocumentExportResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return ['id'=>$this->id, 'kind'=>$this->kind, 'format'=>$this->format, 'version'=>1,
            'byte_size'=>$this->byte_size, 'sha256'=>$this->sha256,
            'created_at'=>$this->created_at->toISOString(), 'expires_at'=>$this->expires_at->toISOString()];
    }
}
