{{-- One piece of equipment on its own page (per_asset template): what it is, every field (pm-field), photos. --}}
@php($asset = $item['asset'] ?? [])
<h3 style="font-size: 11pt; border-bottom: 1px solid #999; padding-bottom: 2px">
    <span style="font-family: monospace">{{ $asset['asset_code'] ?? '-' }}</span> {{ $asset['name'] ?? '' }}
    <span class="result-{{ $item['result'] }}" style="margin-left: 6px">{{ __('ui.pm_visits.results.'.$item['result']) }}</span>
</h3>
<div class="facts" style="margin-top: 4px">
    <div class="fact"><span class="label">{{ __('document.pm.brand_model') }}</span><span class="value">{{ collect([$asset['brand'] ?? null, $asset['model'] ?? null])->filter()->implode(' / ') }}</span></div>
    <div class="fact"><span class="label">{{ __('document.pm.serial') }}</span><span class="value">{{ $asset['serial_number'] ?? '' }}</span></div>
    <div class="fact"><span class="label">{{ __('document.pm.property_no') }}</span><span class="value">{{ $asset['property_no'] ?? '' }}</span></div>
    <div class="fact"><span class="label">{{ __('document.pm.location') }}</span><span class="value">{{ $asset['location'] ?? '' }}</span></div>
    <div class="fact"><span class="label">{{ __('document.pm_sheet.instrument') }}</span><span class="value">{{ isset($item['instrument']) ? $item['instrument']['name'].' · '.$item['instrument']['serial_no'] : '' }}</span></div>
    @if (($item['not_inspected_reason'] ?? null))
        <div class="fact"><span class="label">{{ __('document.pm_sheet.not_inspected_reason') }}</span><span class="value">{{ __('ui.pm_visits.not_inspected_reasons.'.$item['not_inspected_reason']) }}</span></div>
    @endif
</div>

@if ($item['checks'])
    <table style="margin-top: 6px">
        <thead>
            <tr>
                <th style="width: 40%">{{ __('document.pm.check') }}</th>
                <th style="width: 15%">{{ __('document.pm.criterion') }}</th>
                <th style="width: 15%">{{ __('document.pm.outcome') }}</th>
                <th>{{ __('document.pm.remark') }}</th>
            </tr>
        </thead>
        <tbody>
            @php($section = false)
            @foreach ($item['checks'] as $check)
                @if (($check['section'] ?? null) !== $section)
                    @php($section = $check['section'] ?? null)
                    @if ($section)
                        <tr><td colspan="4" style="background: #f3f3f3; font-weight: 600">{{ $section }}</td></tr>
                    @endif
                @endif
                @include('documents.partials.pm-field', ['check' => $check, 'pending' => $item['result'] === 'pending'])
            @endforeach
        </tbody>
    </table>
@endif

@if ($item['failures'])
    <p style="margin: 4px 0 0; color: #b91c1c"><strong>{{ __('document.pm.failures') }}:</strong> {{ implode(', ', $item['failures']) }}</p>
@endif
@if ($item['note'])
    <p class="pre" style="margin: 4px 0 0"><span class="muted">{{ __('document.pm.note') }}:</span> {{ $item['note'] }}</p>
@endif
@if ($item['ticket_no'] ?? null)
    <p style="margin: 2px 0 0" class="muted">{{ __('document.pm.ticket', ['no' => $item['ticket_no']]) }}</p>
@endif

@php($shown = array_values(array_filter($item['photos'] ?? [], fn ($photo) => isset($photos[$photo['id']]))))
@if ($shown)
    @php($labels = collect($item['checks'])->pluck('label', 'key'))
    <div class="photos">
        @foreach ($shown as $photo)
            <figure style="margin: 0; page-break-inside: avoid">
                <img src="{{ $photos[$photo['id']] }}" alt="">
                @if ($photo['question'] || $photo['caption'])
                    <figcaption style="font-size: 8.5pt; color: #555">{{ collect([$photo['question'] ? ($labels[$photo['question']] ?? null) : null, $photo['caption']])->filter()->implode(' — ') }}</figcaption>
                @endif
            </figure>
        @endforeach
    </div>
@endif
