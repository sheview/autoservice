<?php

namespace App\Modules\Maintenance\Support;

/**
 * The questions of a PM checklist (pm_checklists.items, copied to pm_visit_items.checklist when
 * the work starts) and their answers (pm_visit_items.answers, by key):
 *
 *   {key, label, type, section?, unit?, min?, max?, required?}
 *
 *   pass_fail  Pass / Fail / N/A                     answer "pass" | "fail" | "na"
 *   measure    a reading with its unit and the range it should be in (min / max, either may be
 *              left out); out of range = a fail         answer number
 *   check      done / not done (older checklists)       answer bool
 *   number     a reading without a range (older)        answer number
 *   text       free text                                answer string
 *
 * "section" groups questions under a heading; "required" = must be answered before the work is
 * handed in. Checklists saved before these existed still read the same (check / text / number).
 */
final class ChecklistItems
{
    public const TYPES = ['pass_fail', 'measure', 'check', 'number', 'text'];

    public const PASS_FAIL = ['pass', 'fail', 'na'];

    public const MAX_ITEMS = 80;

    /**
     * Questions as saved: known fields only, unit / range only on a measure.
     *
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    public static function clean(array $items): array
    {
        return array_values(array_map(function (array $item) {
            $type = in_array($item['type'] ?? null, self::TYPES, true) ? $item['type'] : 'check';
            $clean = [
                'key' => (string) $item['key'],
                'label' => trim((string) $item['label']),
                'type' => $type,
            ];
            if (filled($item['section'] ?? null)) {
                $clean['section'] = trim((string) $item['section']);
            }
            if ($type === 'measure') {
                foreach (['unit' => 'string', 'min' => 'number', 'max' => 'number'] as $field => $kind) {
                    $value = $item[$field] ?? null;
                    if ($value !== null && $value !== '') {
                        $clean[$field] = $kind === 'number' ? (float) $value : trim((string) $value);
                    }
                }
            }
            if (! empty($item['required'])) {
                $clean['required'] = true;
            }

            return $clean;
        }, $items));
    }

    /**
     * Answers kept by the checklist's keys, each as its type stores it (null = not answered).
     *
     * @param  list<array<string, mixed>>  $checklist
     * @param  array<string, mixed>  $answers
     * @return array<string, bool|float|string|null>
     */
    public static function normalize(array $checklist, array $answers): array
    {
        $clean = [];
        foreach ($checklist as $item) {
            $value = $answers[$item['key']] ?? null;
            $clean[$item['key']] = match ($item['type']) {
                'check' => (bool) $value,
                'pass_fail' => in_array($value, self::PASS_FAIL, true) ? $value : null,
                'measure', 'number' => is_numeric($value) ? (float) $value : null,
                default => filled($value) ? trim((string) $value) : null,
            };
        }

        return $clean;
    }

    /**
     * How one answer came out: pass, fail, na, or null (not judged: text, an unanswered question,
     * a reading without a range).
     */
    public static function verdict(array $item, mixed $answer): ?string
    {
        return match ($item['type']) {
            'pass_fail' => in_array($answer, self::PASS_FAIL, true) ? $answer : null,
            'check' => $answer === true ? 'pass' : null,
            'measure' => is_numeric($answer) && (isset($item['min']) || isset($item['max']))
                ? ((isset($item['min']) && $answer < $item['min']) || (isset($item['max']) && $answer > $item['max']) ? 'fail' : 'pass')
                : null,
            default => null,
        };
    }

    /**
     * Labels of the questions that failed (a Fail, or a reading out of its range).
     *
     * @param  list<array<string, mixed>>  $checklist
     * @param  array<string, mixed>  $answers
     * @return list<string>
     */
    public static function failures(array $checklist, array $answers): array
    {
        return array_values(array_map(fn (array $item) => $item['label'], array_filter(
            $checklist, fn (array $item) => self::verdict($item, $answers[$item['key']] ?? null) === 'fail',
        )));
    }

    /**
     * Labels of required questions still unanswered.
     *
     * @param  list<array<string, mixed>>  $checklist
     * @param  array<string, mixed>  $answers
     * @return list<string>
     */
    public static function missing(array $checklist, array $answers): array
    {
        return array_values(array_map(fn (array $item) => $item['label'], array_filter(
            $checklist, fn (array $item) => ! empty($item['required']) && ($answers[$item['key']] ?? null) === null
                && $item['type'] !== 'check',
        )));
    }
}
