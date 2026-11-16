<?php

namespace App\Services;

use App\Models\InvestigationSchema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class InvestigationResultPayloads
{
    /** @return array<string, mixed> */
    public function validate(InvestigationSchema $schema, mixed $input, bool $complete): array
    {
        $definition = app(InvestigationDefinitions::class)->validate($schema->schema_json);
        if ($input instanceof \stdClass) {
            $input = (array) $input;
        }
        if (! is_array($input) || ($input !== [] && array_is_list($input))
            || array_diff(array_keys($input), array_keys($definition['fields'])) !== []) {
            throw ValidationException::withMessages(['fields' => ['Supply only keys from the requested approved definition.']]);
        }
        $values = [];
        foreach ($definition['fields'] as $key => $field) {
            $value = $input[$key] ?? null;
            if (is_string($value)) {
                $value = trim($value) === '' ? null : trim($value);
            }
            $rules = [$complete && ($field['required'] ?? false) ? 'required' : 'nullable', ...match ($field['type']) {
                'text' => ['string', 'max:'.($field['max_length'] ?? 5000)],
                'number' => ['numeric'], 'integer' => ['integer'], 'boolean' => ['boolean'],
                'date' => ['date_format:Y-m-d'], 'select' => ['string', Rule::in($field['options'])],
            }];
            Validator::make(['fields' => [$key => $value]], ['fields.'.$key => $rules])->validate();
            if ($value !== null && ($field['type'] === 'boolean' && ! is_bool($value)
                || in_array($field['type'], ['number', 'integer'], true) && (is_bool($value) || ! is_finite((float) $value)))) {
                throw ValidationException::withMessages(['fields.'.$key => ['Supply a finite numeric value or an explicit JSON boolean as defined.']]);
            }
            if (array_key_exists($key, $input)) {
                $values[$key] = $value;
            }
        }
        if ($complete && collect($values)->filter(fn (mixed $value): bool => $value !== null && $value !== '')->isEmpty()) {
            throw ValidationException::withMessages(['fields' => ['Record at least one result value before submission.']]);
        }

        return $values;
    }
}
