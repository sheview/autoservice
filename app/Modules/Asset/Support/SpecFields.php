<?php

namespace App\Modules\Asset\Support;

use Carbon\CarbonImmutable;
use Illuminate\Validation\Rule;

/**
 * The extra fields ("specs") a category defines for its assets, e.g. CPU / RAM for a PC:
 * [{key, label, type: text|number|date|select, options: [...], required: bool}]
 */
class SpecFields
{
    /**
     * Validation rules for the spec values, keyed "{$prefix}{field key}".
     *
     * @param  list<array<string, mixed>>  $fields
     * @return array<string, list<mixed>>
     */
    public static function rules(array $fields, string $prefix = 'specs.'): array
    {
        $rules = [];

        foreach ($fields as $field) {
            $rules[$prefix.$field['key']] = [
                ($field['required'] ?? false) ? 'required' : 'nullable',
                ...match ($field['type']) {
                    'number' => ['numeric'],
                    'date' => ['date'],
                    'select' => [Rule::in($field['options'] ?? [])],
                    default => ['string', 'max:255'],
                },
            ];
        }

        return $rules;
    }

    /**
     * Field labels for validation messages, keyed like rules().
     *
     * @param  list<array<string, mixed>>  $fields
     * @return array<string, string>
     */
    public static function labels(array $fields, string $prefix = 'specs.'): array
    {
        $labels = [];
        foreach ($fields as $field) {
            $labels[$prefix.$field['key']] = $field['label'];
        }

        return $labels;
    }

    /**
     * Keep only the category's fields, drop empty values, store numbers as numbers and dates as Y-m-d.
     *
     * @param  list<array<string, mixed>>  $fields
     * @param  array<string, mixed>  $values  (already validated)
     * @return array<string, string|int|float>
     */
    public static function normalize(array $fields, array $values): array
    {
        $specs = [];

        foreach ($fields as $field) {
            $value = $values[$field['key']] ?? null;
            if ($value === null || $value === '') {
                continue;
            }

            $specs[$field['key']] = match ($field['type']) {
                'number' => $value + 0,
                'date' => CarbonImmutable::parse($value)->toDateString(),
                default => (string) $value,
            };
        }

        return $specs;
    }

    /**
     * Clean a spec_fields definition from the category form.
     *
     * @param  list<array<string, mixed>>  $fields
     * @return list<array{key: string, label: string, type: string, options: list<string>, required: bool}>
     */
    public static function clean(array $fields): array
    {
        return array_values(array_map(fn (array $field) => [
            'key' => $field['key'],
            'label' => trim($field['label']),
            'type' => $field['type'],
            'options' => $field['type'] === 'select'
                ? array_values(array_unique(array_filter(array_map('trim', $field['options'] ?? []), fn ($o) => $o !== '')))
                : [],
            'required' => (bool) ($field['required'] ?? false),
        ], $fields));
    }
}
