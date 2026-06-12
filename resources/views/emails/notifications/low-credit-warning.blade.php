@extends('emails.layouts.base')

@section('content')
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:18px 0 0; border:1px solid #e4e8f0; border-radius:10px; overflow:hidden;">
        <tr>
            <td style="padding:14px 16px; background:#f8fafc; color:#64748b; font-size:12px; font-weight:700; text-transform:uppercase; letter-spacing:0.04em;">Available credits</td>
            <td align="right" style="padding:14px 16px; background:#f8fafc; color:#111827; font-size:20px; font-weight:700;">{{ $availableCredits }}</td>
        </tr>
        @if (!empty($automationHint))
            <tr>
                <td colspan="2" style="padding:14px 16px; border-top:1px solid #e4e8f0; color:#475569; font-size:14px; line-height:1.6;">{{ $automationHint }}</td>
            </tr>
        @endif
    </table>
@endsection
