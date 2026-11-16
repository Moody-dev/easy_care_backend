<?php

namespace App\Http\Requests\Api\V1;

use App\Models\InvestigationResult;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListInvestigationResultsRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        $id = ['sometimes', 'integer', 'min:1', 'max:999999999999999999'];

        return ['patient_id' => $id, 'encounter_id' => $id, 'item_id' => $id, 'author_id' => $id,
            'status' => ['sometimes', Rule::in(InvestigationResult::STATUSES)], 'current' => ['sometimes', 'boolean'],
            'page' => ['sometimes', 'integer', 'min:1', 'max:1000000'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.config('api.pagination.max_per_page')]];
    }
}
