@php
    $mailLogoUrl = rtrim((string) config('app.url'), '/') . '/images/argusly-logo-standalone.png';
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $subjectLine ?? ($headline ?? 'Argusly') }}</title>
</head>
<body style="margin:0; padding:0; background:#f7f9fc; color:#111827; font-family:-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;">
<span style="display:none; font-size:1px; color:#f7f9fc; line-height:1px; max-height:0; max-width:0; opacity:0; overflow:hidden;">{{ $preheader ?? '' }}</span>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f7f9fc; padding:32px 16px;">
    <tr>
        <td align="center">
            <table role="presentation" width="640" cellpadding="0" cellspacing="0" style="width:100%; max-width:640px;">
                <tr>
                    <td style="padding:0 0 18px;">
                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                            <tr>
                                <td align="left" style="vertical-align:middle;">
                                    <img src="{{ $mailLogoUrl }}" width="124" alt="Argusly" style="display:block; width:124px; max-width:124px; height:auto; border:0;">
                                </td>
                                <td align="right" style="vertical-align:middle;">
                                    <span style="display:inline-block; border:1px solid #e4e8f0; border-radius:999px; padding:7px 11px; background:#ffffff; color:#64748b; font-size:12px; font-weight:700; letter-spacing:0.02em;">{{ $eyebrow ?? 'Argusly' }}</span>
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>
                <tr>
                    <td style="background:#ffffff; border:1px solid #e4e8f0; border-radius:14px; overflow:hidden; box-shadow:0 16px 40px rgba(15, 23, 42, 0.06);">
                        <div style="height:5px; background:#365cf5; line-height:5px; font-size:5px;">&nbsp;</div>
                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                            <tr>
                                <td style="padding:34px 36px 32px;">
                                    <h1 style="margin:0 0 14px; font-size:24px; font-weight:700; line-height:1.25; color:#111827;">{{ $headline ?? 'Argusly' }}</h1>

                                    @if (!empty($intro))
                                        <p style="margin:0 0 14px; font-size:15px; line-height:1.65; color:#475569;">{{ $intro }}</p>
                                    @endif

                                    @if (!empty($body))
                                        <p style="margin:0 0 14px; font-size:15px; line-height:1.65; color:#475569;">{{ $body }}</p>
                                    @endif

                                    @isset($lines)
                                        @foreach ((array) $lines as $line)
                                            @if (trim((string) $line) !== '')
                                                <p style="margin:0 0 12px; font-size:15px; line-height:1.65; color:#475569;">{{ $line }}</p>
                                            @endif
                                        @endforeach
                                    @endisset

                                    @yield('content')

                                    @if (!empty($cta_url))
                                        <table role="presentation" cellpadding="0" cellspacing="0" style="margin:22px 0 0;">
                                            <tr>
                                                <td style="background:#365cf5; border-radius:999px;">
                                                    <a href="{{ $cta_url }}" style="display:inline-block; color:#ffffff; text-decoration:none; padding:12px 20px; font-size:14px; font-weight:700;">{{ $cta_label ?? 'Open Argusly' }}</a>
                                                </td>
                                            </tr>
                                        </table>
                                    @endif
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>
                <tr>
                    <td style="padding:18px 6px 0;">
                        <p style="margin:0 0 5px; font-size:12px; line-height:1.6; color:#64748b;">This is a system email from Argusly.</p>
                        <p style="margin:0; font-size:12px; line-height:1.6; color:#64748b;">Argusly is a product by Onygo. If you did not expect this message, you can safely ignore it.</p>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
