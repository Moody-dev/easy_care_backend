<?php

namespace App\Http\Requests\Api\V1;

use App\Services\ClinicalRecords;

class CareRecordCommandRequest extends ClinicalCommandRequest
{
    public function rules(): array
    {
        $rules = parent::rules();
        unset($rules['kind']);
        $rules['program_version'] = ['required','integer','min:1','max:4294967294'];
        if (ClinicalRecords::action($this) === 'store') {
            $rules['birth_id'] = ['nullable','integer','min:1','max:999999999999999999'];
        }
        return $rules;
    }
}
