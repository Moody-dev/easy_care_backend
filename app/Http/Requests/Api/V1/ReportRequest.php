<?php

namespace App\Http\Requests\Api\V1;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class ReportRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    protected function prepareForValidation(): void
    {
        if (!$this->has('from_date') && !$this->has('to_date')) {
            $today=CarbonImmutable::now('Africa/Lagos')->toDateString();
            $this->merge(['from_date'=>$today,'to_date'=>$today]);
        }
    }

    public function rules(): array
    {
        $date=['required','date_format:Y-m-d','after_or_equal:1000-01-02','before_or_equal:9999-12-30'];
        $id=['sometimes','integer','min:1','max:999999999999999999'];
        $kind=$this->route('kind');
        $rules=['from_date'=>$date,'to_date'=>[...$date,'after_or_equal:from_date']];
        if ($this->route()->getName()==='api.v1.dashboard') { return $rules; }
        if (in_array($kind,['activity','trend','accounts','billing','hmo'],true)) { $rules['patient_id']=$id; }
        if (in_array($kind,['activity','trend'],true)) { $rules+=['encounter_id'=>$id,'doctor_id'=>$id]; }
        if ($kind==='trend') {
            $rules['module']=['required',Rule::in(\App\Services\ReportAccess::ACTIVITY)];
        }
        if (in_array($kind,['inventory','positions','movements'],true)) { $rules+=['store_id'=>$id,'drug_id'=>$id]; }
        if (in_array($kind,['positions','movements','accounts'],true)) {
            $rules+=['page'=>['sometimes','integer','min:1'],'per_page'=>['sometimes','integer','min:1','max:100']];
        }
        return $rules;
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach (array_diff(array_keys($this->all()),array_keys($this->rules())) as $field) {
                $validator->errors()->add($field,'This filter is not supported by this report.');
            }
            if (!$validator->errors()->has('from_date') && !$validator->errors()->has('to_date')) {
                $from=CarbonImmutable::parse($this->input('from_date'),'Africa/Lagos');
                $to=CarbonImmutable::parse($this->input('to_date'),'Africa/Lagos');
                if ($from->diffInDays($to)>365) { $validator->errors()->add('to_date','Select at most 366 calendar days.'); }
            }
        }];
    }
}
