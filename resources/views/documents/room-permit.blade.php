@php($long = fn ($value) => \App\Modules\Document\Support\ThaiDate::format($value, true))
@extends('documents.layout')

@section('title', __('document.room_permit.title').' '.$request['request_no'])

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
        <div style="display: flex; gap: 10px; align-items: flex-start">
            <div>
                <div class="doc-title" style="text-align: right">{{ __('document.room_permit.title') }}</div>
                <div class="doc-no">{{ $request['request_no'] }}</div>
                <div class="muted" style="text-align: right">{{ __('document.room_permit.status.'.$request['status']) }}</div>
            </div>
            @if ($qr)
                <div style="width: 26mm; text-align: center">
                    <div style="width: 26mm; height: 26mm">{!! $qr !!}</div>
                    <div class="muted" style="font-size: 7.5pt">{{ __('document.room_permit.qr_note') }}</div>
                </div>
            @endif
        </div>
    </div>

    <section>
        <div class="facts" style="margin-top: 4px">
            <div class="fact"><span class="label">{{ __('document.room_permit.customer') }}</span><span class="value">{{ $customer }}</span></div>
            <div class="fact"><span class="label">{{ __('document.room_permit.site') }}</span><span class="value">{{ $room['site'] }}</span></div>
            <div class="fact"><span class="label">{{ __('document.room_permit.room') }}</span><span class="value">{{ $room['name'] }}</span></div>
            <div class="fact"><span class="label">{{ __('document.room_permit.location') }}</span><span class="value">{{ $room['location'] }}</span></div>
            <div class="fact" style="grid-column: span 2"><span class="label">{{ __('document.room_permit.period') }}</span>
                <span class="value">{{ $long($request['planned_start']) }} – {{ $long($request['planned_end']) }}@if ($request['schedule'] ?? null) ({{ __('ui.room_requests.schedule_every', ['schedule' => $request['schedule']]) }})@endif</span></div>
            <div class="fact"><span class="label">{{ __('document.room_permit.requester') }}</span><span class="value">{{ $request['requester_name'] }}</span></div>
            <div class="fact"><span class="label">{{ __('document.room_permit.approved_by') }}</span>
                <span class="value">{{ $approval ? $approval['name'].' · '.$long($approval['at']) : '' }}</span></div>
            <div class="fact" style="grid-column: span 2"><span class="label">{{ __('document.room_permit.purpose') }}</span><span class="value">{{ $request['purpose'] }}</span></div>
            <div class="fact"><span class="label">{{ __('document.room_permit.ticket') }}</span><span class="value">{{ $ticket }}</span></div>
            <div class="fact"><span class="label">{{ __('document.room_permit.contract') }}</span><span class="value">{{ $contract }}</span></div>
        </div>
    </section>

    <section>
        <h2>{{ __('document.room_permit.people') }}</h2>
        <table>
            <thead>
                <tr>
                    <th class="center" style="width: 40px">{{ __('document.no') }}</th>
                    <th>{{ __('document.room_permit.name') }}</th>
                    <th style="width: 150px">{{ __('document.room_permit.person_company') }}</th>
                    <th style="width: 110px">{{ __('document.room_permit.phone') }}</th>
                    @if ($show_ids)
                        <th style="width: 120px">{{ __('document.room_permit.id_number') }}</th>
                    @endif
                </tr>
            </thead>
            <tbody>
                @foreach ($people as $i => $person)
                    <tr>
                        <td class="center">{{ $i + 1 }}</td>
                        <td>{{ $person['name'] }}</td>
                        <td>{{ $person['company'] }}</td>
                        <td>{{ $person['phone'] }}</td>
                        @if ($show_ids)
                            <td style="font-family: monospace">{{ $person['id_number'] }}</td>
                        @endif
                    </tr>
                @endforeach
            </tbody>
        </table>
    </section>

    <section>
        <h2>{{ __('document.room_permit.items') }}</h2>
        @if ($items === [])
            <p class="muted" style="margin: 0">{{ __('document.room_permit.no_items') }}</p>
        @else
            <table>
                <thead>
                    <tr>
                        <th class="center" style="width: 40px">{{ __('document.no') }}</th>
                        <th>{{ __('document.room_permit.item') }}</th>
                        <th style="width: 150px">{{ __('document.room_permit.serial') }}</th>
                        <th class="num" style="width: 60px">{{ __('document.room_permit.qty') }}</th>
                        <th style="width: 120px">{{ __('document.room_permit.direction') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($items as $i => $item)
                        <tr>
                            <td class="center">{{ $i + 1 }}</td>
                            <td>{{ $item['name'] }}</td>
                            <td style="font-family: monospace">{{ $item['serial_number'] }}</td>
                            <td class="num">{{ $item['quantity'] }}</td>
                            <td>{{ __('document.room_permit.directions.'.$item['direction']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </section>

    <section style="page-break-inside: avoid">
        <h2>{{ __('document.room_permit.acceptance') }}</h2>
        <div class="box">
            @if ($acceptance)
                <div style="font-weight: 600">
                    {{ $acceptance['version']
                        ? __('document.room_permit.accepted', ['customer' => $acceptance['customer'], 'room' => $acceptance['room'], 'version' => $acceptance['version'], 'at' => $long($acceptance['at']), 'name' => $acceptance['name']])
                        : __('document.room_permit.accepted_company', ['at' => $long($acceptance['at']), 'name' => $acceptance['name']]) }}
                </div>
                @if ($acceptance['summary'])
                    <ol style="margin: 4px 0 0; padding-left: 20px; font-size: 9.5pt">
                        @foreach ($acceptance['summary'] as $line)
                            <li>{{ $line }}</li>
                        @endforeach
                    </ol>
                @endif
                @if ($acceptance['company_terms'])
                    <ol style="margin: 4px 0 0; padding-left: 20px; font-size: 9pt" class="muted" start="{{ count($acceptance['summary']) + 1 }}">
                        @foreach ($acceptance['company_terms'] as $line)
                            <li>{{ $line }}</li>
                        @endforeach
                    </ol>
                @endif
                @if ($acceptance['team'])
                    <div class="muted" style="margin-top: 4px; font-size: 9pt">{{ __('document.room_permit.team') }}</div>
                @endif
            @else
                <span class="muted">-</span>
            @endif
        </div>
    </section>

    <div class="signatures" style="grid-template-columns: repeat(2, minmax(0, 1fr))">
        <div>
            <div class="sign-line"></div>
            <div>( {{ $request['requester_name'] }} )</div>
            <div style="font-weight: 600">{{ __('document.room_permit.sign_requester') }}</div>
        </div>
        <div>
            <div class="sign-line"></div>
            <div>( {{ str_repeat('.', 40) }} )</div>
            <div style="font-weight: 600">{{ __('document.room_permit.sign_guard') }}</div>
            <div class="muted">{{ __('document.room_permit.time_in_out') }}</div>
        </div>
    </div>
@endsection
