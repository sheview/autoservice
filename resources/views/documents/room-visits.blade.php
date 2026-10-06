@extends('documents.layout')

@section('title', __('ui.room_report.title'))

@section('content')
    <div class="header">
        <div>
            <div class="company">{{ $company }}</div>
            <div class="muted">{{ __('ui.room_report.period', ['period' => $period]) }}</div>
            @if ($room)
                <div class="muted">{{ __('ui.room_report.room') }}: {{ $room }}</div>
            @endif
            @if ($customer)
                <div class="muted">{{ __('ui.room_report.customer') }}: {{ $customer }}</div>
            @endif
        </div>
        <div class="doc-title">{{ __('ui.room_report.title') }}</div>
    </div>

    <section>
        <table style="font-size: 9pt">
            <thead>
                <tr>
                    <th style="width: 95px">{{ __('ui.room_report.entered_at') }} / {{ __('ui.room_report.exited_at') }}</th>
                    <th style="width: 110px">{{ __('ui.room_report.room') }}</th>
                    <th>{{ __('ui.room_report.people') }}</th>
                    <th>{{ __('ui.room_report.purpose') }} / {{ __('ui.room_report.work_summary') }}</th>
                    <th style="width: 95px">{{ __('ui.room_report.ticket') }} / {{ __('ui.room_report.contract') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($rows as $row)
                    <tr>
                        <td>
                            {{ $row['entered'] }}
                            <div>{{ $row['exited'] ?? __('ui.room_report.still_inside') }}</div>
                            <div class="muted">{{ $row['request_no'] }}</div>
                        </td>
                        <td>
                            {{ $row['room'] }}
                            <div class="muted">{{ $row['customer'] }}</div>
                        </td>
                        <td>
                            {{ implode(', ', $row['people']) }}
                            <div class="muted">{{ __('ui.room_report.requester') }}: {{ $row['requester_name'] }}</div>
                        </td>
                        <td>
                            {{ $row['purpose'] }}
                            @if ($row['work_summary'])
                                <div class="muted">{{ $row['work_summary'] }}</div>
                            @endif
                        </td>
                        <td>
                            {{ $row['ticket'] }}
                            @if ($row['contract'])
                                <div class="muted">{{ $row['contract'] }}</div>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="center muted">{{ __('ui.room_report.none') }}</td></tr>
                @endforelse
            </tbody>
        </table>
        <p class="muted" style="margin: 6px 0 0">{{ __('ui.room_report.total', ['count' => count($rows)]) }}</p>
    </section>
@endsection
