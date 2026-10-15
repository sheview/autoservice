{{-- The cover of a round report (PmRoundReport): the work orders inside, with their results and signatures. --}}
@extends('documents.layout')

@section('title', __('document.pm_round.title'))

@section('content')
    @include('documents.partials.pm-round-cover-body')
@endsection
