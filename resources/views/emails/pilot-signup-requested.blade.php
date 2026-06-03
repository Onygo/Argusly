<!doctype html>
<html lang="en">
    <head>
        <meta charset="utf-8">
        <title>New Argusly pilot request</title>
    </head>
    <body style="margin:0;background:#ffffff;color:#0b0f17;font-family:'Instrument Sans',Inter,Arial,sans-serif;-webkit-font-smoothing:antialiased;">
        <div style="display:none;max-height:0;overflow:hidden;color:transparent;opacity:0;">
            A new Argusly pilot request was submitted on argusly.com.
        </div>

        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#ffffff;padding:32px 16px;">
            <tr>
                <td align="center">
                    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:680px;border:1px solid #e7eaf0;border-radius:20px;background:#ffffff;overflow:hidden;">
                        <tr>
                            <td style="padding:30px 34px 32px;border-bottom:1px solid #e7eaf0;background-color:#f8fafc;">
                                <table role="presentation" width="100%" cellspacing="0" cellpadding="0">
                                    <tr>
                                        <td style="vertical-align:top;">
                                            <p style="margin:0 0 12px;font-size:11px;font-weight:700;letter-spacing:.12em;text-transform:uppercase;color:#235cff;">Argusly pilot</p>
                                            <h1 style="margin:0;max-width:460px;font-size:34px;line-height:1.05;font-weight:650;letter-spacing:-.01em;color:#0b0f17;">New pilot request</h1>
                                        </td>
                                        <td align="right" style="vertical-align:top;">
                                            <span style="display:inline-block;border-radius:999px;background:#0b0f17;padding:9px 14px;font-size:13px;font-weight:700;color:#ffffff;">Argusly</span>
                                        </td>
                                    </tr>
                                </table>
                            </td>
                        </tr>
                        <tr>
                            <td style="padding:32px 34px 36px;">
                                <p style="margin:0 0 24px;max-width:560px;font-size:16px;line-height:1.65;color:#667085;">
                                    A new pilot request was submitted on <a href="https://argusly.com" style="color:#235cff;font-weight:700;text-decoration:none;">argusly.com</a>. Follow up with the requester and review the signup in the Admin Control Center.
                                </p>

                                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border-collapse:collapse;font-size:14px;">
                                    @foreach ([
                                        'Name' => $signup['name'],
                                        'Email' => $signup['email'],
                                        'Company' => $signup['company'],
                                        'Website' => $signup['website'] ?: 'Not provided',
                                        'Role' => $signup['role'] ?: 'Not provided',
                                        'Submitted' => $signup['created_at'],
                                    ] as $label => $value)
                                        <tr>
                                            <td style="width:150px;padding:12px 0;border-top:1px solid #e7eaf0;color:#667085;font-weight:600;">{{ $label }}</td>
                                            <td style="padding:12px 0;border-top:1px solid #e7eaf0;color:#0b0f17;font-weight:700;">
                                                @if ($label === 'Email')
                                                    <a href="mailto:{{ $value }}" style="color:#235cff;text-decoration:none;">{{ $value }}</a>
                                                @else
                                                    {{ $value }}
                                                @endif
                                            </td>
                                        </tr>
                                    @endforeach
                                </table>

                                @if ($signup['goal'])
                                    <div style="margin-top:24px;padding:20px;border:1px solid #e7eaf0;border-radius:14px;background:#f8fafc;">
                                        <p style="margin:0 0 10px;font-size:11px;font-weight:700;letter-spacing:.12em;text-transform:uppercase;color:#235cff;">Goal</p>
                                        <p style="margin:0;font-size:14px;line-height:1.65;color:#0b0f17;">{{ $signup['goal'] }}</p>
                                    </div>
                                @endif

                                <table role="presentation" cellspacing="0" cellpadding="0" style="margin-top:28px;">
                                    <tr>
                                        <td style="border-radius:999px;background:#235cff;">
                                            <a href="mailto:{{ $signup['email'] }}?subject={{ rawurlencode('Argusly pilot request') }}" style="display:inline-block;padding:13px 18px;border-radius:999px;color:#ffffff;font-size:14px;font-weight:700;text-decoration:none;">Follow up with requester</a>
                                        </td>
                                    </tr>
                                </table>
                            </td>
                        </tr>
                    </table>
                    <p style="margin:18px 0 0;font-size:12px;line-height:1.5;color:#667085;">Argusly Admin Control Center</p>
                </td>
            </tr>
        </table>
    </body>
</html>
