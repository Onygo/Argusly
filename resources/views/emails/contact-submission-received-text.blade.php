@extends('emails.layouts.base-text')

@section('content')
Name: {{ $submission->name }}
Email: {{ $submission->email }}
Company: {{ $submission->company ?: 'n/a' }}
Website: {{ $submission->website ?: 'n/a' }}
Market: {{ $submission->market ?: 'n/a' }}
Competitors: {{ $submission->competitors ?: 'n/a' }}
Growth goal: {{ $submission->growth_goal ?: 'n/a' }}
Interest area: {{ $submission->interest_area ?: 'n/a' }}
Subject: {{ $submission->subject ?: 'n/a' }}
Topic: {{ $submission->topic ?: 'n/a' }}
Source page: {{ $submission->source_page ?: 'n/a' }}
CTA: {{ $submission->cta_label ?: 'n/a' }}
URL: {{ $submission->url ?: 'n/a' }}
IP address: {{ $submission->ip_address ?: 'n/a' }}
Submitted at: {{ optional($submission->created_at)->format('Y-m-d H:i:s') ?: 'n/a' }}

Message:
{{ $submission->message }}

@endsection
