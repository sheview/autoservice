{{-- The signature block at the foot of every page: technician, customer, approver; who signed, when, and the lock. --}}
@php($signatures = $report['signatures'] ?? [])
<div class="sign-block">
    @foreach (['technician' => [__('document.pm_sheet.engineer'), $visit['assignee'] ?? null], 'customer' => [__('document.pm_sheet.customer'), null], 'approver' => [__('document.pm_sheet.approver'), $visit['approved_by'] ?? null]] as $who => [$role, $fallback])
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
