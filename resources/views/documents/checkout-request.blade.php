@php($long = fn ($value) => \App\Modules\Document\Support\ThaiDate::long($value))
@php($loans = $items->where('checkout_type', 'loan'))
@extends('documents.layout')

@section('title', __('document.request.title').' '.$request->request_no)

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
            <div class="doc-title" style="text-align: right">{{ __('document.request.title') }}</div>
            <div class="doc-no">{{ $request->request_no }}</div>
            <div class="muted" style="text-align: right">{{ __('document.checkout.date', ['date' => $long($request->approved_at ?? $request->created_at)]) }}</div>
        </div>
    </div>

    <section>
        <h2>{{ __('document.request.receiver') }}</h2>
        <div class="facts" style="margin-top: 4px">
            <div class="fact"><span class="label">{{ __('document.checkout.name') }}</span><span class="value">{{ $request->borrower_name }}</span></div>
            <div class="fact"><span class="label">{{ __('document.checkout.department') }}</span><span class="value">{{ $request->borrower_department }}</span></div>
            <div class="fact"><span class="label">{{ __('document.checkout.phone') }}</span><span class="value">{{ $request->borrower_phone }}</span></div>
            <div class="fact"><span class="label">{{ __('document.request.needed_by') }}</span><span class="value">{{ $request->needed_by ? $long($request->needed_by) : '' }}</span></div>
            <div class="fact" style="grid-column: span 2"><span class="label">{{ __('document.checkout.purpose') }}</span><span class="value">{{ $request->purpose }}</span></div>
        </div>
    </section>

    <section>
        <h2>{{ __('document.request.items') }}</h2>
        <table>
            <thead>
                <tr>
                    <th class="center" style="width: 50px">{{ __('document.no') }}</th>
                    <th style="width: 110px">{{ __('document.request.code') }}</th>
                    <th>{{ __('document.checkout.asset_name') }}</th>
                    <th style="width: 80px">{{ __('document.request.kind') }}</th>
                    <th class="right" style="width: 70px">{{ __('document.request.approved') }}</th>
                    <th class="right" style="width: 70px">{{ __('document.request.handed') }}</th>
                    <th style="width: 100px">{{ __('document.checkout.due_on') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($items as $i => $item)
                    <tr>
                        <td class="center">{{ $i + 1 }}</td>
                        <td style="font-family: monospace">{{ $item->item_code }}</td>
                        <td>{{ $item->item_name }}</td>
                        <td>{{ __('document.request.kinds.'.$item->item_type.'_'.$item->checkout_type) }}</td>
                        <td class="right">{{ $item->qty_approved ?? $item->qty_requested }} {{ $item->unit }}</td>
                        <td class="right">{{ $item->qty_fulfilled }}</td>
                        <td>{{ $item->due_return_date ? $long($item->due_return_date) : '-' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        <p style="margin: 6px 0 0">{{ __($loans->isNotEmpty() ? 'document.request.terms_loan' : 'document.request.terms_issue') }}</p>
    </section>

    <div class="signatures" style="grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 24px">
        <div>
            <div class="sign-line" style="margin: 0 8px"></div>
            <div>( {{ $request->borrower_name }} )</div>
            <div style="font-weight: 600">{{ __('document.request.sign_receiver') }}</div>
            <div class="muted">{{ __('document.sign_date') }}</div>
        </div>
        <div>
            <div class="sign-line" style="margin: 0 8px"></div>
            <div>( {{ str_repeat('.', 30) }} )</div>
            <div style="font-weight: 600">{{ __('document.checkout.sign_handover') }}</div>
            <div class="muted">{{ __('document.sign_date') }}</div>
        </div>
        <div>
            <div class="sign-line" style="margin: 0 8px"></div>
            <div>( {{ $request->approved_by_name ?? str_repeat('.', 30) }} )</div>
            <div style="font-weight: 600">{{ __('document.checkout.sign_approver') }}</div>
            <div class="muted">{{ __('document.sign_date') }}</div>
        </div>
    </div>

    @if ($loans->isNotEmpty())
        {{-- Filled in by hand when lent assets come back. --}}
        <section style="margin-top: 26px; page-break-inside: avoid">
            <h2>{{ __('document.checkout.return_title') }}</h2>
            <div class="box">
                <p style="margin: 2px 0">{{ __('document.checkout.return_date') }} .............................................. &nbsp;
                    {{ __('document.checkout.condition') }} &nbsp; ☐ {{ __('document.checkout.condition_ok') }} &nbsp; ☐ {{ __('document.checkout.condition_other') }} ..............................</p>
                <div class="signatures" style="margin-top: 18px">
                    <div>
                        <div class="sign-line"></div>
                        <div>( {{ str_repeat('.', 40) }} )</div>
                        <div style="font-weight: 600">{{ __('document.checkout.sign_returner') }}</div>
                    </div>
                    <div>
                        <div class="sign-line"></div>
                        <div>( {{ str_repeat('.', 40) }} )</div>
                        <div style="font-weight: 600">{{ __('document.checkout.sign_receiver') }}</div>
                    </div>
                </div>
            </div>
        </section>
    @endif
@endsection
