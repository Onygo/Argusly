<!doctype html>
<html lang="en">
    <body style="margin:0;background:#f6f8fb;font-family:Arial,sans-serif;color:#101828;">
        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f6f8fb;padding:32px 16px;">
            <tr>
                <td align="center">
                    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:640px;background:#ffffff;border:1px solid #e4e7ec;border-radius:8px;padding:28px;">
                        <tr>
                            <td>
                                <p style="margin:0 0 18px;font-size:14px;color:#667085;">Argusly pilot</p>
                                <h1 style="margin:0 0 18px;font-size:24px;line-height:1.25;color:#101828;">Your pilot request is ready for the next step</h1>
                                <p style="margin:0 0 16px;font-size:15px;line-height:1.6;color:#344054;">Hi {{ $signup['name'] }},</p>
                                <p style="margin:0 0 16px;font-size:15px;line-height:1.6;color:#344054;">Thanks for requesting an Argusly pilot for {{ $signup['company'] }}. We reviewed your request and can prepare the workspace for the pilot.</p>
                                <p style="margin:0 0 16px;font-size:15px;line-height:1.6;color:#344054;">Reply to this email with the best contact details and any setup notes you want us to include. We will use that to finalize account access, modules and pilot credits.</p>
                                @if ($signup['goal'])
                                    <p style="margin:0 0 16px;font-size:14px;line-height:1.6;color:#667085;"><strong style="color:#101828;">Pilot goal:</strong> {{ $signup['goal'] }}</p>
                                @endif
                                <p style="margin:24px 0 0;font-size:15px;line-height:1.6;color:#344054;">Best,<br>Argusly</p>
                            </td>
                        </tr>
                    </table>
                </td>
            </tr>
        </table>
    </body>
</html>
