@extends('emails.layouts.base')

@section('content')
    <p style="margin:0 0 12px; font-size:14px; line-height:1.6; color:#374151;">
        <strong style="font-size:20px; letter-spacing:2px;">{{ $code ?? '' }}</strong>
    </p>
@endsection
