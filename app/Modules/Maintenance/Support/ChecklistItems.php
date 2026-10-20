<?php

namespace App\Modules\Maintenance\Support;

use Illuminate\Validation\Rule;

/**
 * The fields of a PM template (pm_checklists.items, copied to pm_visit_items.checklist when the
 * work starts) and their answers (pm_visit_items.answers, by key). One schema drives the form
 * (resources/js/components/pm/PmField.vue), the report (documents/partials/pm-field.blade.php)
 * and the checks here; resources/js/lib/pmTemplate.ts mirrors the range rules.
 *
 *   {key, label, type, section?, required?, ...settings}
 *
 *   pass_fail            Pass / Fail / N/A                       "pass" | "fail" | "na"
 *   text / textarea      free text                               string
 *   number               unit?, min?, max? (out of range = fail) number
 *   measure              the older name of number                number
 *   select               options                                 one of the options
 *   checkbox_group       options                                 list of the options ticked
 *   measure_table        rows [{key, label, unit?, min?, max?}],  {row: {column: number}}
 *                        columns [{key, label, min?, max?}];
 *                        a cell takes its column's range, else its row's
 *   repeat_table         columns [{key, label, type (text | number | pass_fail), unit?, min?, max?}],
 *                        rows {count} or {from: key of an earlier number field, max}
 *                                                                list of rows {column: value}
 *   photo                photos (how many; media with custom property "question" = key)   no answer
 *   readonly_from_asset  source (a field of the asset register)  the asset's value when recorded
 *   check                done / not done (older checklists)      bool
 *
 * "section" puts a field under a heading (the list order is the form order); "required" = must
 * be answered before the work is handed in.
 */
final class ChecklistItems
{
    public const TYPES = [
        'pass_fail', 'text', 'textarea', 'number', 'measure', 'select', 'checkbox_group',
        'measure_table', 'repeat_table', 'photo', 'readonly_from_asset', 'check',
    ];

    /** Types a template builder offers (measure and check stay readable in older templates). */
    public const EDITABLE_TYPES = [
        'pass_fail', 'text', 'textarea', 'number', 'select', 'checkbox_group', 'measure_table', 'repeat_table', 'photo', 'readonly_from_asset',
    ];

    public const REPEAT_COLUMN_TYPES = ['text', 'number', 'pass_fail'];

    /** Asset register fields a readonly_from_asset field may show. */
    public const ASSET_SOURCES = ['asset_code', 'name', 'brand', 'model', 'serial_number', 'property_no', 'ip_address', 'location', 'floor', 'room', 'used_by'];

    public const PASS_FAIL = ['pass', 'fail', 'na'];

    public const MAX_ITEMS = 80;

    public const MAX_ROWS = 100;

    public const MAX_COLUMNS = 12;

    public const MAX_OPTIONS = 30;

    public const KEY_PATTERN = '/^[a-z][a-z0-9_]{0,39}$/';

    private const LIMIT = 999999;

    /**
     * Fields as saved: known settings only, each kind of setting only on the types that use it.
     *
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    public static function clean(array $items): array
    {
        return array_values(array_map(function (array $item) {
            $type = in_array($item['type'] ?? null, self::TYPES, true) ? $item['type'] : 'check';
            $clean = ['key' => (string) $item['key'], 'label' => trim((string) $item['label']), 'type' => $type];
            $list = fn (string $name) => array_values(array_filter(is_array($item[$name] ?? null) ? $item[$name] : [], 'is_array'));
            $rows = is_array($item['rows'] ?? null) ? $item['rows'] : [];
            if (filled($item['section'] ?? null)) {
                $clean['section'] = trim((string) $item['section']);
            }

            $clean += match ($type) {
                'number', 'measure' => self::range($item, withUnit: true),
                'select', 'checkbox_group' => ['options' => self::options($item['options'] ?? [])],
                'measure_table' => [
                    'rows' => array_map(fn (array $row) => self::part($row) + self::range($row, withUnit: true), $list('rows')),
                    'columns' => array_map(fn (array $column) => self::part($column) + self::range($column), $list('columns')),
                ],
                'repeat_table' => [
                    'columns' => array_map(fn (array $column) => self::part($column) + [
                        'type' => in_array($column['type'] ?? null, self::REPEAT_COLUMN_TYPES, true) ? $column['type'] : 'text',
                    ] + self::range($column, withUnit: true), $list('columns')),
                    'rows' => filled($rows['from'] ?? null)
                        ? ['from' => (string) $rows['from'], 'max' => min(self::MAX_ROWS, max(1, (int) ($rows['max'] ?? self::MAX_ROWS)))]
                        : ['count' => min(self::MAX_ROWS, max(1, (int) ($rows['count'] ?? 1)))],
                ],
                'photo' => ['photos' => min(10, max(1, (int) ($item['photos'] ?? 1)))],
                'readonly_from_asset' => ['source' => in_array($item['source'] ?? null, self::ASSET_SOURCES, true) ? $item['source'] : 'asset_code'],
                default => [],
            };

            if (! empty($item['required']) && ! in_array($type, ['photo', 'readonly_from_asset'], true)) {
                $clean['required'] = true;
            }

            return $clean;
        }, $items));
    }

    /**
     * What is wrong with a template's fields beyond the per-field request rules: settings that
     * do not fit together. Empty = fine.
     *
     * @param  list<array<string, mixed>>  $items  cleaned
     * @return list<string>
     */
    public static function schemaErrors(array $items): array
    {
        $errors = [];
        $numbersSoFar = [];

        foreach ($items as $item) {
            $label = $item['label'];
            $error = fn (string $key, array $replace = []) => __("maintenance.templates.schema.{$key}", ['field' => $label, ...$replace]);

            switch ($item['type']) {
                case 'select':
                case 'checkbox_group':
                    if ($item['options'] === []) {
                        $errors[] = $error('options_required');
                    }
                    break;
                case 'measure_table':
                    if ($item['rows'] === [] || count($item['rows']) > self::MAX_ROWS) {
                        $errors[] = $error('rows_count', ['max' => self::MAX_ROWS]);
                    }
                    if ($item['columns'] === [] || count($item['columns']) > self::MAX_COLUMNS) {
                        $errors[] = $error('columns_count', ['max' => self::MAX_COLUMNS]);
                    }
                    array_push($errors, ...self::partErrors([...$item['rows'], ...$item['columns']], $error));
                    break;
                case 'repeat_table':
                    if ($item['columns'] === [] || count($item['columns']) > self::MAX_COLUMNS) {
                        $errors[] = $error('columns_count', ['max' => self::MAX_COLUMNS]);
                    }
                    array_push($errors, ...self::partErrors($item['columns'], $error));
                    if (isset($item['rows']['from']) && ! in_array($item['rows']['from'], $numbersSoFar, true)) {
                        $errors[] = $error('rows_from');
                    }
                    break;
            }
            if (isset($item['min'], $item['max']) && $item['min'] > $item['max']) {
                $errors[] = $error('range');
            }
            if (in_array($item['type'], ['number', 'measure'], true)) {
                $numbersSoFar[] = $item['key'];
            }
        }

        return $errors;
    }

    /**
     * Validation rules for the answers to a checklist, by "answers.{key}".
     *
     * @param  list<array<string, mixed>>  $checklist
     * @return array<string, list<mixed>>
     */
    public static function answerRules(array $checklist): array
    {
        $number = ['nullable', 'numeric', 'between:-'.self::LIMIT.','.self::LIMIT];
        $rules = [];

        foreach ($checklist as $item) {
            $field = "answers.{$item['key']}";
            switch ($item['type']) {
                case 'check':
                    $rules[$field] = ['nullable', 'boolean'];
                    break;
                case 'number':
                case 'measure':
                    $rules[$field] = $number;
                    break;
                case 'pass_fail':
                    $rules[$field] = ['nullable', Rule::in(self::PASS_FAIL)];
                    break;
                case 'select':
                    $rules[$field] = ['nullable', Rule::in($item['options'] ?? [])];
                    break;
                case 'checkbox_group':
                    $rules[$field] = ['nullable', 'array'];
                    $rules["{$field}.*"] = [Rule::in($item['options'] ?? [])];
                    break;
                case 'textarea':
                    $rules[$field] = ['nullable', 'string', 'max:5000'];
                    break;
                case 'measure_table':
                    $rules[$field] = ['nullable', 'array'];
                    $rules["{$field}.*"] = ['nullable', 'array'];
                    $rules["{$field}.*.*"] = $number;
                    break;
                case 'repeat_table':
                    $rules[$field] = ['nullable', 'array', 'max:'.self::MAX_ROWS];
                    $rules["{$field}.*"] = ['nullable', 'array'];
                    foreach ($item['columns'] ?? [] as $column) {
                        $rules["{$field}.*.{$column['key']}"] = match ($column['type']) {
                            'number' => $number,
                            'pass_fail' => ['nullable', Rule::in(self::PASS_FAIL)],
                            default => ['nullable', 'string', 'max:500'],
                        };
                    }
                    break;
                case 'photo':
                case 'readonly_from_asset':
                    break; // nothing typed in
                default:
                    $rules[$field] = ['nullable', 'string', 'max:500'];
            }
        }

        return $rules;
    }

    /**
     * Answers kept by the checklist's keys, each as its type stores it (null = not answered).
     * readonly_from_asset fields take the asset's value now, so the report keeps what it was.
     *
     * @param  list<array<string, mixed>>  $checklist
     * @param  array<string, mixed>  $answers
     * @param  array<string, mixed>  $asset  facts of the asset register (ASSET_SOURCES)
     * @return array<string, mixed>
     */
    public static function normalize(array $checklist, array $answers, array $asset = []): array
    {
        $clean = [];
        foreach ($checklist as $item) {
            $value = $answers[$item['key']] ?? null;
            $clean[$item['key']] = match ($item['type']) {
                'check' => (bool) $value,
                'pass_fail' => self::passFail($value),
                'measure', 'number' => self::number($value),
                'select' => in_array($value, $item['options'] ?? [], true) ? $value : null,
                'checkbox_group' => is_array($value) ? (array_values(array_intersect($item['options'] ?? [], $value)) ?: null) : null,
                'measure_table' => self::measureTable($item, is_array($value) ? $value : []),
                'repeat_table' => self::repeatTable($item, is_array($value) ? $value : [], $clean),
                'photo' => null,
                'readonly_from_asset' => filled($asset[$item['source']] ?? null) ? (string) $asset[$item['source']] : null,
                default => filled($value) ? trim((string) $value) : null,
            };
        }

        return $clean;
    }

    /**
     * How one answer came out: pass, fail, na, or null (not judged: text, an unanswered field, a
     * reading without a range). A table fails when any of its cells does.
     */
    public static function verdict(array $item, mixed $answer): ?string
    {
        return match ($item['type']) {
            'pass_fail' => in_array($answer, self::PASS_FAIL, true) ? $answer : null,
            'check' => $answer === true ? 'pass' : null,
            'measure', 'number' => is_numeric($answer) && (isset($item['min']) || isset($item['max']))
                ? (self::outOfRange($answer, $item['min'] ?? null, $item['max'] ?? null) ? 'fail' : 'pass')
                : null,
            'measure_table', 'repeat_table' => self::tableVerdict($item, $answer),
            default => null,
        };
    }

    /**
     * What failed: labels of failed fields, a table's failed cells named "label – row column".
     *
     * @param  list<array<string, mixed>>  $checklist
     * @param  array<string, mixed>  $answers
     * @return list<string>
     */
    public static function failures(array $checklist, array $answers): array
    {
        $failures = [];
        foreach ($checklist as $item) {
            $answer = $answers[$item['key']] ?? null;
            if ($item['type'] === 'measure_table' || $item['type'] === 'repeat_table') {
                foreach (self::failedCells($item, $answer) as $cell) {
                    $failures[] = "{$item['label']} – {$cell}";
                }
            } elseif (self::verdict($item, $answer) === 'fail') {
                $failures[] = $item['label'];
            }
        }

        return $failures;
    }

    /**
     * Labels of required fields still unanswered (a table counts once any cell is filled).
     *
     * @param  list<array<string, mixed>>  $checklist
     * @param  array<string, mixed>  $answers
     * @return list<string>
     */
    public static function missing(array $checklist, array $answers): array
    {
        return array_values(array_map(fn (array $item) => $item['label'], array_filter(
            $checklist, fn (array $item) => ! empty($item['required']) && $item['type'] !== 'check'
                && ! self::answered($answers[$item['key']] ?? null),
        )));
    }

    public static function outOfRange(mixed $value, ?float $min, ?float $max): bool
    {
        return is_numeric($value) && (($min !== null && $value < $min) || ($max !== null && $value > $max));
    }

    /**
     * The range of a cell of a measure table: its column's, else its row's.
     *
     * @return array{0: float|null, 1: float|null}
     */
    public static function cellRange(array $row, array $column): array
    {
        return isset($column['min']) || isset($column['max'])
            ? [$column['min'] ?? null, $column['max'] ?? null]
            : [$row['min'] ?? null, $row['max'] ?? null];
    }

    /**
     * How many rows a repeat table has: fixed, or the number typed in its "from" field (up to max).
     *
     * @param  array<string, mixed>  $answers
     */
    public static function repeatRowCount(array $item, array $answers): int
    {
        if (isset($item['rows']['from'])) {
            $count = (int) floor((float) ($answers[$item['rows']['from']] ?? 0));

            return max(0, min($count, $item['rows']['max'] ?? self::MAX_ROWS));
        }

        return (int) ($item['rows']['count'] ?? 0);
    }

    /**
     * "210 – 240 V", "≥ 10.5", "≤ 32 °C" or null: the recommended range as printed.
     */
    public static function rangeText(?float $min, ?float $max, ?string $unit = null): ?string
    {
        $number = fn (float $value) => rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
        $text = match (true) {
            $min !== null && $max !== null => $number($min).' – '.$number($max),
            $min !== null => '≥ '.$number($min),
            $max !== null => '≤ '.$number($max),
            default => null,
        };

        return $text === null ? null : trim($text.' '.($unit ?? ''));
    }

    private static function answered(mixed $value): bool
    {
        if (is_array($value)) {
            foreach ($value as $part) {
                if (self::answered($part)) {
                    return true;
                }
            }

            return false;
        }

        return $value !== null && $value !== '';
    }

    private static function tableVerdict(array $item, mixed $answer): ?string
    {
        if (! self::answered($answer)) {
            return null;
        }

        return self::failedCells($item, $answer) === [] ? 'pass' : 'fail';
    }

    /**
     * @return list<string> "row column" of each failed cell
     */
    private static function failedCells(array $item, mixed $answer): array
    {
        if (! is_array($answer)) {
            return [];
        }
        $failed = [];

        if ($item['type'] === 'measure_table') {
            foreach ($item['rows'] as $row) {
                foreach ($item['columns'] as $column) {
                    [$min, $max] = self::cellRange($row, $column);
                    if (self::outOfRange($answer[$row['key']][$column['key']] ?? null, $min, $max)) {
                        $failed[] = trim("{$row['label']} {$column['label']}");
                    }
                }
            }

            return $failed;
        }

        foreach (array_values($answer) as $index => $row) {
            foreach ($item['columns'] as $column) {
                $value = is_array($row) ? ($row[$column['key']] ?? null) : null;
                $bad = $column['type'] === 'pass_fail'
                    ? $value === 'fail'
                    : $column['type'] === 'number' && self::outOfRange($value, $column['min'] ?? null, $column['max'] ?? null);
                if ($bad) {
                    $failed[] = ($index + 1).' '.$column['label'];
                }
            }
        }

        return $failed;
    }

    /**
     * @return array<string, array<string, float|null>>|null
     */
    private static function measureTable(array $item, array $value): ?array
    {
        $table = [];
        foreach ($item['rows'] ?? [] as $row) {
            foreach ($item['columns'] ?? [] as $column) {
                $table[$row['key']][$column['key']] = self::number($value[$row['key']][$column['key']] ?? null);
            }
        }

        return self::answered($table) ? $table : null;
    }

    /**
     * @param  array<string, mixed>  $answersSoFar  the fields before it (its "from" field among them)
     * @return list<array<string, mixed>>|null
     */
    private static function repeatTable(array $item, array $value, array $answersSoFar): ?array
    {
        $rows = [];
        $value = array_values($value);
        for ($i = 0, $count = self::repeatRowCount($item, $answersSoFar); $i < $count; $i++) {
            $row = is_array($value[$i] ?? null) ? $value[$i] : [];
            foreach ($item['columns'] ?? [] as $column) {
                $cell = $row[$column['key']] ?? null;
                $rows[$i][$column['key']] = match ($column['type']) {
                    'number' => self::number($cell),
                    'pass_fail' => self::passFail($cell),
                    default => filled($cell) ? mb_substr(trim((string) $cell), 0, 500) : null,
                };
            }
        }

        return self::answered($rows) ? $rows : null;
    }

    private static function number(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    private static function passFail(mixed $value): ?string
    {
        return in_array($value, self::PASS_FAIL, true) ? $value : null;
    }

    /**
     * @return array{key: string, label: string}
     */
    private static function part(array $part): array
    {
        return ['key' => (string) ($part['key'] ?? ''), 'label' => trim((string) ($part['label'] ?? ''))];
    }

    /**
     * @return array<string, float|string>
     */
    private static function range(array $from, bool $withUnit = false): array
    {
        $range = [];
        foreach ($withUnit ? ['unit', 'min', 'max'] : ['min', 'max'] as $field) {
            $value = $from[$field] ?? null;
            if ($value !== null && $value !== '') {
                $range[$field] = $field === 'unit' ? mb_substr(trim((string) $value), 0, 20) : (float) $value;
            }
        }

        return $range;
    }

    /**
     * @return list<string>
     */
    private static function options(mixed $options): array
    {
        return array_slice(array_values(array_unique(array_filter(
            array_map(fn ($option) => mb_substr(trim((string) $option), 0, 100), is_array($options) ? $options : []),
            fn (string $option) => $option !== '',
        ))), 0, self::MAX_OPTIONS);
    }

    /**
     * Rows / columns of a table: keys as field keys, unique, labelled, ranges in order.
     *
     * @param  list<array<string, mixed>>  $parts
     * @return list<string>
     */
    private static function partErrors(array $parts, callable $error): array
    {
        $errors = [];
        $keys = array_column($parts, 'key');
        if (count($keys) !== count(array_unique($keys))) {
            $errors[] = $error('part_keys_unique');
        }
        foreach ($parts as $part) {
            if (! preg_match(self::KEY_PATTERN, $part['key']) || $part['label'] === '') {
                $errors[] = $error('part_invalid');
                break;
            }
        }
        foreach ($parts as $part) {
            if (isset($part['min'], $part['max']) && $part['min'] > $part['max']) {
                $errors[] = $error('range');
                break;
            }
        }

        return $errors;
    }
}
