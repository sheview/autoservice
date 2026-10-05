@php($date = fn ($value, $time = true) => \App\Modules\Document\Support\ThaiDate::format($value, $time))
@php($device = $ticket['device'])
@php($warranty = $ticket['warranty'])
@php($report = $ticket['report'])
@php($blank = str_repeat('.', 40))
{{-- Same layout as resources/js/pages/Service/Tickets/Print.vue (printed from the browser): change both together. --}}
@extends('documents.layout')

@section('title', __('ui.ticket_print.title').' '.$ticket['ticket_no'])

@section('content')
    <style>
        body { font-size: 10pt; line-height: 1.35; }
        .sheet section { margin-top: 8px; }
        .sheet h2 { font-size: 10pt; border-bottom: 1px solid #000; padding-bottom: 1px; margin-bottom: 4px; }
        .sheet .times { margin-top: 8px; }
        .sheet .facts { margin-top: 0; row-gap: 2px; }
        .sheet .fact .label { width: 110px; }
        .sheet .box { padding: 3px 8px; }
        .sheet th, .sheet td { padding: 2px 6px; }
        .sheet .signatures { margin-top: 10px; gap: 40px; }
        .sign-row { display: flex; align-items: flex-end; gap: 6px; height: 26px; }
        .sign-row .sign-line { flex: 1; height: auto; margin: 0; border-bottom: 1px dotted #000; }
        .caption { font-weight: 600; margin-bottom: 2px; }
        .checkbox { display: inline-block; width: 11px; height: 11px; border: 1px solid #000; text-align: center; line-height: 10px; font-size: 8.5pt; vertical-align: -1px; }
        .rating-grid { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); gap: 1px 20px; font-size: 9.5pt; }
        .footer { margin-top: 10px; }
    </style>

    <div class="sheet">
        <div class="header">
            <div style="display: flex; gap: 10px; align-items: flex-start">
                @if ($logo)
                    <img src="{{ $logo }}" alt="" style="height: 16mm; max-width: 40mm; object-fit: contain">
                @endif
                <div>
                <div class="company">{{ $company }}</div>
                <div class="doc-title">{{ __('ui.ticket_print.title') }}</div>
                </div>
            </div>
            <div style="display: flex; gap: 10px; align-items: flex-start">
                <div>
                    <div class="doc-no">{{ $ticket['ticket_no'] }}</div>
                    <div class="muted" style="text-align: right">{{ __('ui.tickets.statuses.'.$ticket['status']) }}</div>
                </div>
                @if ($tracking)
                    {{-- At least 2.5 cm: it scans from small paper, printed in black and white. --}}
                    <div style="width: 28mm; text-align: center">
                        <div style="width: 28mm; height: 28mm">{!! $tracking['qr'] !!}</div>
                        <div style="font-size: 7pt; line-height: 1.2">{{ __('ui.ticket_print.track_scan') }}<br>{{ $tracking['search'] }}</div>
                    </div>
                @endif
            </div>
        </div>

        <div class="times">
            @foreach (['opened_at' => 'created_at', 'responded_at' => 'responded_at', 'resolved_at' => 'resolved_at', 'closed_at' => 'closed_at'] as $label => $field)
                <div class="box">
                    <div class="muted">{{ __('ui.ticket_print.'.$label) }}</div>
                    <div>{{ $date($ticket[$field]) }}</div>
                </div>
            @endforeach
        </div>

        {{-- Who asked for the service, and how the job is handled --}}
        <section>
            <h2>{{ __('ui.ticket_print.requester') }}</h2>
            <div class="facts">
                <div class="fact"><span class="label">{{ __('ui.ticket_print.contact_name') }}</span><span class="value">{{ $ticket['contact_name'] }}</span></div>
                <div class="fact"><span class="label">{{ __('ui.ticket_print.contact_phone') }}</span><span class="value">{{ $ticket['contact_phone'] }}</span></div>
                <div class="fact"><span class="label">{{ __('ui.ticket_print.organization') }}</span><span class="value">{{ collect([$ticket['customer'], $ticket['department']])->filter()->implode(' · ') }}</span></div>
                <div class="fact"><span class="label">{{ __('document.ticket.branch') }}</span><span class="value">{{ $ticket['branch'] }}</span></div>
                <div class="fact"><span class="label">{{ __('document.ticket.contract') }}</span><span class="value">{{ $ticket['contract_no'] ?? __('document.ticket.out_of_contract') }}</span></div>
                <div class="fact"><span class="label">{{ __('document.ticket.source') }}</span><span class="value">{{ __('ui.tickets.sources.'.$ticket['source']) }}</span></div>
                <div class="fact"><span class="label">{{ __('document.ticket.priority') }}</span><span class="value">{{ __('ui.tickets.priorities.'.$ticket['priority']) }}</span></div>
                <div class="fact"><span class="label">{{ __('document.ticket.assignee') }}</span><span class="value">{{ $ticket['assignee'] }}</span></div>
            </div>
        </section>

        {{-- The device: from the asset register, or as told when it is not registered --}}
        <section>
            <h2>
                {{ __('ui.ticket_print.device') }}
                <span class="muted" style="font-weight: normal; font-size: 9pt">({{ __($device['registered'] ? 'ui.tickets.device_registered_badge' : 'ui.tickets.device_unregistered_badge') }})</span>
            </h2>
            <div class="facts">
                <div class="fact"><span class="label">{{ __('ui.tickets.device_name') }}</span><span class="value">{{ $ticket['asset'] ? $ticket['asset']['asset_code'].' '.$ticket['asset']['name'] : $device['name'] }}</span></div>
                <div class="fact"><span class="label">{{ __('ui.tickets.device_brand') }} / {{ __('ui.tickets.device_model') }}</span><span class="value">{{ collect([$device['brand'], $device['model']])->filter()->implode(' / ') }}</span></div>
                <div class="fact"><span class="label">{{ __('ui.ticket_print.serial') }}</span><span class="value">{{ $device['serial_unknown'] ? __('ui.tickets.device_serial_unknown') : $device['serial'] }}</span></div>
                <div class="fact"><span class="label">{{ __('ui.tickets.property_no') }}</span><span class="value">{{ $device['property_no'] }}</span></div>
                <div class="fact"><span class="label">{{ __('ui.ticket_print.location') }}</span><span class="value">{{ $device['location'] }}</span></div>
                <div class="fact"><span class="label">{{ __('ui.ticket_print.ip_address') }}</span><span class="value">{{ $device['ip'] }}</span></div>
                <div class="fact" style="grid-column: span 2">
                    <span class="label">{{ __('ui.ticket_print.warranty') }}</span>
                    <span class="value">
                        @if ($warranty['status'])
                            {{ __('ui.tickets.warranty_statuses.'.$warranty['status']) }}@if ($warranty['expires_on']) ({{ __('ui.ticket_print.warranty_until', ['date' => $date($warranty['expires_on'], false)]) }})@endif
                        @else
                            <span class="checkbox"></span> {{ __('ui.tickets.warranty_statuses.in_warranty') }}
                            &nbsp; <span class="checkbox"></span> {{ __('ui.tickets.warranty_statuses.out_of_warranty') }}
                        @endif
                    </span>
                </div>
            </div>
        </section>

        <section>
            <h2>{{ __('ui.ticket_print.problem') }}</h2>
            <div class="box" style="min-height: 15mm">
                <div style="font-weight: 600">{{ $ticket['title'] }}</div>
                @if ($ticket['description'])
                    <div class="pre">{{ $ticket['description'] }}</div>
                @endif
            </div>
        </section>

        {{-- The customer's side asks for the repair and approves it --}}
        <div class="signatures">
            @foreach ([[__('ui.ticket_print.reporter_sign'), $ticket['contact_name']], [__('ui.ticket_print.approver_sign'), $report['approver_name']]] as [$role, $name])
                <div>
                    <div class="sign-row"><span>{{ __('ui.ticket_print.sign') }}</span><span class="sign-line"></span><span>{{ $role }}</span></div>
                    <div>( {{ $name ?? $blank }} )</div>
                    <div class="muted">{{ __('document.sign_date') }}</div>
                </div>
            @endforeach
        </div>

        {{-- What the technician found and did --}}
        <section>
            <h2>{{ __('ui.ticket_print.staff_section') }}</h2>
            <div class="fact"><span class="label">{{ __('ui.ticket_print.cause') }}</span><span class="value pre">{{ $report['cause'] }}</span></div>
            <div class="muted" style="margin-top: 4px">{{ __('ui.ticket_print.work_done') }}</div>
            <div class="box" style="min-height: 26mm">
                @foreach ($notes as $note)
                    <div style="margin-bottom: 3px">
                        <span class="muted" style="font-size: 9pt">{{ $date($note['at']) }} · {{ $note['user_name'] ?? __('ui.common.system') }}</span>
                        <div class="pre">{{ $note['body'] }}</div>
                    </div>
                @endforeach
            </div>
            <div class="fact" style="margin-top: 4px; width: 50%">
                <span class="label">{{ __('ui.ticket_print.extra_cost') }}</span><span class="value" style="text-align: right; padding-right: 6px">{{ $report['extra_cost'] !== null ? number_format((float) $report['extra_cost'], 2) : '' }}</span><span>{{ __('ui.ticket_print.baht') }}</span>
            </div>
        </section>

        <section>
            <h2>{{ __('ui.ticket_print.parts') }}</h2>
            <table>
                <thead>
                    <tr>
                        <th class="center" style="width: 50px">{{ __('document.no') }}</th>
                        <th style="width: 100px">{{ __('ui.ticket_print.part_code') }}</th>
                        <th>{{ __('ui.ticket_print.part_name') }}</th>
                        <th style="width: 150px">{{ __('ui.ticket_print.part_serial') }}</th>
                        <th class="num" style="width: 70px">{{ __('ui.ticket_print.quantity') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($parts ?? [] as $i => $part)
                        <tr>
                            <td class="center">{{ $i + 1 }}</td>
                            <td style="font-family: monospace">{{ $part['code'] }}</td>
                            <td>{{ $part['name'] }}@if (array_diff($part['types'] ?? [], ['issue'])) <span class="muted">({{ collect($part['types'])->map(fn ($type) => __('ui.stock_movements.types.'.$type))->implode(', ') }})</span>@endif</td>
                            <td></td>
                            <td class="num">{{ $part['quantity'] }} {{ $part['unit'] }}</td>
                        </tr>
                    @empty
                        @for ($row = 0; $row < 2; $row++)
                            <tr><td style="height: 20px"></td><td></td><td></td><td></td><td></td></tr>
                        @endfor
                    @endforelse
                </tbody>
            </table>
        </section>

        {{-- The technician says it is done, the customer that the device came back right --}}
        <div class="signatures">
            @foreach ([
                [__('ui.ticket_print.done_caption'), __('document.ticket.technician_sign'), $ticket['assignee']],
                [__('ui.ticket_print.checked_caption'), __('document.ticket.customer_sign'), $ticket['contact_name']],
            ] as [$caption, $role, $name])
                <div>
                    <div class="caption">{{ $caption }}</div>
                    <div class="sign-row"><span>{{ __('ui.ticket_print.sign') }}</span><span class="sign-line"></span><span>{{ $role }}</span></div>
                    <div>( {{ $name ?? $blank }} )</div>
                    <div class="muted">{{ __('document.sign_date') }}</div>
                </div>
            @endforeach
        </div>

        @if ($rating !== null)
            <section style="page-break-inside: avoid">
                <h2>{{ __('ui.ticket_print.rating') }}</h2>
                <div class="rating-grid">
                    @foreach (__('ui.ticket_survey.levels') as $score => $level)
                        <div>
                            <span class="checkbox">{{ $rating['score'] === $score ? '✓' : '' }}</span>
                            {{ __('ui.ticket_survey.level_score', ['score' => $score]) }} : <strong>{{ $level['label'] }}</strong>
                            <span class="muted">({{ $level['hint'] }})</span>
                        </div>
                    @endforeach
                </div>
                <div class="fact" style="margin-top: 3px"><span class="label">{{ __('ui.ticket_print.rating_comment') }}</span><span class="value">{{ $rating['comment'] }}</span></div>
            </section>
        @endif
    </div>
@endsection
