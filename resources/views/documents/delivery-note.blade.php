@php($long = fn ($value) => \App\Modules\Document\Support\ThaiDate::long($value))
@extends('documents.layout')

@section('title', __('document.delivery.title').' '.$request->request_no)

@section('content')
    <div class="header" style="gap: 16px">
        <div style="display: flex; gap: 12px; align-items: center; min-width: 0">
            @if ($logo)
                <img src="{{ $logo }}" alt="" style="height: 16mm; max-width: 45mm; object-fit: contain">
            @endif
            <div>
                <div class="company">{{ $company['name'] ?? '' }}</div>
                @if ($company['service_phone'] ?? null)
                    <div class="muted">{{ __('ui.labels.service_phone', ['phone' => $company['service_phone']]) }}</div>
                @endif
            </div>
        </div>
        <div>
            <div class="doc-title" style="text-align: right">{{ __('document.delivery.title') }}</div>
            <div class="doc-no">{{ $request->request_no }}</div>
            <div class="muted" style="text-align: right">{{ __('document.checkout.date', ['date' => $long($deliveredAt)]) }}</div>
            @if ($revision > 0)
                <div style="text-align: right"><span class="revision">{{ __('document.serials.revision', ['n' => $revision]) }}</span></div>
            @endif
        </div>
    </div>

    <section>
        <h2>{{ __('document.delivery.ship_to') }}</h2>
        <div class="facts" style="margin-top: 4px">
            <div class="fact"><span class="label">{{ __('document.checkout.name') }}</span><span class="value">{{ $request->borrower_name }}</span></div>
            <div class="fact"><span class="label">{{ __('document.checkout.department') }}</span><span class="value">{{ $request->borrower_department }}</span></div>
            <div class="fact"><span class="label">{{ __('document.checkout.phone') }}</span><span class="value">{{ $request->borrower_phone }}</span></div>
            <div class="fact"><span class="label">{{ __('document.delivery.project') }}</span><span class="value">{{ $project ? $project['contract_no'].' '.$project['title'] : '' }}</span></div>
            <div class="fact" style="grid-column: span 2"><span class="label">{{ __('document.checkout.purpose') }}</span><span class="value">{{ $request->purpose }}</span></div>
        </div>
    </section>

    <section>
        <h2>{{ __('document.delivery.items') }}</h2>
        <table>
            <thead>
                <tr>
                    <th class="center" style="width: 50px">{{ __('document.no') }}</th>
                    <th style="width: 110px">{{ __('document.request.code') }}</th>
                    <th>{{ __('document.checkout.asset_name') }}</th>
                    <th style="width: 200px">{{ __('document.delivery.serials') }}</th>
                    <th class="right" style="width: 80px">{{ __('document.delivery.qty') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($items as $i => $item)
                    @php($pieces = $partSerials[$item->id] ?? null)
                    @php($info = $item->part_id ? ($partInfo[$item->part_id] ?? null) : null)
                    <tr class="{{ count($pieces['out'] ?? []) > 20 ? 'many-serials' : '' }}">
                        <td class="center">{{ $i + 1 }}</td>
                        <td style="font-family: monospace">{{ $item->item_code }}</td>
                        <td>
                            {{ $item->item_name }}
                            @if ($info && ($info['brand'] || $info['part_number']))
                                <div class="muted" style="font-size: 9pt">{{ trim($info['brand'].' '.$info['part_number']) }}</div>
                            @endif
                            @if ($purchases[$item->purchase_request_id] ?? null)
                                <div class="muted">{{ __('document.delivery.purchase') }} {{ $purchases[$item->purchase_request_id]['pr_no'] }}</div>
                            @endif
                        </td>
                        <td>
                            @if ($pieces)
                                @include('documents.partials.part-serials', ['serials' => $pieces])
                            @elseif ($serials[$item->asset_id] ?? null)
                                <div class="sn-list">
                                    @foreach ($serials[$item->asset_id] as $serial)
                                        <span class="sn">{{ $serial }}</span>
                                    @endforeach
                                </div>
                            @endif
                        </td>
                        <td class="right">{{ $item->qty_fulfilled }} {{ $item->unit }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        <p style="margin: 6px 0 0" class="muted">{{ __('document.delivery.total_items', ['count' => $items->count()]) }}</p>
        @php($backs = collect($partSerials)->flatMap(fn ($line) => $line['returned']))
        @if ($backs->isNotEmpty())
            <h2 style="margin-top: 10px">{{ __('document.serials.returned_title') }}</h2>
            <ul style="margin: 2px 0 0; padding-left: 18px; font-size: 9.5pt">
                @foreach ($backs as $back)
                    <li>{{ __('document.serials.returned_line', ['serial' => $back['serial'], 'date' => $long($back['at']), 'by' => $back['by'] ?? '-', 'reason' => $back['reason'] ?? '-']) }}</li>
                @endforeach
            </ul>
        @endif
        <p style="margin: 6px 0 0">{{ __('document.delivery.terms') }}</p>
    </section>

    <div class="signatures">
        <div>
            <div class="sign-line"></div>
            <div>( {{ $senders ?: str_repeat('.', 40) }} )</div>
            <div style="font-weight: 600">{{ __('document.delivery.sign_sender') }}</div>
            <div class="muted">{{ __('document.sign_date') }}</div>
        </div>
        <div>
            <div class="sign-line"></div>
            <div>( {{ $request->borrower_name }} )</div>
            <div style="font-weight: 600">{{ __('document.delivery.sign_receiver') }}</div>
            <div class="muted">{{ __('document.sign_date') }}</div>
        </div>
    </div>
@endsection
