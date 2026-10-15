{{--
    One field of a PM template with its answer, as a row of the checks table (4 columns: field,
    recommended range, outcome, remark). Reads the same schema as the form (ChecklistItems,
    resources/js/components/pm/PmField.vue); a reading out of its range is printed in red.
    $check = the field + answer, remark, verdict (PmReportData); $pending = not checked yet.
--}}
@php($schema = \App\Modules\Maintenance\Support\ChecklistItems::class)
@php($answer = $pending ? null : ($check['answer'] ?? null))
@php($number = fn ($value) => is_numeric($value) ? rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.') : $value)
@php($red = 'color: #b91c1c; font-weight: 700')
@php($verdictStyle = ['pass' => 'color: #166534', 'fail' => $red, 'na' => 'color: #555'])

@switch($check['type'])
    @case('photo')
        @break

    @case('measure_table')
        @php($rows = $check['rows'] ?? [])
        @php($columns = $check['columns'] ?? [])
        @php($rowRanges = collect($rows)->contains(fn ($row) => isset($row['min']) || isset($row['max'])))
        @php($columnRanges = collect($columns)->contains(fn ($column) => isset($column['min']) || isset($column['max'])))
        <tr>
            <td colspan="4">
                <div style="font-weight: 600">{{ $check['label'] }}</div>
                <table class="pm-grid">
                    <thead>
                        <tr>
                            <th></th>
                            @foreach ($columns as $column)
                                <th>{{ $column['label'] }}</th>
                            @endforeach
                            @if ($rowRanges)
                                <th>{{ __('document.pm.recommend') }}</th>
                            @endif
                        </tr>
                        @if ($columnRanges)
                            <tr class="muted">
                                <td>{{ __('document.pm.recommend') }}</td>
                                @foreach ($columns as $column)
                                    <td>{{ $schema::rangeText($column['min'] ?? null, $column['max'] ?? null) }}</td>
                                @endforeach
                                @if ($rowRanges)
                                    <td></td>
                                @endif
                            </tr>
                        @endif
                    </thead>
                    <tbody>
                        @foreach ($rows as $row)
                            <tr>
                                <td>{{ $row['label'] }}@if (isset($row['unit'])) ({{ $row['unit'] }})@endif</td>
                                @foreach ($columns as $column)
                                    @php($value = $answer[$row['key']][$column['key']] ?? null)
                                    @php([$min, $max] = $schema::cellRange($row, $column))
                                    <td style="{{ $schema::outOfRange($value, $min, $max) ? $red : '' }}">{{ $value === null ? '' : $number($value) }}</td>
                                @endforeach
                                @if ($rowRanges)
                                    <td class="muted">{{ $schema::rangeText($row['min'] ?? null, $row['max'] ?? null) }}</td>
                                @endif
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                @if ($check['remark'] ?? null)
                    <div class="pre muted">{{ $check['remark'] }}</div>
                @endif
            </td>
        </tr>
        @break

    @case('repeat_table')
        @php($columns = $check['columns'] ?? [])
        @php($rows = is_array($answer) ? $answer : [])
        <tr>
            <td colspan="4">
                <div style="font-weight: 600">{{ $check['label'] }}</div>
                @if ($rows)
                    <table class="pm-grid">
                        <thead>
                            <tr>
                                <th style="width: 8%">{{ __('document.pm.row_no') }}</th>
                                @foreach ($columns as $column)
                                    <th>{{ $column['label'] }}@if (isset($column['unit'])) ({{ $column['unit'] }})@endif</th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($rows as $index => $row)
                                <tr>
                                    <td>{{ $index + 1 }}</td>
                                    @foreach ($columns as $column)
                                        @php($value = $row[$column['key']] ?? null)
                                        @php($bad = $column['type'] === 'pass_fail' ? $value === 'fail' : ($column['type'] === 'number' && $schema::outOfRange($value, $column['min'] ?? null, $column['max'] ?? null)))
                                        <td style="{{ $bad ? $red : '' }}">
                                            {{ $value === null ? '' : ($column['type'] === 'pass_fail' ? __('document.pm.'.$value) : $number($value)) }}
                                        </td>
                                    @endforeach
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @else
                    <div class="muted">{{ __('document.pm.none') }}</div>
                @endif
                @if ($check['remark'] ?? null)
                    <div class="pre muted">{{ $check['remark'] }}</div>
                @endif
            </td>
        </tr>
        @break

    @default
        @php($empty = $answer === null || $answer === '' || $answer === [])
        @php($outcome = match ($check['type']) {
            'pass_fail' => $empty ? __('document.pm.none') : __('document.pm.'.$answer),
            'check' => $pending ? __('document.pm.none') : ($answer ? __('document.pm.done') : __('document.pm.not_done')),
            'number', 'measure' => $empty ? __('document.pm.none') : trim($number($answer).' '.($check['unit'] ?? '')),
            'checkbox_group' => $empty ? __('document.pm.none') : implode(', ', (array) $answer),
            default => $empty ? __('document.pm.none') : (string) $answer,
        })
        @php($range = in_array($check['type'], ['number', 'measure'], true) ? $schema::rangeText($check['min'] ?? null, $check['max'] ?? null, $check['unit'] ?? null) : null)
        <tr>
            <td>{{ $check['label'] }}</td>
            <td class="muted">{{ $range }}</td>
            <td class="{{ $check['type'] === 'textarea' ? 'pre' : '' }}" style="{{ $pending ? '' : ($verdictStyle[$check['verdict'] ?? ''] ?? '') }}">
                {{ $outcome }}@if (($check['verdict'] ?? null) === 'fail' && $check['type'] !== 'pass_fail' && ! $pending) ({{ __('document.pm.fail') }})@endif
            </td>
            <td class="pre">{{ $check['remark'] ?? '' }}</td>
        </tr>
@endswitch
