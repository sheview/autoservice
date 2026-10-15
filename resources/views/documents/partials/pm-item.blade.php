{{-- One piece of equipment of a PM report: what it is, every checklist question with its outcome, the photos. --}}
@php($date = fn ($value, $time = false) => \App\Modules\Document\Support\ThaiDate::format($value, $time))
@php($asset = $item['asset'])
@php($outcome = function (array $check) use ($item) {
    $answer = $check['answer'];
    // Not checked yet (a draft): nothing to say about any question.
    if ($item['result'] === 'pending') {
        return __('document.pm.none');
    }
    return match ($check['type']) {
        'pass_fail' => $answer ? __('document.pm.'.$answer) : __('document.pm.none'),
        'check' => $answer ? __('document.pm.done') : __('document.pm.not_done'),
        default => $answer === null || $answer === '' ? __('document.pm.none') : (is_float($answer) || is_int($answer) ? rtrim(rtrim(number_format((float) $answer, 2, '.', ''), '0'), '.') : $answer).(isset($check['unit']) && $answer !== null && $answer !== '' ? ' '.$check['unit'] : ''),
    };
})
@php($criterion = fn (array $check) => $check['type'] !== 'measure' ? '' : match (true) {
    isset($check['min'], $check['max']) => $check['min'].' – '.$check['max'].' '.($check['unit'] ?? ''),
    isset($check['min']) => '≥ '.$check['min'].' '.($check['unit'] ?? ''),
    isset($check['max']) => '≤ '.$check['max'].' '.($check['unit'] ?? ''),
    default => '',
})
@php($verdictStyle = ['pass' => 'color: #166534', 'fail' => 'color: #b91c1c; font-weight: 700', 'na' => 'color: #555'])

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
                    <tr>
                        <td>{{ $check['label'] }}</td>
                        <td class="muted">{{ $criterion($check) }}</td>
                        <td style="{{ $verdictStyle[$check['verdict'] ?? ''] ?? '' }}">
                            {{ $outcome($check) }}@if (($check['verdict'] ?? null) === 'fail' && $check['type'] !== 'pass_fail') ({{ __('document.pm.fail') }})@endif
                        </td>
                        <td class="pre">{{ $check['remark'] }}</td>
                    </tr>
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
