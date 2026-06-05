@extends('emails.layouts.base-text')

@section('content')
@if ($signup)
Request details: {{ $signup->full_name }}@if ($signup->company_name) · {{ $signup->company_name }}@endif

@endif
@if ($invite?->expires_at)
This activation link expires on {{ $invite->expires_at->format('Y-m-d H:i') }}.

@endif
@endsection
