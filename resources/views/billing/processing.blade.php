@extends('layouts.auth', ['title' => 'Payment processing', 'containerClass' => 'max-w-lg'])

@section('content')
    <div class="flex flex-col items-center gap-3">
        <span class="inline-flex h-10 w-10 items-center justify-center rounded-md bg-accentYellow-100 text-accentYellow-900">
            <i data-lucide="loader-circle" class="h-5 w-5 animate-spin"></i>
        </span>
        <h1 class="text-center text-2xl font-semibold tracking-tight text-textPrimary">Payment is being processed</h1>
        <p class="text-center text-sm text-textSecondary">We are confirming your payment. This page refreshes automatically.</p>
    </div>

    <x-alert :icon="true" iconName="timer">
        {{ $message ?? 'Please wait while we confirm your payment.' }}
    </x-alert>

    <div class="rounded-md border border-border bg-surface p-4 text-sm text-textSecondary">
        Keep this tab open. Activation usually completes within a few seconds.
    </div>

    <div class="flex flex-col gap-2 sm:flex-row">
        @if (! empty($poll_url))
            <a
                href="{{ $poll_url }}"
                class="inline-flex h-10 w-full items-center justify-center gap-2 rounded-md bg-primary px-4 py-2 text-sm font-medium text-textInverse transition-colors hover:bg-primaryHover focus:outline-none focus:ring-2 focus:ring-primarySoftRing sm:w-auto"
            >
                Refresh now
            </a>
        @endif
        @auth
            <a
                href="{{ route('app.billing.index') }}"
                class="inline-flex h-10 w-full items-center justify-center gap-2 rounded-md border border-border bg-surface px-4 py-2 text-sm font-medium text-textPrimary transition-colors hover:bg-surfaceMuted focus:outline-none focus:ring-2 focus:ring-primarySoftRing sm:w-auto"
            >
                Back to Billing
            </a>
        @endauth
    </div>

    @if (! empty($poll_url))
        <script>
            setTimeout(function () {
                window.location.href = @json($poll_url);
            }, 3000);
        </script>
    @endif
@endsection
