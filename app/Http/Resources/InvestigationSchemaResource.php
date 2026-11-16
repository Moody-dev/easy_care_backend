<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InvestigationSchemaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'test_id' => $this->test_id, 'version' => $this->version,
            'definition' => $this->schema_json, 'content_hash' => $this->content_hash,
            'published_by' => $this->published_by, 'published_at' => $this->published_at->toISOString(),
            'approval_reference' => $this->approval_reference, 'reason' => $this->reason];
    }
}
