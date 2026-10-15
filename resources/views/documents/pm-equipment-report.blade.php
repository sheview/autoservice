{{-- Individual Equipment Report: one piece of equipment of a work order, from the same data as the consolidated report. --}}
@extends('documents.layout')

@section('title', __('document.pm.equipment_title').' '.$report['visit']['visit_no'].' '.($items[0]['asset']['asset_code'] ?? ''))

@section('content')
    @include('documents.partials.pm-styles')
    @include('documents.partials.pm-head', ['title' => __('document.pm.equipment_title')])

    @foreach ($items as $item)
        @include('documents.partials.pm-item', ['item' => $item])
    @endforeach

    @include('documents.partials.pm-sign')
@endsection
