{{-- Consolidated PM report: the whole work order (PmReportDocument). --}}
@extends('documents.layout')

@section('title', __('document.pm.consolidated_title').' '.$report['visit']['visit_no'])

@section('content')
    @include('documents.partials.pm-styles')
    @include('documents.partials.pm-head', ['title' => __('document.pm.consolidated_title')])

    <section>
        <h2>{{ __('document.pm.overview') }}</h2>
        <p class="muted" style="margin: 0 0 4px">{{ __('document.pm.totals', $report['totals']) }}</p>
        <table class="overview">
            <thead>
                <tr>
                    <th class="center" style="width: 46px">{{ __('document.no') }}</th>
                    <th style="width: 34%">{{ __('document.pm.equipment') }}</th>
                    <th style="width: 18%">{{ __('document.pm.category') }}</th>
                    <th style="width: 12%">{{ __('document.pm.result') }}</th>
                    <th>{{ __('document.pm.failures') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($items as $i => $item)
                    <tr>
                        <td class="center">{{ $i + 1 }}</td>
                        <td><span style="font-family: monospace">{{ $item['asset']['asset_code'] ?? '-' }}</span> {{ $item['asset']['name'] ?? '' }}</td>
                        <td>{{ $item['asset']['category'] ?? '' }}</td>
                        <td>{{ __('ui.pm_visits.results.'.$item['result']) }}</td>
                        <td>{{ implode(', ', $item['failures']) ?: '-' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </section>

    @if ($report['visit']['summary'])
        <section>
            <h2>{{ __('document.pm.summary') }}</h2>
            <div class="box pre">{{ $report['visit']['summary'] }}</div>
        </section>
    @endif

    <section>
        <h2>{{ __('document.pm.detail') }}</h2>
        @foreach ($items as $item)
            @include('documents.partials.pm-item', ['item' => $item])
        @endforeach
    </section>

    @include('documents.partials.pm-sign')
@endsection
