<h2>Mollie webhook activation gaps detected</h2>

<p>Checked intents: {{ $checkedCount }}</p>
<p>Issue count: {{ count($issueRows) }}</p>

<table border="1" cellpadding="6" cellspacing="0">
    <thead>
        <tr>
            <th>payment_intent_id</th>
            <th>provider_payment_id</th>
            <th>subscription_id</th>
            <th>organization_id</th>
            <th>plan_id</th>
            <th>subscription_status</th>
            <th>allowance_entries</th>
            <th>webhook_event</th>
            <th>issues</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($issueRows as $row)
            <tr>
                <td>{{ $row['payment_intent_id'] ?? '' }}</td>
                <td>{{ $row['provider_payment_id'] ?? '' }}</td>
                <td>{{ $row['subscription_id'] ?? '' }}</td>
                <td>{{ $row['organization_id'] ?? '' }}</td>
                <td>{{ $row['plan_id'] ?? '' }}</td>
                <td>{{ $row['subscription_status'] ?? '' }}</td>
                <td>{{ $row['allowance_entries'] ?? '' }}</td>
                <td>{{ $row['webhook_event'] ?? '' }}</td>
                <td>{{ $row['issues'] ?? '' }}</td>
            </tr>
        @endforeach
    </tbody>
</table>

<p>Generated at: {{ now()->toDateTimeString() }}</p>
