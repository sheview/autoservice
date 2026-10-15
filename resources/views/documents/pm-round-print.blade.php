{{--
    A round report printed from the browser (PmRoundReport::printable): the cover, then each work
    order's sheet on new pages. With many sheets in one print, a sheet's signature block closes the
    sheet instead of being repeated at the foot of every page.
--}}
@extends('documents.layout')

@section('title', __('document.pm_round.title'))

@push('head')
    @include('documents.partials.pm-sheet-styles')
    <style>
        .round-sheet { page-break-before: always; }
        .round-sheet .sign-block { position: static; margin-top: 6mm; }
        .round-sheet .sign-space { display: none; }
        @media screen { .round-sheet { margin-top: 10mm; } }
    </style>
@endpush

@section('content')
    <div class="sheet-wrap">
        @include('documents.partials.pm-round-cover-body', $cover)
    </div>

    @foreach ($sheets as $sheet)
        <div class="round-sheet">
            @include('documents.partials.pm-sheet-body', $sheet)
        </div>
    @endforeach
@endsection
