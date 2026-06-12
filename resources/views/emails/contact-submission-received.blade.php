@extends('emails.layouts.base')

@php
    $detailRows = [
        'Name' => $submission->name,
        'Email' => $submission->email,
        'Company' => $submission->company ?: 'n/a',
        'Subject' => $submission->subject ?: 'n/a',
        'Topic' => $submission->topic ?: 'n/a',
        'Source page' => $submission->source_page ?: 'n/a',
        'CTA' => $submission->cta_label ?: 'n/a',
        'URL' => $submission->url ?: 'n/a',
        'IP address' => $submission->ip_address ?: 'n/a',
        'Submitted at' => optional($submission->created_at)->format('Y-m-d H:i:s') ?: 'n/a',
    ];
@endphp

@section('content')
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:18px 0 22px; border:1px solid #e4e8f0; border-radius:10px; overflow:hidden;">
        @foreach ($detailRows as $label => $value)
            <tr>
                <td width="34%" style="padding:11px 14px; background:#f8fafc; border-bottom:1px solid #e4e8f0; color:#64748b; font-size:13px; font-weight:700; vertical-align:top;">{{ $label }}</td>
                <td style="padding:11px 14px; border-bottom:1px solid #e4e8f0; color:#1f2937; font-size:14px; line-height:1.5; vertical-align:top;">
                    @if ($label === 'Email' && filter_var($value, FILTER_VALIDATE_EMAIL))
                        <a href="mailto:{{ $value }}" style="color:#365cf5; text-decoration:none; font-weight:700;">{{ $value }}</a>
                    @elseif ($label === 'URL' && filter_var($value, FILTER_VALIDATE_URL))
                        <a href="{{ $value }}" style="color:#365cf5; text-decoration:none; font-weight:700;">{{ $value }}</a>
                    @else
                        {{ $value }}
                    @endif
                </td>
            </tr>
        @endforeach
    </table>

    <p style="margin:0 0 8px; font-size:13px; line-height:1.5; color:#64748b; font-weight:700; text-transform:uppercase; letter-spacing:0.04em;">Message</p>
    <div style="margin:0; padding:16px 18px; background:#f8fafc; border:1px solid #e4e8f0; border-radius:10px; color:#1f2937; font-size:15px; line-height:1.7;">
        {!! nl2br(e($submission->message)) !!}
    </div>
@endsection
