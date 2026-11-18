<?php

namespace App\Http\Requests\Api\V1;

use App\Services\ExportReports;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class ExportRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    protected function prepareForValidation(): void
    {
        if ($this->input('kind')==='report' && is_array($filters=$this->input('filters',[]))) {
            if (!array_key_exists('from_date',$filters) && !array_key_exists('to_date',$filters)) {
                $today=CarbonImmutable::now('Africa/Lagos')->toDateString();
                $this->merge(['filters'=>$filters+['from_date'=>$today,'to_date'=>$today]]);
            }
        }
    }

    public function rules(): array
    {
        $report=$this->input('kind')==='report';
        $rules=['kind'=>['required',Rule::in(['report','invoice','receipt','prescription','clinical','result'])],
            'format'=>['required',Rule::in($report?['csv','html']:['html'])],
            'source_id'=>[$report?'prohibited':'required','integer','min:1','max:999999999999999999'],
            'report_key'=>[$report?'required':'prohibited',Rule::in(ExportReports::KINDS)],
            'filters'=>[$report?'required':'prohibited','array']];
        if ($report && in_array($this->input('report_key'),ExportReports::KINDS,true)) {
            $filters=ReportRequest::rulesFor($this->input('report_key'));
            unset($filters['page'],$filters['per_page']);
            $rules['filters'][]='array:'.implode(',',array_keys($filters));
            foreach ($filters as $key=>$rule) {
                $rules['filters.'.$key]=array_map(fn ($item)=>$item==='after_or_equal:from_date'?'after_or_equal:filters.from_date':$item,$rule);
            }
        }
        return $rules;
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach (array_diff(array_keys($this->all()),['kind','format','source_id','report_key','filters']) as $key) {
                $validator->errors()->add($key,'This field is not supported.');
            }
            if ($this->input('kind')==='report' && !$validator->errors()->has('filters.from_date') && !$validator->errors()->has('filters.to_date')
                && is_string($this->input('filters.from_date')) && is_string($this->input('filters.to_date'))) {
                if (CarbonImmutable::parse($this->input('filters.from_date'))->diffInDays(CarbonImmutable::parse($this->input('filters.to_date')))>365) {
                    $validator->errors()->add('filters.to_date','Select at most 366 calendar days.');
                }
            }
        }];
    }
}
