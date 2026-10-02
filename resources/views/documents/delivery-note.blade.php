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
                    <th style="width: 160px">{{ __('document.delivery.serials') }}</th>
                    <th class="right" style="width: 80px">{{ __('document.delivery.qty') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($items as $i => $item)
                    <tr>
                        <td class="center">{{ $i + 1 }}</td>
                        <td style="font-family: monospace">{{ $item->item_code }}</td>
                        <td>
                            {{ $item->item_name }}
                            @if ($purchases[$item->purchase_request_id] ?? null)
                                <div class="muted">{{ __('document.delivery.purchase') }} {{ $purchases[$item->purchase_request_id]['pr_no'] }}</div>
                            @endif
                        </td>
                        <td style="font-family: monospace; font-size: 9pt">{{ implode(', ', $serials[$item->asset_id] ?? []) }}</td>
                        <td class="right">{{ $item->qty_fulfilled }} {{ $item->unit }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        <p style="margin: 6px 0 0" class="muted">{{ __('document.delivery.total_items', ['count' => $items->count()]) }}</p>
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
