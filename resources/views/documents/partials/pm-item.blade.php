{{-- One piece of equipment of a PM report: what it is, every field of its template with the answer (pm-field), the photos. --}}
@php($date = fn ($value, $time = false) => \App\Modules\Document\Support\ThaiDate::format($value, $time))
@php($asset = $item['asset'])

<div class="pm-item">
    <h3 class="pm-item-title">
        <span style="font-family: monospace">{{ $asset['asset_code'] ?? '-' }}</span> {{ $asset['name'] ?? '' }}
        <span class="result result-{{ $item['result'] }}">{{ __('ui.pm_visits.results.'.$item['result']) }}</span>
    </h3>
    <div class="facts" style="margin-top: 4px">
        <div class="fact"><span class="label">{{ __('document.pm.category') }}</span><span class="value">{{ $asset['category'] ?? '' }}</span></div>
        <div class="fact"><span class="label">{{ __('document.pm.brand_model') }}</span><span class="value">{{ collect([$asset['brand'] ?? null, $asset['model'] ?? null])->filter()->implode(' / ') }}</span></div>
        <div class="fact"><span class="label">{{ __('document.pm.serial') }}</span><span class="value">{{ $asset['serial_number'] ?? '' }}</span></div>
        <div class="fact"><span class="label">{{ __('document.pm.location') }}</span><span class="value">{{ $asset['location'] ?? '' }}</span></div>
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
                            <tr><td colspan="4" style="background: #f8f8f8; font-weight: 600">{{ $section }}</td></tr>
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
    @if ($item['ticket_no'])
        <p style="margin: 2px 0 0" class="muted">{{ __('document.pm.ticket', ['no' => $item['ticket_no']]) }}</p>
    @endif
    @if ($item['checked_by'])
        <p style="margin: 2px 0 0; font-size: 9pt" class="muted">{{ __('document.pm.checked_by', ['name' => $item['checked_by'], 'at' => $date($item['checked_at'], true)]) }}</p>
    @endif

    @php($shown = array_values(array_filter($item['photos'] ?? [], fn ($photo) => isset($photos[$photo['id']]))))
    @if ($shown)
        @php($labels = collect($item['checks'])->pluck('label', 'key'))
        <div class="photos">
            @foreach ($shown as $photo)
                <figure>
                    <img src="{{ $photos[$photo['id']] }}" alt="">
                    @if ($photo['question'] || $photo['caption'])
                        <figcaption>{{ collect([$photo['question'] ? ($labels[$photo['question']] ?? null) : null, $photo['caption']])->filter()->implode(' — ') }}</figcaption>
                    @endif
                </figure>
            @endforeach
        </div>
    @endif
</div>
