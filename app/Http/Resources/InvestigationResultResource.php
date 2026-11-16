<?php

namespace App\Http\Resources;

use App\Services\InvestigationResults;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InvestigationResultResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return InvestigationResults::snapshot($this->resource)
            + ['definition' => $this->schema->schema_json,
                'superseded_by' => $this->replacements()->where('status', 'released')->value('id')];
    }
}
