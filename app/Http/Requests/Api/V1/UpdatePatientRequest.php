<?php

namespace App\Http\Requests\Api\V1;

class UpdatePatientRequest extends StorePatientRequest
{
    public function rules(): array
    {
        $rules = parent::rules();
        unset($rules['mrn'], $rules['category_id'], $rules['number_reservation_token'],
            $rules['insurance'], $rules['insurance.plan_id'], $rules['insurance.member_number']);
        $rules['first_name'] = ['sometimes', 'required', 'string', 'max:64'];
        $rules['last_name'] = ['sometimes', 'required', 'string', 'max:64'];
        $rules['version'] = ['required', 'integer', 'min:1', 'max:4294967294'];

        return $rules;
    }
}
