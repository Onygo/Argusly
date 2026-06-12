@extends('emails.layouts.base', [
    'subjectLine' => 'Mollie webhook activation gaps detected',
    'preheader' => 'Billing webhook activation gaps need review.',
    'headline' => 'Mollie webhook activation gaps detected',
    'intro' => 'Argusly detected payment intents where activation may not have completed cleanly.',
    'body' => 'Review the rows below and reconcile the subscriptions that need attention.',
    'eyebrow' => 'Billing alert',
])

@section('content')
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:18px 0 22px;">
        <tr>
            <td style="padding:14px 16px; background:#f8fafc; border:1px solid #e4e8f0; border-radius:10px;">
                <p style="margin:0 0 4px; color:#64748b; font-size:12px; font-weight:700; text-transform:uppercase; letter-spacing:0.04em;">Checked intents</p>
                <p style="margin:0; color:#111827; font-size:22px; font-weight:700;">{{ $checkedCount }}</p>
            </td>
            <td width="12">&nbsp;</td>
            <td style="padding:14px 16px; background:#f8fafc; border:1px solid #e4e8f0; border-radius:10px;">
                <p style="margin:0 0 4px; color:#64748b; font-size:12px; font-weight:700; text-transform:uppercase; letter-spacing:0.04em;">Issue count</p>
                <p style="margin:0; color:#111827; font-size:22px; font-weight:700;">{{ count($issueRows) }}</p>
            </td>
        </tr>
    </table>

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #e4e8f0; border-radius:10px; overflow:hidden;">
        <thead>
            <tr>
                <th align="left" style="padding:10px 12px; background:#f8fafc; border-bottom:1px solid #e4e8f0; color:#64748b; font-size:12px;">Payment</th>
                <th align="left" style="padding:10px 12px; background:#f8fafc; border-bottom:1px solid #e4e8f0; color:#64748b; font-size:12px;">Subscription</th>
                <th align="left" style="padding:10px 12px; background:#f8fafc; border-bottom:1px solid #e4e8f0; color:#64748b; font-size:12px;">Issues</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($issueRows as $row)
                <tr>
                    <td style="padding:10px 12px; border-bottom:1px solid #e4e8f0; color:#1f2937; font-size:12px; line-height:1.45;">
                        <strong>Intent:</strong> {{ $row['payment_intent_id'] ?? 'n/a' }}<br>
                        <strong>Provider:</strong> {{ $row['provider_payment_id'] ?? 'n/a' }}<br>
                        <strong>Event:</strong> {{ $row['webhook_event'] ?? 'n/a' }}
                    </td>
                    <td style="padding:10px 12px; border-bottom:1px solid #e4e8f0; color:#1f2937; font-size:12px; line-height:1.45;">
                        <strong>Subscription:</strong> {{ $row['subscription_id'] ?? 'n/a' }}<br>
                        <strong>Organization:</strong> {{ $row['organization_id'] ?? 'n/a' }}<br>
                        <strong>Plan:</strong> {{ $row['plan_id'] ?? 'n/a' }}<br>
                        <strong>Status:</strong> {{ $row['subscription_status'] ?? 'n/a' }}<br>
                        <strong>Allowance:</strong> {{ $row['allowance_entries'] ?? 'n/a' }}
                    </td>
                    <td style="padding:10px 12px; border-bottom:1px solid #e4e8f0; color:#1f2937; font-size:12px; line-height:1.45;">{{ $row['issues'] ?? 'n/a' }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <p style="margin:14px 0 0; font-size:12px; line-height:1.6; color:#64748b;">Generated at: {{ now()->toDateTimeString() }}</p>
@endsection
