@extends('public.legal.layout')

@section('legal_content')
    @include('public.legal.partials.document', ['document' => $document, 'lastUpdated' => $lastUpdated, 'relatedLinks' => $relatedLinks, 'activeLegal' => $activeLegal])
@endsection
