<?php

namespace App\Http\Requests\Api\V1;

use App\Models\CareProgram;
use App\Services\CarePrograms;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class CareProgramCommandRequest extends FormRequest
{
    public function authorize(): bool { return true; }
    public function rules(): array
    {
        $version = ['required','integer','min:1','max:4294967294'];
        $id = ['required','integer','min:1','max:999999999999999999'];
        $action = CarePrograms::action($this);
        $rules = ['encounter_version' => $version, 'reason' => ['required','string','min:5','max:2000']];
        if ($action === 'store') {
            return $rules + ['type' => ['required', Rule::in(CareProgram::TYPES)], 'patient_version' => $version, 'identity_verified' => ['required','accepted']];
        }
        $rules += ['encounter_id' => $id, 'program_version' => $version];
        if ($action === 'birth') {
            $rules['birth_order'] = ['required','integer','min:1','max:4294967294'];
        }
        if ($action === 'link') {
            $rules += ['child_patient_id' => $id,'child_patient_version' => $version,'identity_verified' => ['required','accepted']];
        }
        return $rules;
    }
    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach (array_diff(array_keys($this->all()), array_keys($this->rules())) as $field) {
                $validator->errors()->add($field, 'This field cannot be supplied.');
            }
            if (is_string($this->input('reason')) && mb_strlen(trim($this->input('reason'))) < 5) {
                $validator->errors()->add('reason', 'Enter a meaningful reason.');
            }
        }];
    }
}
