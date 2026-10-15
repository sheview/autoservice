{{-- The cover of a round report: the work orders inside, with their results and signatures (pm-round-cover, pm-round-print). --}}
@php($date = fn ($value, $time = false) => \App\Modules\Document\Support\ThaiDate::format($value, $time))
<div class="header">
    <div style="display: flex; gap: 10px; align-items: flex-start">
        @if ($logo)
            <img src="{{ $logo }}" alt="" style="height: 16mm; max-width: 40mm; object-fit: contain">
        @endif
        <div>
            <div class="company">{{ $company }}</div>
            <div class="doc-title">{{ __('document.pm_round.title') }}</div>
        </div>
    </div>
</div>

<div class="facts">
    <div class="fact"><span class="label">{{ __('document.pm.customer') }}</span><span class="value">{{ $customer }}</span></div>
    <div class="fact"><span class="label">{{ __('document.pm_round.period') }}</span><span class="value">{{ $date($from) }} – {{ $date($to) }}</span></div>
</div>

<section>
    <h2>{{ __('document.pm_round.work_orders', ['count' => count($rows)]) }}</h2>
    <table>
        <thead>
            <tr>
                <th class="center" style="width: 8mm">#</th>
                <th style="width: 34mm">{{ __('document.pm_sheet.no') }}</th>
                <th>{{ __('document.pm.work_title') }}</th>
                <th style="width: 22mm">{{ __('document.pm.due_on') }}</th>
                <th class="center" style="width: 12mm">{{ __('document.pm_sheet.total') }}</th>
                <th class="center" style="width: 12mm">{{ __('document.pm_sheet.passed') }}</th>
                <th class="center" style="width: 14mm">{{ __('document.pm_sheet.failed') }}</th>
                <th class="center" style="width: 16mm">{{ __('document.pm_sheet.not_inspected') }}</th>
                <th class="center" style="width: 16mm">{{ __('document.pm_round.signed') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($rows as $i => $row)
                <tr>
                    <td class="center">{{ $i + 1 }}</td>
                    <td style="font-family: monospace">{{ $row['number'] }}</td>
                    <td>{{ $row['title'] }}</td>
                    <td>{{ $date($row['date']) }}</td>
                    <td class="center">{{ $row['total'] }}</td>
                    <td class="center">{{ $row['passed'] }}</td>
                    <td class="center" style="{{ $row['failed'] ? 'color: #b91c1c; font-weight: 700' : '' }}">{{ $row['failed'] }}</td>
                    <td class="center">{{ $row['not_inspected'] }}</td>
                    <td class="center">{{ $row['signed'] ? '✓' : '-' }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</section>
