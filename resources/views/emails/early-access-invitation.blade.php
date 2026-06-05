@extends('emails.layouts.base')

@section('content')
    @if ($signup)
        <p style="margin:0 0 12px; font-size:14px; line-height:1.6; color:#374151;">
            Request details:
            {{ $signup->full_name }}
            @if ($signup->company_name)
                · {{ $signup->company_name }}
            @endif
        </p>
    @endif

    @if ($invite?->expires_at)
        <p style="margin:0 0 12px; font-size:14px; line-height:1.6; color:#374151;">
            This activation link expires on {{ $invite->expires_at->format('Y-m-d H:i') }}.
        </p>
    @endif
@endsection
