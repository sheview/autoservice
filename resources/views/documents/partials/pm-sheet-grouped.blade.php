{{--
    Equipment of a grouped_table template (PC, network): a row per piece, a column per field the
    row can show (tables and photos are left out). The header row repeats on every page; rows do not
    split across pages.
--}}
@php($schema = \App\Modules\Maintenance\Support\ChecklistItems::class)
@php($columns = array_values(array_filter($items[0]['checks'] ?? [], fn ($check) => ! in_array($check['type'], ['measure_table', 'repeat_table', 'photo'], true))))
@php($number = fn ($value) => is_numeric($value) ? rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.') : $value)
<table class="grouped">
    <thead>
        <tr>
            <th class="center" style="width: 8mm">#</th>
            <th style="width: 30mm">{{ __('document.pm_sheet.equipment') }}</th>
            @foreach ($columns as $column)
                <th>{{ $column['label'] }}@if (isset($column['unit'])) ({{ $column['unit'] }})@endif</th>
            @endforeach
            <th style="width: 16mm">{{ __('document.pm_sheet.result') }}</th>
            <th style="width: 30mm">{{ __('document.pm.note') }}</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($items as $i => $item)
            @php($answers = collect($item['checks'])->pluck('answer', 'key'))
            <tr>
                <td class="center">{{ $i + 1 }}</td>
                <td><span style="font-family: monospace">{{ $item['asset']['asset_code'] ?? '-' }}</span><br>{{ $item['asset']['name'] ?? '' }}</td>
                @foreach ($columns as $column)
                    @php($value = $item['result'] === 'pending' ? null : ($answers[$column['key']] ?? null))
                    @php($red = $column['type'] === 'pass_fail' ? $value === 'fail' : (in_array($column['type'], ['number', 'measure'], true) && $schema::outOfRange($value, $column['min'] ?? null, $column['max'] ?? null)))
                    <td class="center" style="{{ $red ? 'color: #b91c1c; font-weight: 700' : '' }}">
                        @switch($column['type'])
                            @case('pass_fail')
                                {{ $value ? __('document.pm.'.$value) : '' }}
                                @break
                            @case('check')
                                {{ $value ? '✓' : '' }}
                                @break
                            @case('checkbox_group')
                                {{ is_array($value) ? implode(', ', $value) : '' }}
                                @break
                            @default
                                {{ $value === null ? '' : $number($value) }}
                        @endswitch
                    </td>
                @endforeach
                <td class="result-{{ $item['result'] }}">{{ __('ui.pm_visits.results.'.$item['result']) }}</td>
                <td class="pre">{{ collect([
                    $item['not_inspected_reason'] ?? null ? __('ui.pm_visits.not_inspected_reasons.'.$item['not_inspected_reason']) : null,
                    $item['note'] ?? null,
                ])->filter()->implode(' — ') }}</td>
            </tr>
        @endforeach
    </tbody>
</table>
