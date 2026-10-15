{{-- Head of the PM sheet: company logo and name, the document and its round, number, version, tracking QR. --}}
<div class="head">
    <div style="display: flex; gap: 10px; align-items: flex-start">
        @if ($logo)
            <img src="{{ $logo }}" alt="" style="height: 16mm; max-width: 40mm; object-fit: contain">
        @endif
        <div>
            <div class="company">{{ $company }}</div>
            <div class="title">{{ __('document.pm_sheet.title') }}@if ($round) {{ __('document.pm_sheet.round', ['round' => $round]) }}@endif</div>
            @if ($customerCopy)
                <div class="muted">{{ __('document.pm_sheet.customer_copy') }}</div>
            @endif
        </div>
    </div>
    <div style="display: flex; gap: 6mm; align-items: flex-start">
        <div style="text-align: right">
            <div class="muted" style="font-size: 8.5pt">{{ __('document.pm_sheet.no') }}</div>
            <div class="doc-no">{{ $number }}</div>
            @if ($draft)
                <div class="draft-mark" style="display: inline-block; margin-top: 2px">{{ __('document.pm.draft') }}</div>
            @else
                <div class="revision">{{ __('document.pm.version', ['version' => $version]) }}</div>
            @endif
        </div>
        <div class="qr">{!! $qr !!}<div>{{ __('document.pm_sheet.track') }}</div></div>
    </div>
</div>
