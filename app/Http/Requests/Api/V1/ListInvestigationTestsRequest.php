<?php

namespace App\Http\Requests\Api\V1;

use App\Models\InvestigationTest;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListInvestigationTestsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['q' => ['sometimes', 'string', 'min:1', 'max:100'],
            'department' => ['sometimes', Rule::in(InvestigationTest::DEPARTMENTS)],
            'active' => ['sometimes', 'boolean'],
            'page' => ['sometimes', 'integer', 'min:1', 'max:1000000'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.config('api.pagination.max_per_page')]];
    }
}
