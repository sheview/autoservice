@php($long = fn ($value) => \App\Modules\Document\Support\ThaiDate::long($value))
@php($isLoan = $checkout->type === \App\Modules\Asset\Models\AssetCheckout::TYPE_LOAN)
@php($title = __('document.checkout.title_'.$checkout->type))
@extends('documents.layout')

@section('title', $title.' '.$checkout->checkout_no)

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
                @if ($company['service_email'] ?? null)
                    <div class="muted">{{ __('ui.labels.service_email', ['email' => $company['service_email']]) }}</div>
                @endif
            </div>
        </div>
        <div>
            <div class="doc-title" style="text-align: right">{{ $title }}</div>
            <div class="doc-no">{{ $checkout->checkout_no }}</div>
            <div class="muted" style="text-align: right">{{ __('document.checkout.date', ['date' => $long($checkout->decided_at ?? $checkout->created_at)]) }}</div>
        </div>
    </div>

    <section>
        <h2>{{ __('document.checkout.borrower_'.$checkout->type) }}</h2>
        <div class="facts" style="margin-top: 4px">
            <div class="fact"><span class="label">{{ __('document.checkout.name') }}</span><span class="value">{{ $checkout->borrower_name }}</span></div>
            <div class="fact"><span class="label">{{ __('document.checkout.department') }}</span><span class="value">{{ $checkout->borrower_department }}</span></div>
            <div class="fact"><span class="label">{{ __('document.checkout.phone') }}</span><span class="value">{{ $checkout->borrower_phone }}</span></div>
            <div class="fact">
                <span class="label">{{ __('document.checkout.due_on') }}</span>
                <span class="value">{{ $isLoan ? $long($checkout->due_on) : __('document.checkout.no_due') }}</span>
            </div>
            <div class="fact" style="grid-column: span 2"><span class="label">{{ __('document.checkout.purpose') }}</span><span class="value">{{ $checkout->purpose }}</span></div>
        </div>
    </section>

    <section>
        <h2>{{ __('document.checkout.asset') }}</h2>
        <table>
            <thead>
                <tr>
                    <th class="center" style="width: 50px">{{ __('document.no') }}</th>
                    <th style="width: 110px">{{ __('document.checkout.asset_code') }}</th>
                    <th>{{ __('document.checkout.asset_name') }}</th>
                    <th style="width: 150px">{{ __('document.checkout.serial') }}</th>
                    <th style="width: 120px">{{ __('document.checkout.property_no') }}</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td class="center">1</td>
                    <td style="font-family: monospace">{{ $asset['asset_code'] }}</td>
                    <td>
                        {{ $asset['name'] }}
                        @if ($asset['brand'] || $asset['model'])
                            <div class="muted">{{ collect([$asset['brand'], $asset['model']])->filter()->implode(' ') }}</div>
                        @endif
                        @if ($checkout->quantity > 1)
                            <div>{{ __('document.checkout.quantity', ['quantity' => $checkout->quantity, 'unit' => $asset['unit'] ?? '']) }}</div>
                        @endif
                    </td>
                    <td style="font-family: monospace">{{ $asset['serial_number'] ?? '-' }}</td>
                    <td>{{ $asset['property_no'] ?? '-' }}</td>
                </tr>
            </tbody>
        </table>
        <p style="margin: 8px 0 0">
            {{ __('document.checkout.condition') }} &nbsp; ☐ {{ __('document.checkout.condition_ok') }} &nbsp;&nbsp; ☐ {{ __('document.checkout.condition_other') }} ..............................................................
        </p>
        <p style="margin: 6px 0 0">{{ __('document.checkout.terms_'.$checkout->type, ['date' => $isLoan ? $long($checkout->due_on) : '']) }}</p>
    </section>

    <div class="signatures" style="grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 24px">
        <div>
            <div class="sign-line" style="margin: 0 8px"></div>
            <div>( {{ $checkout->borrower_name }} )</div>
            <div style="font-weight: 600">{{ __('document.checkout.sign_borrower_'.$checkout->type) }}</div>
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
            <div>( {{ $checkout->decided_by_name ?? str_repeat('.', 30) }} )</div>
            <div style="font-weight: 600">{{ __('document.checkout.sign_approver') }}</div>
            <div class="muted">{{ __('document.sign_date') }}</div>
        </div>
    </div>

    {{-- Filled in by hand when the asset comes back. --}}
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
@endsection
