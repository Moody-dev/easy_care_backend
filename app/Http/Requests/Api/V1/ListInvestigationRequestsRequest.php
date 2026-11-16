<?php

namespace App\Http\Requests\Api\V1;

use App\Models\InvestigationItem;
use App\Models\InvestigationTest;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListInvestigationRequestsRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        $id = ['sometimes', 'integer', 'min:1', 'max:999999999999999999'];

        return ['patient_id' => $id, 'encounter_id' => $id, 'ordered_by' => $id, 'order_id' => $id,
            'department' => ['sometimes', Rule::in(InvestigationTest::DEPARTMENTS)],
            'status' => ['sometimes', Rule::in(InvestigationItem::STATUSES)], 'priority' => ['sometimes', Rule::in(['routine', 'urgent'])],
            'active' => ['sometimes', 'boolean'], 'page' => ['sometimes', 'integer', 'min:1', 'max:1000000'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.config('api.pagination.max_per_page')]];
    }
}
