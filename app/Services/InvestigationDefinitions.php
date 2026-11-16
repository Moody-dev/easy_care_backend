<?php

namespace App\Services;

use App\Models\ClinicalTemplateVersion;
use App\Models\InvestigationTest;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class InvestigationDefinitions
{
    /** @return array<string, mixed> */
    public function validate(mixed $input): array
    {
        if (! is_array($input) || array_is_list($input)) {
            $this->invalid('Supply a JSON definition object.');
        }
        $data = Validator::make($input, [
            'code' => ['required', 'string', 'max:64', 'regex:/^[a-z][a-z0-9._-]*$/D'],
            'name' => ['required', 'string', 'max:191'],
            'department' => ['required', Rule::in(InvestigationTest::DEPARTMENTS)],
            'specimen' => ['present', 'nullable', 'string', 'max:191'],
            'is_active' => ['required', 'boolean'],
            'title' => ['required', 'string', 'max:191'],
            'service' => ['required', 'array:code,name'],
            'service.code' => ['required', 'string', 'max:64', 'regex:/^[a-z][a-z0-9._-]*$/D'],
            'service.name' => ['required', 'string', 'max:191'],
            'fields' => ['required', 'array', 'min:1', 'max:50'],
            'field_order' => ['sometimes', 'array', 'min:1', 'max:50'],
            'format_version' => ['sometimes', 'integer', Rule::in([1])],
        ])->validate();
        if (array_diff(array_keys($input), ['code', 'name', 'department', 'specimen', 'is_active', 'title', 'service', 'fields', 'field_order', 'format_version']) !== []
            || ! is_bool($input['is_active']) || array_is_list($input['fields'])) {
            $this->invalid('Unsupported definition keys or field structure.');
        }
        foreach (['name', 'title'] as $key) {
            $data[$key] = trim($data[$key]);
            if ($data[$key] === '') {
                $this->invalid('Supply a non-empty name and title.');
            }
        }
        $data['service']['name'] = trim($data['service']['name']);
        if ($data['service']['name'] === '') {
            $this->invalid('Supply a non-empty service name.');
        }
        $data['specimen'] = $data['specimen'] === null ? null : trim($data['specimen']);
        $core = [];
        foreach ($data['fields'] as $key => $field) {
            if (! is_array($field) || array_is_list($field)
                || array_diff(array_keys($field), ['type', 'label', 'required', 'max_length', 'options', 'unit', 'group', 'reference_ranges']) !== []) {
                $this->invalid('Unsupported result field definition.');
            }
            if (isset($field['group']) && (! is_string($field['group']) || trim($field['group']) === '' || mb_strlen($field['group']) > 160)) {
                $this->invalid('Invalid result field group.');
            }
            if (in_array($field['type'] ?? null, ['number', 'integer'], true) && ! array_key_exists('unit', $field)) {
                $this->invalid('Numeric fields need an explicit unit, or null for a dimensionless value.');
            }
            if (isset($field['unit']) && (! is_string($field['unit']) || trim($field['unit']) === '')) {
                $this->invalid('Supply a non-empty unit or explicit null.');
            }
            $this->ranges($field);
            $core[$key] = array_diff_key($field, array_flip(['group', 'reference_ranges']));
        }
        $candidate = new ClinicalTemplateVersion;
        $candidate->fields = $core;
        app(ClinicalPayloads::class)->definition($candidate);
        $data['field_order'] ??= array_keys($data['fields']);
        if (! array_is_list($data['field_order']) || count($data['field_order']) !== count($data['fields'])
            || ! collect($data['field_order'])->every(fn (mixed $key): bool => is_string($key) && array_key_exists($key, $data['fields']))
            || count(array_unique($data['field_order'])) !== count($data['field_order'])) {
            $this->invalid('Field order must list every result key exactly once.');
        }
        $data['format_version'] = 1;

        return $this->canonical($data);
    }

    /** @param array<string, mixed> $field */
    private function ranges(array $field): void
    {
        if (! array_key_exists('reference_ranges', $field)) {
            return;
        }
        $ranges = $field['reference_ranges'];
        if (! is_array($ranges) || ! array_is_list($ranges) || count($ranges) > 20) {
            $this->invalid('Reference ranges must be a bounded list.');
        }
        foreach ($ranges as $range) {
            if (! is_array($range) || array_is_list($range)
                || array_diff(array_keys($range), ['label', 'population', 'lower', 'upper', 'text']) !== []
                || ! is_string($range['label'] ?? null) || trim($range['label']) === '' || mb_strlen($range['label']) > 160) {
                $this->invalid('Each reference range needs a descriptive label.');
            }
            foreach (['population' => 500, 'text' => 2000] as $key => $max) {
                if (isset($range[$key]) && (! is_string($range[$key]) || trim($range[$key]) === '' || mb_strlen($range[$key]) > $max)) {
                    $this->invalid('Invalid reference description.');
                }
            }
            foreach (['lower', 'upper'] as $bound) {
                if (isset($range[$bound]) && ((! is_int($range[$bound]) && ! is_float($range[$bound]))
                    || ! is_finite((float) $range[$bound]) || ! in_array($field['type'] ?? null, ['number', 'integer'], true))) {
                    $this->invalid('Numeric reference bounds require a numeric result field.');
                }
            }
            if (isset($range['lower'], $range['upper']) && $range['lower'] > $range['upper']
                || ! isset($range['lower']) && ! isset($range['upper']) && ! isset($range['text'])) {
                $this->invalid('Reference bounds must be ordered or a textual reference must be supplied.');
            }
        }
    }

    /** @param array<array-key, mixed> $data @return array<array-key, mixed> */
    private function canonical(array $data): array
    {
        if (! array_is_list($data)) {
            ksort($data);
        }
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = $this->canonical($value);
            }
        }

        return $data;
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['definition' => [$message]]);
    }
}
