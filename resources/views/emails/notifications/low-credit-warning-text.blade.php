@extends('emails.layouts.base-text')

@section('content')
Available credits: {{ $availableCredits }}

@if (!empty($automationHint))
{{ $automationHint }}

@endif
@endsection
