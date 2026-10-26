{{-- The signature block at the foot of every page: technician, customer, approver; who signed, when, and the lock.
     The approver shows only once they signed (approving without a signature leaves them off the report). --}}
@php($signatures = $report['signatures'] ?? [])
@php($signers = array_filter(
    ['technician' => [__('document.pm_sheet.engineer'), $visit['assignee'] ?? null], 'customer' => [__('document.pm_sheet.customer'), null], 'approver' => [__('document.pm_sheet.approver'), $visit['approved_by'] ?? null]],
    fn (string $who) => $who !== 'approver' || ! empty($signatures['approver']['image']),
    ARRAY_FILTER_USE_KEY,
))
<div class="sign-block" style="grid-template-columns: repeat({{ count($signers) }}, minmax(0, 1fr))">
    @foreach ($signers as $who => [$role, $fallback])
        @php($signature = $signatures[$who] ?? null)
        <div>
            @if ($signature && ($signature['image'] ?? null))
                <img src="{{ $signature['image'] }}" alt="">
            @else
                <div class="line"></div>
            @endif
            <div>( {{ $signature['signer'] ?? $fallback ?? str_repeat('.', 30) }} )@if (! empty($signature['position'])) · {{ $signature['position'] }}@endif</div>
            <div style="font-weight: 600">{{ $role }}</div>
            <div class="muted">{{ $signature ? $date($signature['signed_at'] ?? null, true) : __('document.sign_date') }}</div>
        </div>
    @endforeach
    @if ($signatures['customer']['data_hash'] ?? null)
        <div class="muted" style="grid-column: 1 / -1; font-size: 7pt">
            {{ __('document.pm_sheet.locked', ['date' => $date($signatures['customer']['signed_at'] ?? null, true), 'hash' => substr($signatures['customer']['data_hash'], 0, 16)]) }}
        </div>
    @endif
</div>
