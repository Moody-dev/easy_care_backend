<?php

namespace App\Http\Requests\Api\V1;

use App\Models\CareProgram;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListCareProgramsRequest extends FormRequest
{
    public function authorize(): bool { return true; }
    public function rules(): array
    {
        return ['type' => ['sometimes',Rule::in([...CareProgram::TYPES,'birth'])],
            'status' => ['sometimes',Rule::in(['active','completed'])],
            'patient_id' => ['sometimes','integer','min:1','max:999999999999999999'],
            'birth_id' => ['sometimes','integer','min:1','max:999999999999999999'],
            'page' => ['sometimes','integer','min:1','max:1000000'],
            'per_page' => ['sometimes','integer','min:1','max:'.config('api.pagination.max_per_page')]];
    }
}
