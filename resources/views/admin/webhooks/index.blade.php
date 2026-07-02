@extends('layouts.admin', ['title' => 'Webhooks'])

@section('pageHeader')
    <x-page-header>
        <x-slot:title>Webhooks</x-slot:title>
        <x-slot:description>Recent inbound webhook events for diagnostics.</x-slot:description>
    </x-page-header>
@endsection

@section('content')

    <div class="grid gap-6 lg:grid-cols-2">
        <div class="rounded-lg border border-border bg-surface p-4">
            <h2 class="text-sm font-semibold text-textPrimary">Billing Webhook Events</h2>
            @if (! $has_billing_logs)
                <p class="mt-3 text-sm text-textSecondary">`webhook_events` table not available.</p>
            @elseif ($billing_webhook_events->isEmpty())
                <p class="mt-3 text-sm text-textSecondary">No billing webhook events yet.</p>
            @else
                <div class="mt-3 space-y-2">
                    @foreach ($billing_webhook_events as $event)
                        <div class="rounded border border-border p-3">
                            <p class="text-sm font-medium text-textPrimary">{{ $event->provider }} · {{ $event->event_type ?? 'event' }}</p>
                            <p class="mt-1 text-xs text-textSecondary">{{ $event->provider_event_id }}</p>
                            <p class="mt-1 text-xs text-textFaint">{{ optional($event->received_at)->toDateTimeString() }}</p>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>

        <div class="rounded-lg border border-border bg-surface p-4">
            <h2 class="text-sm font-semibold text-textPrimary">Argusly Webhook Events</h2>
            @if (! $has_argusly_logs)
                <p class="mt-3 text-sm text-textSecondary">`argusly_webhook_events` table not available.</p>
            @elseif ($argusly_webhook_events->isEmpty())
                <p class="mt-3 text-sm text-textSecondary">No Argusly webhook events yet.</p>
            @else
                <div class="mt-3 space-y-2">
                    @foreach ($argusly_webhook_events as $event)
                        <div class="rounded border border-border p-3">
                            <p class="text-sm font-medium text-textPrimary">{{ data_get($event, 'event_name', 'event') }}</p>
                            <p class="mt-1 text-xs text-textSecondary">{{ data_get($event, 'target_url', 'n/a') }}</p>
                            <p class="mt-1 text-xs text-textFaint">{{ data_get($event, 'created_at') }}</p>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </div>
@endsection
