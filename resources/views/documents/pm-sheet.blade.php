{{--
    The PM inspection sheet (PmReportDocument): the PDF and the "view" page in the browser are this
    same HTML. A4 portrait. A summary page, then each piece of equipment on its own page (per_asset)
    or a table of rows (grouped_table). The signature block is at the foot of every page: it is fixed
    to the bottom, which Chromium prints on each page, over a footer row of the page table that keeps
    the space free. The status badge is for the screen only.
--}}
@extends('documents.layout')

@section('title', __('document.pm_sheet.title').' '.$number)

@push('head')
    @include('documents.partials.pm-sheet-styles')
@endpush

@section('content')
    @include('documents.partials.pm-sheet-body')
@endsection
