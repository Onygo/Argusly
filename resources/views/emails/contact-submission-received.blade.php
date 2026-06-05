@extends('emails.layouts.base')

@section('content')
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:4px 0 12px;">
        <tr>
            <td style="padding:4px 0; font-size:14px; color:#374151;"><strong>Name:</strong> {{ $submission->name }}</td>
        </tr>
        <tr>
            <td style="padding:4px 0; font-size:14px; color:#374151;"><strong>Email:</strong> {{ $submission->email }}</td>
        </tr>
        <tr>
            <td style="padding:4px 0; font-size:14px; color:#374151;"><strong>Company:</strong> {{ $submission->company ?: 'n/a' }}</td>
        </tr>
        <tr>
            <td style="padding:4px 0; font-size:14px; color:#374151;"><strong>Subject:</strong> {{ $submission->subject ?: 'n/a' }}</td>
        </tr>
        <tr>
            <td style="padding:4px 0; font-size:14px; color:#374151;"><strong>Topic:</strong> {{ $submission->topic ?: 'n/a' }}</td>
        </tr>
        <tr>
            <td style="padding:4px 0; font-size:14px; color:#374151;"><strong>Source page:</strong> {{ $submission->source_page ?: 'n/a' }}</td>
        </tr>
        <tr>
            <td style="padding:4px 0; font-size:14px; color:#374151;"><strong>CTA:</strong> {{ $submission->cta_label ?: 'n/a' }}</td>
        </tr>
        <tr>
            <td style="padding:4px 0; font-size:14px; color:#374151;"><strong>URL:</strong> {{ $submission->url ?: 'n/a' }}</td>
        </tr>
        <tr>
            <td style="padding:4px 0; font-size:14px; color:#374151;"><strong>IP address:</strong> {{ $submission->ip_address ?: 'n/a' }}</td>
        </tr>
        <tr>
            <td style="padding:4px 0; font-size:14px; color:#374151;"><strong>Submitted at:</strong> {{ optional($submission->created_at)->format('Y-m-d H:i:s') ?: 'n/a' }}</td>
        </tr>
    </table>

    <p style="margin:0 0 8px; font-size:14px; line-height:1.6; color:#374151;"><strong>Message</strong></p>
    <p style="margin:0 0 12px; font-size:14px; line-height:1.6; color:#374151;">{!! nl2br(e($submission->message)) !!}</p>
@endsection
