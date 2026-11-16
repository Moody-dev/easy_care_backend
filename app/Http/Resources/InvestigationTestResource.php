<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InvestigationTestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $definition = $this->latestSchema->schema_json;

        return $this->resource->only(['id', 'code', 'department', 'service_id']) + [
            'name' => $definition['name'], 'specimen' => $definition['specimen'], 'is_active' => $definition['is_active'],
            'version' => $this->latestSchema->version,
            'service' => $this->service->only(['id', 'code', 'name', 'category', 'is_active']),
            'latest_schema' => ['id' => $this->latestSchema->id, 'test_id' => $this->id, 'version' => $this->latestSchema->version,
                'title' => $this->latestSchema->schema_json['title'] ?? null, 'published_at' => $this->latestSchema->published_at->toISOString()],
        ];
    }
}
