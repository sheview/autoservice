@php($long = fn ($value) => \App\Modules\Document\Support\ThaiDate::long($value))
@extends('documents.layout')

@section('title', __('ui.parts_issued.title'))

@section('content')
    <div class="header">
        <div>
            <div class="company">{{ $company }}</div>
            <div class="muted">{{ __('ui.parts_issued.period', ['from' => $long($period->from), 'to' => $long($period->to)]) }}</div>
            @if ($contract)
                <div class="muted">{{ __('ui.parts_issued.contract') }}: {{ $contract['contract_no'] }} {{ $contract['title'] }}</div>
            @endif
            @if ($customer)
                <div class="muted">{{ __('ui.parts_issued.customer') }}: {{ $customer }}</div>
            @endif
        </div>
        <div class="doc-title">{{ __('ui.parts_issued.title') }}</div>
    </div>

    <section>
        <table>
            <thead>
                <tr>
                    <th style="width: 70px">{{ __('ui.parts_issued.date') }}</th>
                    <th>{{ __('ui.parts_issued.part') }}</th>
                    <th class="num" style="width: 50px">{{ __('ui.parts_issued.quantity') }}</th>
                    <th style="width: 170px">{{ __('ui.parts_issued.serials') }}</th>
                    <th style="width: 120px">{{ __('ui.parts_issued.ticket') }}</th>
                    <th style="width: 120px">{{ __('ui.parts_issued.customer') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($rows as $row)
                    <tr class="{{ count($row['serials']) > 20 ? 'many-serials' : '' }}">
                        <td>{{ \App\Modules\Document\Support\ThaiDate::format($row['at']) }}</td>
                        <td>
                            <span style="font-family: monospace">{{ $row['part']['code'] ?? '' }}</span> {{ $row['part']['name'] ?? '' }}
                            @if (($row['part']['brand'] ?? null) || ($row['part']['part_number'] ?? null))
                                <div class="muted" style="font-size: 9pt">{{ trim(($row['part']['brand'] ?? '').' '.($row['part']['part_number'] ?? '')) }}</div>
                            @endif
                            @if ($row['asset'])
                                <div class="muted" style="font-size: 9pt">{{ $row['asset'] }}</div>
                            @endif
                        </td>
                        <td class="num">{{ $row['quantity'] }} {{ $row['part']['unit'] ?? '' }}</td>
                        <td>
                            @if ($row['serials'])
                                <div class="sn-list">
                                    @foreach ($row['serials'] as $serial)
                                        <span class="sn{{ in_array($serial, $row['returned'], true) ? ' sn-back' : '' }}">{{ $serial }}</span>
                                    @endforeach
                                </div>
                            @endif
                        </td>
                        <td>{{ $row['ticket']['ticket_no'] ?? $row['reference'] }}</td>
                        <td>
                            {{ $row['customer'] }}
                            @if ($row['contract'])
                                <div class="muted" style="font-size: 9pt">{{ $row['contract'] }}</div>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="center muted">{{ __('ui.parts_issued.none') }}</td></tr>
                @endforelse
            </tbody>
        </table>
        <p class="muted" style="margin: 6px 0 0">{{ __('ui.parts_issued.total', ['count' => count($rows)]) }}</p>
        @if (collect($rows)->contains(fn ($row) => $row['returned'] !== []))
            <p class="muted" style="margin: 2px 0 0; font-size: 9pt">{{ __('document.serials.returned_note') }}</p>
        @endif
    </section>
@endsection
