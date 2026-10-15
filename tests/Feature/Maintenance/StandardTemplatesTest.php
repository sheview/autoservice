<?php

use App\Modules\Maintenance\Support\ChecklistItems;
use App\Modules\Maintenance\Support\StandardTemplates;

/**
 * A sample answer for every field, with readings in range unless $outOfRange.
 */
function sampleAnswers(array $items, bool $outOfRange = false): array
{
    $answers = [];
    foreach ($items as $item) {
        $reading = fn (array $part) => $outOfRange && isset($part['max']) ? $part['max'] + 100 : ($part['min'] ?? $part['max'] ?? 5);
        $answers[$item['key']] = match ($item['type']) {
            'pass_fail' => 'pass',
            'number', 'measure' => $item['key'] === 'battery_total' || $item['key'] === 'camera_count' ? 3 : $reading($item),
            'select' => $item['options'][0],
            'checkbox_group' => [$item['options'][0]],
            'measure_table' => collect($item['rows'])->mapWithKeys(fn ($row) => [$row['key'] => collect($item['columns'])
                ->mapWithKeys(fn ($column) => [$column['key'] => $reading([...$row, ...array_filter($column)])])->all()])->all(),
            'repeat_table' => array_fill(0, 3, collect($item['columns'])->mapWithKeys(fn ($column) => [$column['key'] => match ($column['type']) {
                'number' => $reading($column), 'pass_fail' => 'pass', default => 'x',
            }])->all()),
            'photo', 'readonly_from_asset' => null,
            default => 'ข้อความ',
        };
    }

    return $answers;
}

it('takes the customer forms with their mistakes put right', function () {
    $all = collect(StandardTemplates::all())->keyBy('code');
    $text = json_encode($all->all(), JSON_UNESCAPED_UNICODE);

    foreach (['Wring', 'BATTARY', 'Vcd', 'Subnet Mark', 'zxxx', 'Connecter'] as $mistake) {
        expect($text)->not->toContain($mistake);
    }
    expect($text)->toContain('Check Cable Wiring')->toContain('Subnet Mask');

    // The 14 forms (Monitoring with its variants) are there.
    expect($all->keys()->all())->toContain('DC-ELEC', 'DC-UPS', 'DC-BATT', 'DC-MON', 'DC-MON-EAGLEEYE', 'DC-MON-GW04', 'DC-MON-GH04',
        'DC-MON-CYBERHUB', 'DC-PAC', 'DC-WLD', 'DC-FSS', 'DC-HSSD', 'DC-CCTV', 'NW-SWITCH', 'DC-ACS', 'DC-CTRL', 'PC-SET', 'NW-GROUP');

    // The Cisco switch form is a network form, not CCTV.
    $switch = json_encode($all['NW-SWITCH'], JSON_UNESCAPED_UNICODE);
    expect($switch)->not->toContain('CCTV')->and($all['NW-SWITCH']['service_line'])->toBe('network');

    // The UPS AC output line voltage is 370–410.
    $acOutput = collect($all['DC-UPS']['items'])->firstWhere('key', 'ac_output');
    expect(collect($acOutput['rows'])->firstWhere('key', 'line_voltage'))->toMatchArray(['min' => 370, 'max' => 410]);

    // Who signs is never part of a template.
    foreach ($all as $template) {
        $labels = strtolower(implode(' ', array_column($template['items'], 'label')));
        expect($labels)->not->toContain('tel')->not->toContain('email :')->not->toContain('approve by');
    }

    expect($all['PC-SET']['layout'])->toBe('grouped_table')->and($all['NW-GROUP']['layout'])->toBe('grouped_table')
        ->and($all['DC-UPS']['layout'])->toBe('per_asset');
});

it('renders every standard template in the report, empty and filled in, and flags readings out of range', function () {
    foreach (StandardTemplates::all() as $template) {
        $items = ChecklistItems::clean($template['items']);
        $asset = ['ip_address' => '10.0.0.5', 'property_no' => 'P-1', 'serial_number' => 'SN-1', 'location' => 'ชั้น 3'];

        foreach ([null, false, true] as $outOfRange) {
            $answers = $outOfRange === null ? [] : ChecklistItems::normalize($items, sampleAnswers($items, $outOfRange), $asset);
            $html = '';
            foreach ($items as $field) {
                $html .= view('documents.partials.pm-field', [
                    'check' => [...$field, 'answer' => $answers[$field['key']] ?? null, 'remark' => null,
                        'verdict' => ChecklistItems::verdict($field, $answers[$field['key']] ?? null)],
                    'pending' => $outOfRange === null,
                ])->render();
            }

            $labels = array_column(array_filter($items, fn ($item) => $item['type'] !== 'photo'), 'label');
            foreach ($labels as $label) {
                expect($html)->toContain(e($label));
            }

            // a reading with a maximum (repeat_table rows.max is a row count, not a range)
            $hasRange = collect($items)->contains(fn (array $item) => isset($item['max'])
                || collect($item['columns'] ?? [])->contains(fn (array $column) => isset($column['max']))
                || ($item['type'] === 'measure_table' && collect($item['rows'])->contains(fn (array $row) => isset($row['max']))));
            if ($outOfRange === true && $hasRange) {
                expect(ChecklistItems::failures($items, $answers))->not->toBeEmpty("{$template['code']} flags nothing out of range")
                    ->and($html)->toContain('#b91c1c');
            }
            if ($outOfRange === false) {
                expect(ChecklistItems::failures($items, $answers))->toBe([], "{$template['code']} fails in range");
            }
        }
    }
});
