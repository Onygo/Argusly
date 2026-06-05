<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $subjectLine ?? ($headline ?? 'Argusly') }}</title>
</head>
<body style="margin:0; padding:0; background:#f5f7fa; color:#111827; font-family:-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;">
<span style="display:none; font-size:1px; color:#f5f7fa; line-height:1px; max-height:0; max-width:0; opacity:0; overflow:hidden;">{{ $preheader ?? '' }}</span>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f5f7fa; padding:24px 0;">
    <tr>
        <td align="center">
            <table role="presentation" width="600" cellpadding="0" cellspacing="0" style="width:600px; max-width:600px; background:#ffffff; border:1px solid #e5e7eb; border-radius:6px;">
                <tr>
                    <td style="padding:24px;">
                        <h1 style="margin:0 0 12px; font-size:18px; font-weight:600; line-height:1.4; color:#111827;">{{ $headline ?? 'Argusly' }}</h1>

                        @if (!empty($intro))
                            <p style="margin:0 0 12px; font-size:14px; line-height:1.6; color:#374151;">{{ $intro }}</p>
                        @endif

                        @if (!empty($body))
                            <p style="margin:0 0 12px; font-size:14px; line-height:1.6; color:#374151;">{{ $body }}</p>
                        @endif

                        @isset($lines)
                            @foreach ((array) $lines as $line)
                                @if (trim((string) $line) !== '')
                                    <p style="margin:0 0 12px; font-size:14px; line-height:1.6; color:#374151;">{{ $line }}</p>
                                @endif
                            @endforeach
                        @endisset

                        @yield('content')

                        @if (!empty($cta_url))
                            <p style="margin:18px 0 0;">
                                <a href="{{ $cta_url }}" style="display:inline-block; background:#111827; color:#ffffff; text-decoration:none; border-radius:4px; padding:10px 18px; font-size:14px; font-weight:600;">{{ $cta_label ?? 'Open Argusly' }}</a>
                            </p>
                        @endif
                    </td>
                </tr>
                <tr>
                    <td style="padding:16px 24px; border-top:1px solid #e5e7eb;">
                        <p style="margin:0 0 4px; font-size:12px; line-height:1.5; color:#6b7280;">This is a system email from Argusly.</p>
                        <p style="margin:0; font-size:12px; line-height:1.5; color:#6b7280;">If you did not expect this message, you can safely ignore it.</p>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>

