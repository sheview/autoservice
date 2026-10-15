{{-- End of both PM reports: the signatures drawn on hand-in, who checked and approved it, the company's note. --}}
@php($date = fn ($value, $time = false) => \App\Modules\Document\Support\ThaiDate::format($value, $time))
@php($visit = $report['visit'])
@php($signatures = $report['signatures'] ?? [])

<div class="signatures">
    @foreach (['technician' => [__('document.pm.technician_sign'), $visit['assignee']], 'customer' => [__('document.pm.customer_sign'), null]] as $who => [$role, $fallback])
        @php($signature = $signatures[$who] ?? null)
        <div>
            @if ($signature)
                <img src="{{ $signature['image'] }}" alt="" style="height: 16mm; max-width: 60mm; object-fit: contain; display: block; margin: 0 auto -4px">
            @endif
            <div class="sign-line" style="{{ $signature ? 'height: 2px' : '' }}"></div>
            <div>( {{ $signature['signer'] ?? $fallback ?? str_repeat('.', 40) }} )</div>
            @if (! empty($signature['position']))
                <div class="muted">{{ __('document.pm.position', ['position' => $signature['position']]) }}</div>
            @endif
            <div style="font-weight: 600">{{ $role }}</div>
            <div class="muted">{{ $signature ? __('document.pm.signed_on', ['date' => $date($signature['signed_at'], true)]) : __('document.sign_date') }}</div>
        </div>
    @endforeach
</div>

@unless ($draft)
    <section style="page-break-inside: avoid">
        <h2>{{ __('document.pm.approval') }}</h2>
        <table>
            <tbody>
                <tr>
                    <th style="width: 25%">{{ __('document.pm.submitted') }}</th><td>{{ $visit['submitted_by'] ?? '-' }} · {{ $date($visit['completed_at'], true) }}</td>
                </tr>
                @if ($visit['reviewed_by'] ?? null)
                    <tr><th>{{ __('document.pm.reviewed') }}</th><td>{{ $visit['reviewed_by'] }} · {{ $date($visit['reviewed_at'], true) }}</td></tr>
                @endif
                <tr><th>{{ __('document.pm.approved') }}</th><td>{{ $visit['approved_by'] ?? '-' }} · {{ $date($visit['approved_at'], true) }}</td></tr>
            </tbody>
        </table>
    </section>
@endunless

@if ($report['note'] ?? null)
    <p class="pre muted" style="margin-top: 10px; font-size: 9pt">{{ $report['note'] }}</p>
@endif
