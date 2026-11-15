<?php

namespace App\Http\Requests\Api\V1;

use App\Models\Admission;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListAdmissionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $id = ['sometimes', 'integer', 'min:1', 'max:999999999999999999'];

        return ['status' => ['sometimes', Rule::in(array_keys(Admission::TRANSITIONS))],
            'patient_id' => $id, 'encounter_id' => $id, 'ward_id' => $id,
            'page' => ['sometimes', 'integer', 'min:1', 'max:1000000'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.config('api.pagination.max_per_page')]];
    }
}
