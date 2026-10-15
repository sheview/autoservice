{{--
    The PM inspection sheet (PmReportDocument): the PDF and the "view" page in the browser are this
    same HTML. A4 portrait. A summary page, then each piece of equipment on its own page (per_asset)
    or a table of rows (grouped_table). The signature block is at the foot of every page: it is fixed
    to the bottom, which Chromium prints on each page, over a footer row of the page table that keeps
    the space free. The status badge is for the screen only.
--}}
@extends('documents.layout')

@section('title', __('document.pm_sheet.title').' '.$number)

@push('head')
    <style>
        body { font-size: 10pt; }
        .footer { display: none; }
        .sheet { width: 100%; border: 0; table-layout: auto; }
        .sheet > thead > tr > td, .sheet > tfoot > tr > td, .sheet > tbody > tr > td { border: 0; padding: 0; }
        .sign-space { height: 34mm; }
        .sign-block { position: fixed; bottom: 0; left: 0; right: 0; height: 32mm; background: #fff; border-top: 1px solid #000; padding-top: 2mm;
            display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 6mm; text-align: center; font-size: 8.5pt; }
        .sign-block img { height: 13mm; max-width: 50mm; object-fit: contain; display: block; margin: 0 auto; }
        .sign-block .line { border-bottom: 1px solid #000; height: 13mm; margin: 0 6mm; }
        .page { page-break-before: always; }
        .head { display: flex; justify-content: space-between; gap: 8mm; align-items: flex-start; border-bottom: 2px solid #000; padding-bottom: 6px; }
        .head .qr { width: 22mm; text-align: center; font-size: 7pt; color: #555; }
        .head .qr svg { width: 22mm; height: 22mm; }
        .title { font-size: 12.5pt; font-weight: 700; }
        .counts { display: grid; grid-template-columns: repeat(5, minmax(0, 1fr)); gap: 4px; text-align: center; }
        .counts div { border: 1px solid #999; padding: 4px; }
        .counts b { display: block; font-size: 14pt; }
        .result-passed { color: #166534; font-weight: 600; }
        .result-failed { color: #b91c1c; font-weight: 700; }
        .result-not_inspected, .result-pending { color: #555; }
        .pm-grid { width: 100%; margin: 3px 0; border-collapse: collapse; }
        .pm-grid th, .pm-grid td { border: 1px solid #ccc; padding: 1px 4px; font-size: 9pt; text-align: center; }
        .pm-grid td:first-child { text-align: left; }
        .grouped th, .grouped td { font-size: 8.5pt; padding: 2px 3px; }
        .draft-mark { color: #b91c1c; border: 1px solid #b91c1c; padding: 0 6px; font-weight: 700; }
        .photos { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 6px; margin-top: 6px; }
        .photos img { width: 100%; height: 40mm; object-fit: cover; border: 1px solid #ccc; }
        .screen-only { position: absolute; top: 4mm; right: 4mm; }
        @media print { .screen-only { display: none; } }
        @media screen { body { background: #e5e5e5; } .sheet-wrap { background: #fff; padding: 12mm 14mm; box-shadow: 0 0 6px rgba(0,0,0,.2); position: relative; }
            .sign-block { position: static; margin-top: 8mm; } .sign-space { display: none; } .page { margin-top: 10mm; border-top: 2px dashed #bbb; padding-top: 10mm; } }
    </style>
@endpush

@section('content')
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
@endsection
