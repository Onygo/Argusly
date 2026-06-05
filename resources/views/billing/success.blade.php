@extends('layouts.auth', ['title' => 'Payment confirmed', 'containerClass' => 'max-w-lg'])

@section('content')
    <div class="flex flex-col items-center gap-3">
        <span class="inline-flex h-10 w-10 items-center justify-center rounded-md bg-accentYellow-100 text-accentYellow-900">
            <i data-lucide="check-circle-2" class="h-5 w-5"></i>
        </span>
        <h1 class="text-center text-2xl font-semibold tracking-tight text-textPrimary">Payment confirmed</h1>
        <p class="text-center text-sm text-textSecondary">Your billing update has been completed successfully.</p>
    </div>

    <x-alert :icon="true" iconName="shield-check">
        {{ $message ?? 'Your billing state has been updated.' }}
    </x-alert>

    @if (! empty($summaryLines))
        <div class="rounded-md border border-border bg-surface p-4 text-sm text-textSecondary">
            <p class="font-medium text-textPrimary">Payment summary</p>
            <div class="mt-2 space-y-2">
                @foreach ($summaryLines as $line)
                    <div class="flex items-start justify-between gap-3">
                        <span>
                            {{ $line['label'] ?? 'Line item' }}
                            @if(($line['type'] ?? '') === 'one_time')
                                <span class="text-textSecondary">, one time</span>
                            @endif
                        </span>
                        <span class="whitespace-nowrap">€ {{ number_format(((int) ($line['amount_cents'] ?? 0)) / 100, 2, '.', ',') }}</span>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    <div class="rounded-md border border-border bg-surface p-4 text-sm text-textSecondary">
        You can continue in Billing to review subscription status, invoices, and credits.
    </div>

    <div class="flex flex-col gap-2 sm:flex-row">
        @auth
            <a
                href="{{ route('app.billing.index') }}"
                class="inline-flex h-10 w-full items-center justify-center gap-2 rounded-md bg-primary px-4 py-2 text-sm font-medium text-textInverse transition-colors hover:bg-primaryHover focus:outline-none focus:ring-2 focus:ring-primarySoftRing sm:w-auto"
            >
                Go to Billing
            </a>
        @else
            <a
                href="{{ route('login') }}"
                class="inline-flex h-10 w-full items-center justify-center gap-2 rounded-md bg-primary px-4 py-2 text-sm font-medium text-textInverse transition-colors hover:bg-primaryHover focus:outline-none focus:ring-2 focus:ring-primarySoftRing sm:w-auto"
            >
                Go to login
            </a>
        @endauth
        <a
            href="{{ route('landing') }}"
            class="inline-flex h-10 w-full items-center justify-center gap-2 rounded-md border border-border bg-surface px-4 py-2 text-sm font-medium text-textPrimary transition-colors hover:bg-surfaceMuted focus:outline-none focus:ring-2 focus:ring-primarySoftRing sm:w-auto"
        >
            Back to website
        </a>
    </div>
@endsection
