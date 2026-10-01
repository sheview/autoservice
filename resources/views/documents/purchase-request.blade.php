@php($long = fn ($value) => \App\Modules\Document\Support\ThaiDate::long($value))
@php($money = fn ($baht) => $baht === null ? '-' : number_format((float) $baht, 2))
@extends('documents.layout')

@section('title', __('document.purchase.title').' '.$request->pr_no)

@section('content')
    <div class="header">
        <div style="display: flex; gap: 12px; align-items: center">
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
            <div class="doc-title" style="text-align: right">{{ __('document.purchase.title') }}</div>
            <div class="doc-no">{{ $request->pr_no }}</div>
            <div class="muted" style="text-align: right">{{ __('document.purchase.date', ['date' => $long($request->created_at)]) }}</div>
            <div class="muted" style="text-align: right">{{ __('ui.purchase_requests.statuses.'.$request->status) }}</div>
        </div>
    </div>

    <div class="facts">
        <div class="fact"><span class="label">{{ __('document.purchase.requested_by') }}</span><span class="value">{{ $request->requested_by_name }}</span></div>
        <div class="fact"><span class="label">{{ __('document.purchase.needed_by') }}</span><span class="value">{{ $request->needed_by ? $long($request->needed_by) : '' }}</span></div>
    </div>

    <section>
        <table>
            <thead>
                <tr>
                    <th class="center" style="width: 40px">{{ __('document.no') }}</th>
                    <th>{{ __('document.purchase.item') }}</th>
                    <th class="num" style="width: 90px">{{ __('document.purchase.quantity') }}</th>
                    <th class="num" style="width: 120px">{{ __('document.purchase.unit_price') }}</th>
                    <th class="num" style="width: 120px">{{ __('document.purchase.total') }}</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td class="center">1</td>
                    <td>
                        <div style="font-weight: 600">{{ $request->item_name }}</div>
                        @if ($request->description)
                            <div class="pre muted">{{ $request->description }}</div>
                        @endif
                    </td>
                    <td class="num">{{ number_format($request->quantity) }} {{ $request->unit }}</td>
                    <td class="num">{{ $money($row['unit_price']) }}</td>
                    <td class="num">{{ $money($row['total']) }}</td>
                </tr>
            </tbody>
        </table>
        @if ($row['unit_price'] !== null)
            <p class="muted" style="margin: 4px 0 0; font-size: 9pt">* {{ __('document.purchase.estimate_note') }}</p>
        @endif
    </section>

    @if ($request->reason)
        <section>
            <h2>{{ __('document.purchase.reason') }}</h2>
            <div class="box pre">{{ $request->reason }}</div>
        </section>
    @endif

    @if ($request->links)
        <section>
            <h2>{{ __('document.purchase.links') }}</h2>
            <ol style="margin: 0; padding-left: 20px; font-size: 9pt; word-break: break-all">
                @foreach ($request->links as $link)
                    <li>{{ $link }}</li>
                @endforeach
            </ol>
        </section>
    @endif

    <div class="signatures" style="grid-template-columns: repeat(3, 1fr); gap: 24px">
        <div>
            <div class="sign-line" style="margin: 0 8px"></div>
            <div>( {{ $request->requested_by_name ?? str_repeat('.', 36) }} )</div>
            <div style="font-weight: 600">{{ __('document.purchase.sign_requester') }}</div>
            <div class="muted">{{ __('document.sign_date') }}</div>
        </div>
        <div>
            <div class="sign-line" style="margin: 0 8px"></div>
            <div>( {{ $request->decided_by_name ?? str_repeat('.', 36) }} )</div>
            <div style="font-weight: 600">{{ __('document.purchase.sign_approver') }}</div>
            <div class="muted">{{ __('document.sign_date') }}</div>
        </div>
        <div>
            <div class="sign-line" style="margin: 0 8px"></div>
            <div>( {{ $request->ordered_by_name ?? str_repeat('.', 36) }} )</div>
            <div style="font-weight: 600">{{ __('document.purchase.sign_buyer') }}</div>
            <div class="muted">{{ __('document.sign_date') }}</div>
        </div>
    </div>
@endsection
