@extends('public.legal.layout')

@section('legal_content')
    @include('public.legal.partials.document', [
        'documentIcon' => 'badge-check',
        'document' => $document,
        'lastUpdated' => $lastUpdated,
        'relatedLinks' => $relatedLinks,
    ])
@endsection
