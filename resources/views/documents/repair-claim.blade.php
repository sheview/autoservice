{{-- The repair fee claim form of an outside technician, filled in by hand (RepairClaimSheet). --}}
@php($dots = fn (string $width) => '<span class="blank" style="width: '.$width.'"></span>')
{{-- The printed date only, at the foot of the sheet (not the layout footer). --}}
@php($ownFooter = true)
@extends('documents.layout')

@section('title', __('document.repair_claim.title'))

@push('head')
    <style>
        /* One A4 page only: 10pt, tight spacing, nothing allowed to break onto a second page. */
        body { font-size: 10pt; line-height: 1.35; }
        .claim-head { display: flex; align-items: center; gap: 12px; }
        .claim-head .logo { width: 32mm; flex-shrink: 0; }
        .claim-head .logo img { max-height: 15mm; max-width: 32mm; object-fit: contain; }
        .claim-head h1 { flex: 1; text-align: center; font-size: 13pt; font-weight: 700; white-space: nowrap; }
        .claim-head .spacer { width: 32mm; flex-shrink: 1; }
        section { margin-top: 10px; }
        section h2 { font-size: 10.5pt; font-weight: 700; margin-bottom: 4px; }
        .claimant { border: 1px solid #000; }
        .claimant .line { display: flex; align-items: flex-end; gap: 6px; padding: 5px 8px 2px; border-bottom: 1px solid #000; white-space: nowrap; }
        .claimant .line:last-child { border-bottom: 0; }
        .fill { flex: 1; min-width: 0; border-bottom: 1px dotted #000; min-height: 1.4em; padding-left: 4px; white-space: normal; }
        .blank { display: inline-block; border-bottom: 1px dotted #000; height: 1.2em; vertical-align: bottom; }
        .tick { display: inline-block; width: 3.2mm; height: 3.2mm; border: 1px solid #000; vertical-align: -1px; margin-right: 4px; }
        .repairs th, .repairs td { border: 1px solid #000; }
        .repairs th { background: none; text-align: center; font-weight: 700; }
        .repairs th, .repairs td { padding: 3px 6px; }
        .repairs td { height: 7mm; }
        .repairs .total td { font-weight: 700; }
        .repairs .total .none { border: 0; }
        .docs { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1.4fr); gap: 4px 16px; }
        .terms { text-indent: 12mm; text-align: justify; margin: 0 0 6px; }
        .sign { width: 80mm; margin: 16px 0 0 auto; line-height: 2.2; page-break-inside: avoid; }
        /* Exactly one sheet: shorter than the printable height of A4 in both the PDF service (12.7 mm
           margins) and the browser (12 mm), cut off rather than spilling onto an empty second page;
           the printed date at the foot of the paper. */
        .sheet { display: flex; flex-direction: column; }
        .sheet-foot { margin-top: auto; text-align: right; font-size: 8.5pt; color: #666; }
        @media screen { .sheet { min-height: 268mm; } }
        /* On paper the content takes about 210 mm, so it fits A4 or Letter whatever the printer's
           paper or margins; the date is pinned to the foot of the page and takes no room of its own. */
        @media print {
            html, body { height: auto !important; }
            .sheet-foot { position: fixed; right: 0; bottom: 0; margin: 0; }
        }
    </style>
@endpush

@section('content')
    <div class="sheet">
    <div class="claim-head">
        <div class="logo">
            @if ($logo)
                <img src="{{ $logo }}" alt="">
            @else
                <div class="company">{{ $company }}</div>
            @endif
        </div>
        <h1>{{ __('document.repair_claim.title') }}</h1>
        <div class="spacer"></div>
    </div>

    <section>
        <h2>{{ __('document.repair_claim.part1') }}</h2>
        <div class="claimant">
            <div class="line">{{ __('document.repair_claim.name') }} :<span class="fill">{{ $claimant['name'] }}</span></div>
            <div class="line">
                {{ __('document.repair_claim.company') }}<span class="fill">{{ $claimant['company'] }}</span>
                {{ __('document.repair_claim.phone') }} :<span class="fill" style="flex: 0 0 48mm">{{ $claimant['phone'] }}</span>
            </div>
            <div class="line">{{ __('document.repair_claim.address') }} :<span class="fill"></span></div>
            <div class="line"><span class="fill"></span></div>
            <div class="line">
                {{ __('document.repair_claim.tax_id') }} :<span class="fill"></span>
                <span><span class="tick"></span>{{ __('document.repair_claim.head_office') }}</span>
                <span><span class="tick"></span>{{ __('document.repair_claim.branch') }}</span><span class="fill" style="flex: 0 0 30mm"></span>
            </div>
            <div class="line">
                {{ __('document.repair_claim.bank') }}<span class="fill"></span>
                {{ __('document.repair_claim.account_name') }}<span class="fill"></span>
                {{ __('document.repair_claim.account_no') }}<span class="fill"></span>
            </div>
        </div>
    </section>

    <section>
        <h2>{{ __('document.repair_claim.part2') }}</h2>
        <table class="repairs">
            <thead>
                <tr>
                    <th style="width: 26mm">{{ __('document.repair_claim.date') }}</th>
                    <th>{{ __('document.repair_claim.detail') }}</th>
                    <th style="width: 32mm">{{ __('document.repair_claim.province') }}</th>
                    <th style="width: 32mm">{{ __('document.repair_claim.amount') }}</th>
                </tr>
            </thead>
            <tbody>
                @for ($row = 0; $row < $rows; $row++)
                    <tr>
                        <td></td>
                        <td>{{ $row === 0 ? $ticket : '' }}</td>
                        <td></td>
                        <td></td>
                    </tr>
                @endfor
                <tr class="total">
                    <td class="none" colspan="2"></td>
                    <td class="center">{{ __('document.repair_claim.total') }}</td>
                    <td></td>
                </tr>
            </tbody>
        </table>
    </section>

    <section>
        <h2>{{ __('document.repair_claim.part3') }}</h2>
        <div class="docs">
            @foreach (__('document.repair_claim.documents') as $document)
                <div><span class="tick"></span>{{ $document }}</div>
            @endforeach
        </div>
    </section>

    <section>
        <h2>{{ __('document.repair_claim.part4') }}</h2>
        <p class="terms">{{ __('document.repair_claim.terms') }}</p>
        <p style="margin: 0"><strong>{{ __('document.repair_claim.remark_label') }}</strong> {{ __('document.repair_claim.remark') }}</p>
    </section>

    <div class="sign">
        <div>{{ __('document.repair_claim.sign') }}{!! $dots('48mm') !!}{{ __('document.repair_claim.claimant') }}</div>
        <div>({!! $dots('60mm') !!})</div>
        <div>{{ __('document.repair_claim.sign_date') }}{!! $dots('12mm') !!}/{!! $dots('22mm') !!}/{!! $dots('18mm') !!}</div>
    </div>

    <div class="sheet-foot">{{ __('document.printed_at', ['at' => \App\Modules\Document\Support\ThaiDate::format(now(), true)]) }}</div>
    </div>
@endsection
