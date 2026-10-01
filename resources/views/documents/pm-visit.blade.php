@php($date = fn ($value, $time = false) => \App\Modules\Document\Support\ThaiDate::format($value, $time))
@extends('documents.layout')

@section('title', __('document.pm.title').' '.$visit['visit_no'])

@section('content')
    <div class="header">
        <div>
            <div class="company">{{ $company }}</div>
            <div class="doc-title">{{ __('document.pm.title') }}</div>
        </div>
        <div>
            <div class="doc-no">{{ $visit['visit_no'] }}</div>
            <div class="muted" style="text-align: right">{{ __('ui.pm_visits.statuses.'.$visit['status']) }}</div>
        </div>
    </div>

    <div class="facts">
        <div class="fact"><span class="label">{{ __('document.pm.customer') }}</span><span class="value">{{ $visit['customer'] }}</span></div>
        <div class="fact"><span class="label">{{ __('document.pm.contract') }}</span><span class="value">{{ $visit['contract_no'] }}</span></div>
        <div class="fact"><span class="label">{{ __('document.pm.plan') }}</span><span class="value">{{ $visit['plan'] }}</span></div>
        <div class="fact"><span class="label">{{ __('document.pm.round') }}</span><span class="value">{{ $visit['round'] }}</span></div>
        <div class="fact"><span class="label">{{ __('document.pm.period') }}</span><span class="value">{{ $date($visit['period_starts_on']) }} – {{ $date($visit['due_on']) }}</span></div>
        <div class="fact"><span class="label">{{ __('document.pm.scheduled_on') }}</span><span class="value">{{ $date($visit['scheduled_on']) }}</span></div>
        <div class="fact"><span class="label">{{ __('document.pm.assignee') }}</span><span class="value">{{ $visit['assignee'] }}</span></div>
        <div class="fact"><span class="label">{{ __('document.pm.completed_at') }}</span><span class="value">{{ $date($visit['completed_at'], true) }}</span></div>
    </div>

    <section>
        <h2>{{ __('document.pm.items') }}</h2>
        <p class="muted" style="margin: 0 0 4px">{{ __('document.pm.totals', $totals) }}</p>
        <table>
            <thead>
                <tr>
                    <th class="center" style="width: 50px">{{ __('document.no') }}</th>
                    <th style="width: 30%">{{ __('document.pm.asset') }}</th>
                    <th style="width: 70px">{{ __('document.pm.result') }}</th>
                    <th>{{ __('document.pm.checks') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($items as $i => $item)
                    <tr>
                        <td class="center">{{ $i + 1 }}</td>
                        <td>
                            @if ($item['asset'])
                                <div style="font-family: monospace">{{ $item['asset']['asset_code'] }}</div>
                                <div>{{ $item['asset']['name'] }}</div>
                            @else
                                -
                            @endif
                        </td>
                        <td>{{ __('ui.pm_visits.results.'.$item['result']) }}</td>
                        <td>
                            @foreach ($item['checks'] as $check)
                                <div>
                                    {{ $check['label'] }}:
                                    @if ($check['type'] === 'check')
                                        {{ $check['answer'] ? __('document.pm.yes') : __('document.pm.no_answer') }}
                                    @else
                                        {{ $check['answer'] ?? __('document.pm.no_answer') }}
                                    @endif
                                </div>
                            @endforeach
                            @if ($item['note'])
                                <div class="pre"><span class="muted">{{ __('document.pm.note') }}:</span> {{ $item['note'] }}</div>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </section>

    @if ($visit['summary'])
        <section>
            <h2>{{ __('document.pm.summary') }}</h2>
            <div class="box pre">{{ $visit['summary'] }}</div>
        </section>
    @endif

    <div class="signatures">
        <div>
            <div class="sign-line"></div>
            <div>( {{ $visit['assignee'] ?? str_repeat('.', 40) }} )</div>
            <div style="font-weight: 600">{{ __('document.pm.technician_sign') }}</div>
            <div class="muted">{{ __('document.sign_date') }}</div>
        </div>
        <div>
            <div class="sign-line"></div>
            <div>( {{ str_repeat('.', 40) }} )</div>
            <div style="font-weight: 600">{{ __('document.pm.customer_sign') }}</div>
            <div class="muted">{{ __('document.sign_date') }}</div>
        </div>
    </div>
@endsection
