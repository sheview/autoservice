{{--
    Equipment of a grouped_table template (PC, network), the fields that fit a table (tables and
    photos are left out); a part (a PC's monitor) under its main piece. The header row repeats on
    every page; rows do not split across pages.
    Few fields: a row per piece, a column per field. More than 5 fields would squeeze the columns
    to a few letters each, so the table turns: a row per field (its label on one line), a column
    per piece, up to 6 pieces to a table.
--}}
@php
    $schema = \App\Modules\Maintenance\Support\ChecklistItems::class;
    $columns = array_values(array_filter($items[0]['checks'] ?? [], fn ($check) => ! in_array($check['type'], ['measure_table', 'repeat_table', 'photo'], true)));
    $number = fn ($value) => is_numeric($value) ? rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.') : $value;
    $label = fn (array $column) => $column['label'].(isset($column['unit']) ? " ({$column['unit']})" : '');
    $answersOf = fn (array $item) => $item['result'] === 'pending' ? [] : collect($item['checks'])->pluck('answer', 'key')->all();
    // What a piece answered for a field, and the style that shows it red (failed / out of range).
    $cell = function (array $answers, array $column) use ($schema, $number) {
        $value = $answers[$column['key']] ?? null;
        $red = $column['type'] === 'pass_fail' ? $value === 'fail' : (in_array($column['type'], ['number', 'measure'], true) && $schema::outOfRange($value, $column['min'] ?? null, $column['max'] ?? null));
        $text = match ($column['type']) {
            'pass_fail' => $value ? __('document.pm.'.$value) : '',
            'check' => $value ? '✓' : '',
            'checkbox_group' => is_array($value) ? implode(', ', $value) : '',
            default => $value === null ? '' : $number($value),
        };

        return [$text, $red ? 'color: #b91c1c; font-weight: 700' : ''];
    };
    $note = fn (array $item) => collect([
        $item['not_inspected_reason'] ?? null ? __('ui.pm_visits.not_inspected_reasons.'.$item['not_inspected_reason']) : null,
        $item['note'] ?? null,
    ])->filter()->implode(' — ');
    $wide = count($columns) > 5;
    // Fewer pieces than a full table: no empty columns.
    $perTable = min(6, max(1, count($items)));
@endphp

@if ($wide)
    @foreach (array_chunk($items, $perTable) as $c => $chunk)
        @php($chunkAnswers = array_map($answersOf, $chunk))
        <table class="grouped turned">
            <thead>
                <tr>
                    <th style="width: 42mm">{{ __('document.pm_sheet.check_item') }}</th>
                    @foreach ($chunk as $i => $item)
                        <th class="center">
                            <span class="muted">{{ $c * $perTable + $i + 1 }}.</span>
                            @if ($item['part'] ?? false)↳ @endif<span class="code">{{ $item['asset']['asset_code'] ?? '-' }}</span><br>
                            <span style="font-weight: 400">{{ $item['asset']['name'] ?? '' }}</span>
                        </th>
                    @endforeach
                    {{-- the last table keeps its columns as wide as the others --}}
                    @for ($blank = count($chunk); $blank < $perTable; $blank++)
                        <th class="blank"></th>
                    @endfor
                </tr>
            </thead>
            <tbody>
                @foreach ($columns as $column)
                    <tr>
                        <td>{{ $label($column) }}</td>
                        @foreach ($chunk as $i => $item)
                            @php([$text, $style] = $cell($chunkAnswers[$i], $column))
                            <td class="center" style="{{ $style }}">{{ $text }}</td>
                        @endforeach
                        @for ($blank = count($chunk); $blank < $perTable; $blank++)
                            <td class="blank"></td>
                        @endfor
                    </tr>
                @endforeach
                <tr>
                    <th>{{ __('document.pm_sheet.result') }}</th>
                    @foreach ($chunk as $item)
                        <td class="center result-{{ $item['result'] }}">{{ __('ui.pm_visits.results.'.$item['result']) }}</td>
                    @endforeach
                    @for ($blank = count($chunk); $blank < $perTable; $blank++)
                        <td class="blank"></td>
                    @endfor
                </tr>
                <tr>
                    <th>{{ __('document.pm.note') }}</th>
                    @foreach ($chunk as $item)
                        <td class="pre">{{ $note($item) }}</td>
                    @endforeach
                    @for ($blank = count($chunk); $blank < $perTable; $blank++)
                        <td class="blank"></td>
                    @endfor
                </tr>
            </tbody>
        </table>
    @endforeach
@else
    <table class="grouped">
        <thead>
            <tr>
                <th class="center" style="width: 8mm">#</th>
                <th style="width: 30mm">{{ __('document.pm_sheet.equipment') }}</th>
                @foreach ($columns as $column)
                    <th>{{ $label($column) }}</th>
                @endforeach
                <th style="width: 16mm">{{ __('document.pm_sheet.result') }}</th>
                <th style="width: 30mm">{{ __('document.pm.note') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($items as $i => $item)
                @php($answers = $answersOf($item))
                <tr>
                    <td class="center">{{ $i + 1 }}</td>
                    <td style="{{ ($item['part'] ?? false) ? 'padding-left: 4mm' : '' }}">@if ($item['part'] ?? false)↳ @endif<span style="font-family: monospace">{{ $item['asset']['asset_code'] ?? '-' }}</span><br>{{ $item['asset']['name'] ?? '' }}</td>
                    @foreach ($columns as $column)
                        @php([$text, $style] = $cell($answers, $column))
                        <td class="center" style="{{ $style }}">{{ $text }}</td>
                    @endforeach
                    <td class="result-{{ $item['result'] }}">{{ __('ui.pm_visits.results.'.$item['result']) }}</td>
                    <td class="pre">{{ $note($item) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
@endif
