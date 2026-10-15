{{-- The PM inspection sheet itself (PmReportDocument data), in pm-sheet and in pm-round-print. --}}
@php($date = fn ($value, $time = false) => \App\Modules\Document\Support\ThaiDate::format($value, $time))
@php($visit = $report['visit'])
@php($totals = $report['totals'])
<div class="sheet-wrap">
    @if ($screen)
        <span class="screen-only draft-mark">{{ __('document.pm_sheet.status.'.($visit['status'] ?? 'new')) }}</span>
    @endif

    <table class="sheet">
        <tfoot><tr><td><div class="sign-space"></div></td></tr></tfoot>
        <tbody><tr><td>
            @include('documents.partials.pm-sheet-head')

            @unless ($oneItem)
                @include('documents.partials.pm-sheet-summary')
            @endunless

            @foreach ($sections as $s => $section)
                <div class="{{ $oneItem && $s === 0 ? '' : 'page' }}">
                    @if ($section['layout'] === 'grouped_table')
                        @include('documents.partials.pm-sheet-grouped', ['items' => $section['items']])
                    @else
                        @include('documents.partials.pm-sheet-asset', ['item' => $section['items'][0]])
                    @endif
                </div>
            @endforeach

            @if ($report['note'] ?? null)
                <p class="pre muted" style="margin-top: 10px; font-size: 9pt">{{ $report['note'] }}</p>
            @endif
        </td></tr></tbody>
    </table>

    @include('documents.partials.pm-sheet-signatures')
</div>
