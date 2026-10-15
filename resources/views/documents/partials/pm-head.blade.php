{{-- Head of both PM reports: the company, the document, its version (or draft) and the work order. --}}
@php($date = fn ($value, $time = false) => \App\Modules\Document\Support\ThaiDate::format($value, $time))
@php($visit = $report['visit'])
<div class="header">
    <div style="display: flex; gap: 10px; align-items: flex-start">
        @if ($logo)
            <img src="{{ $logo }}" alt="" style="height: 16mm; max-width: 40mm; object-fit: contain">
        @endif
        <div>
            <div class="company">{{ $company }}</div>
            <div class="doc-title">{{ $title }}</div>
        </div>
    </div>
    <div style="text-align: right">
        <div class="doc-no">{{ $visit['visit_no'] }}</div>
        @if ($draft)
            <div class="revision" style="border-color: #b91c1c; color: #b91c1c">{{ __('document.pm.draft') }}</div>
        @else
            <div class="revision">{{ __('document.pm.version', ['version' => $version]) }}</div>
            <div class="muted" style="font-size: 9pt">{{ __('document.pm.approved_on', ['date' => $date($approvedAt, true)]) }}</div>
        @endif
    </div>
</div>

<div class="facts">
    <div class="fact"><span class="label">{{ __('document.pm.customer') }}</span><span class="value">{{ $visit['customer']['name'] ?? '' }}</span></div>
    <div class="fact"><span class="label">{{ __('document.pm.contract') }}</span><span class="value">{{ $visit['contract_no'] }}</span></div>
    <div class="fact"><span class="label">{{ __('document.pm.work_title') }}</span><span class="value">{{ $visit['title'] }}@if ($visit['round']) ({{ __('document.pm.round') }} {{ $visit['round'] }})@endif</span></div>
    <div class="fact"><span class="label">{{ __('document.pm.assignee') }}</span><span class="value">{{ $visit['assignee'] }}</span></div>
    <div class="fact"><span class="label">{{ __('document.pm.due_on') }}</span><span class="value">{{ $visit['period_starts_on'] ? $date($visit['period_starts_on']).' – ' : '' }}{{ $date($visit['due_on']) }}</span></div>
    <div class="fact"><span class="label">{{ __('document.pm.completed_at') }}</span><span class="value">{{ $date($visit['completed_at'], true) }}</span></div>
</div>
