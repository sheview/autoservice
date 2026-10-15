{{-- The summary page of a round: customer, the work, counts by result, the equipment, and the blocks the templates turn on. --}}
@php($blocks = $blocks ?? [])
<div class="facts">
    {{-- Who and what: always, whether or not the template asks for the customer_info block. --}}
    <div class="fact"><span class="label">{{ __('document.pm.customer') }}</span><span class="value">{{ $visit['customer']['name'] ?? '' }}</span></div>
    <div class="fact"><span class="label">{{ __('document.pm.contract') }}</span><span class="value">{{ $visit['contract_no'] }}</span></div>
    <div class="fact"><span class="label">{{ __('document.pm.work_title') }}</span><span class="value">{{ $visit['title'] }}</span></div>
    <div class="fact"><span class="label">{{ __('document.pm.assignee') }}</span><span class="value">{{ $visit['assignee'] }}</span></div>
    <div class="fact"><span class="label">{{ __('document.pm.due_on') }}</span><span class="value">{{ $date($visit['scheduled_on'] ?? $visit['due_on']) }}</span></div>
    <div class="fact"><span class="label">{{ __('document.pm.completed_at') }}</span><span class="value">{{ $date($visit['completed_at'], true) }}</span></div>
    @if (in_array('case_details', $blocks, true))
        <div class="fact"><span class="label">{{ __('document.pm_sheet.service_type') }}</span><span class="value">☑ {{ __('document.pm_sheet.preventive') }}</span></div>
    @endif
</div>

<section>
    <h2>{{ __('document.pm_sheet.summary') }}</h2>
    <div class="counts">
        <div><b>{{ $totals['total'] }}</b>{{ __('document.pm_sheet.total') }}</div>
        @foreach (['passed', 'failed', 'not_inspected', 'pending'] as $result)
            <div class="result-{{ $result }}"><b>{{ $totals[$result] ?? 0 }}</b>{{ __('document.pm_sheet.'.$result) }}</div>
        @endforeach
    </div>
    @if ($visit['summary'])
        <div class="box pre" style="margin-top: 6px">{{ $visit['summary'] }}</div>
    @endif
</section>

<section>
    <h2>{{ __('document.pm_sheet.equipment_list') }}</h2>
    <table>
        <thead>
            <tr>
                <th class="center" style="width: 10mm">#</th>
                <th style="width: 40%">{{ __('document.pm_sheet.equipment') }}</th>
                <th style="width: 15%">{{ __('document.pm_sheet.result') }}</th>
                <th>{{ __('document.pm_sheet.failures') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($items as $i => $item)
                <tr>
                    <td class="center">{{ $i + 1 }}</td>
                    <td><span style="font-family: monospace">{{ $item['asset']['asset_code'] ?? '-' }}</span> {{ $item['asset']['name'] ?? '' }}</td>
                    <td class="result-{{ $item['result'] }}">{{ __('ui.pm_visits.results.'.$item['result']) }}</td>
                    <td>{{ implode(', ', $item['failures']) ?: '-' }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</section>

@if (in_array('parts_usage', $blocks, true))
    <section style="page-break-inside: avoid">
        <h2>{{ __('document.pm_sheet.parts_usage') }}</h2>
        <table>
            <thead>
                <tr><th colspan="2" class="center">{{ __('document.pm_sheet.removed_part') }}</th><th colspan="2" class="center">{{ __('document.pm_sheet.installed_part') }}</th><th rowspan="2">{{ __('document.pm_sheet.description') }}</th></tr>
                <tr><th>{{ __('document.pm_sheet.part_no') }}</th><th>{{ __('document.pm_sheet.serial_no') }}</th><th>{{ __('document.pm_sheet.part_no') }}</th><th>{{ __('document.pm_sheet.serial_no') }}</th></tr>
            </thead>
            <tbody>
                @for ($r = 0; $r < 2; $r++)
                    <tr><td>&nbsp;</td><td></td><td></td><td></td><td></td></tr>
                @endfor
            </tbody>
        </table>
    </section>
@endif

@if (in_array('response_time', $blocks, true))
    <section class="office-use" style="page-break-inside: avoid">
        <h2>{{ __('document.pm_sheet.response_time') }}</h2>
        @php($office = $visit['office_data'] ?? [])
        <table>
            <thead><tr><th></th><th>{{ __('document.pm_sheet.date_time') }}</th><th>{{ __('document.pm_sheet.travel') }}</th><th>{{ __('document.pm_sheet.ot') }}</th></tr></thead>
            <tbody>
                <tr><th>{{ __('document.pm_sheet.onsite') }}</th><td>{{ $date($visit['onsite_at'] ?? null, true) }}</td><td>{{ $office['travel'] ?? '' }}</td><td>{{ $office['ot_from'] ?? '' }}</td></tr>
                <tr><th>{{ __('document.pm_sheet.departure') }}</th><td>{{ $date($visit['departed_at'] ?? null, true) }}</td><td></td><td>{{ $office['ot_to'] ?? '' }}</td></tr>
            </tbody>
        </table>
    </section>
@endif

@if (in_array('satisfaction', $blocks, true))
    <section style="page-break-inside: avoid">
        <h2>{{ __('document.pm_sheet.satisfaction') }}</h2>
        <div>
            @foreach (__('document.pm_sheet.levels') as $level => $label)
                <span style="margin-right: 14px">{{ ($report['satisfaction']['level'] ?? null) === $level ? '☑' : '☐' }} {{ $label }}</span>
            @endforeach
        </div>
        @if ($report['satisfaction']['comment'] ?? null)
            <div class="pre muted">{{ $report['satisfaction']['comment'] }}</div>
        @endif
    </section>
@endif
