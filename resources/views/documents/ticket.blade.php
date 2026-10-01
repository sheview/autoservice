@php($date = fn ($value, $time = true) => \App\Modules\Document\Support\ThaiDate::format($value, $time))
@extends('documents.layout')

@section('title', __('document.ticket.title').' '.$ticket['ticket_no'])

@section('content')
    <div class="header">
        <div>
            <div class="company">{{ $company }}</div>
            <div class="doc-title">{{ __('document.ticket.title') }}</div>
        </div>
        <div>
            <div class="doc-no">{{ $ticket['ticket_no'] }}</div>
            <div class="muted" style="text-align: right">{{ __('ui.tickets.statuses.'.$ticket['status']) }}</div>
        </div>
    </div>

    <div class="facts">
        <div class="fact"><span class="label">{{ __('document.ticket.customer') }}</span><span class="value">{{ $ticket['customer'] }}</span></div>
        <div class="fact"><span class="label">{{ __('document.ticket.contact') }}</span><span class="value">{{ collect([$ticket['contact_name'], $ticket['contact_phone']])->filter()->implode(' · ') }}</span></div>
        <div class="fact"><span class="label">{{ __('document.ticket.asset') }}</span><span class="value">{{ $ticket['asset'] ? $ticket['asset']['asset_code'].' '.$ticket['asset']['name'] : '' }}</span></div>
        <div class="fact"><span class="label">{{ __('document.ticket.contract') }}</span><span class="value">{{ $ticket['contract_no'] ?? __('document.ticket.out_of_contract') }}</span></div>
        <div class="fact"><span class="label">{{ __('document.ticket.priority') }}</span><span class="value">{{ __('ui.tickets.priorities.'.$ticket['priority']) }}</span></div>
        <div class="fact"><span class="label">{{ __('document.ticket.source') }}</span><span class="value">{{ __('ui.tickets.sources.'.$ticket['source']) }}</span></div>
        <div class="fact"><span class="label">{{ __('document.ticket.assignee') }}</span><span class="value">{{ $ticket['assignee'] }}</span></div>
        <div class="fact"><span class="label">{{ __('document.ticket.branch') }}</span><span class="value">{{ $ticket['branch'] }}</span></div>
    </div>

    <div class="times">
        @foreach (['opened_at' => 'created_at', 'responded_at' => 'responded_at', 'resolved_at' => 'resolved_at', 'closed_at' => 'closed_at'] as $label => $field)
            <div class="box">
                <div class="muted">{{ __('document.ticket.'.$label) }}</div>
                <div>{{ $date($ticket[$field]) }}</div>
            </div>
        @endforeach
    </div>

    <section>
        <h2>{{ __('document.ticket.problem') }}</h2>
        <div class="box" style="min-height: 22mm">
            <div style="font-weight: 600">{{ $ticket['title'] }}</div>
            @if ($ticket['description'])
                <div class="pre">{{ $ticket['description'] }}</div>
            @endif
        </div>
    </section>

    <section>
        <h2>{{ __('document.ticket.work_done') }}</h2>
        <div class="box" style="min-height: 38mm">
            @foreach ($notes as $note)
                <div style="margin-bottom: 4px">
                    <span class="muted" style="font-size: 9pt">{{ $date($note['at']) }} · {{ $note['user_name'] ?? __('ui.common.system') }}</span>
                    <div class="pre">{{ $note['body'] }}</div>
                </div>
            @endforeach
        </div>
    </section>

    @if ($parts !== null)
        <section>
            <h2>{{ __('document.ticket.parts') }}</h2>
            <table>
                <thead>
                    <tr>
                        <th class="center" style="width: 40px">{{ __('document.no') }}</th>
                        <th style="width: 140px">{{ __('document.ticket.part_code') }}</th>
                        <th>{{ __('document.ticket.part_name') }}</th>
                        <th class="num" style="width: 110px">{{ __('document.ticket.quantity') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($parts as $i => $part)
                        <tr>
                            <td class="center">{{ $i + 1 }}</td>
                            <td style="font-family: monospace">{{ $part['code'] }}</td>
                            <td>{{ $part['name'] }}@if (array_diff($part['types'] ?? [], ['issue'])) <span class="muted">({{ collect($part['types'])->map(fn ($type) => __('ui.stock_movements.types.'.$type))->implode(', ') }})</span>@endif</td>
                            <td class="num">{{ $part['quantity'] }} {{ $part['unit'] }}</td>
                        </tr>
                    @empty
                        @for ($row = 0; $row < 3; $row++)
                            <tr><td style="height: 24px"></td><td></td><td></td><td></td></tr>
                        @endfor
                    @endforelse
                </tbody>
            </table>
        </section>
    @endif

    <div class="signatures">
        <div>
            <div class="sign-line"></div>
            <div>( {{ $ticket['assignee'] ?? str_repeat('.', 40) }} )</div>
            <div style="font-weight: 600">{{ __('document.ticket.technician_sign') }}</div>
            <div class="muted">{{ __('document.sign_date') }}</div>
        </div>
        <div>
            <div class="sign-line"></div>
            <div>( {{ $ticket['contact_name'] ?? str_repeat('.', 40) }} )</div>
            <div style="font-weight: 600">{{ __('document.ticket.customer_sign') }}</div>
            <div class="muted">{{ __('document.sign_date') }}</div>
        </div>
    </div>
@endsection
