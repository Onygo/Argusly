@props([
    'state' => [],
    'message' => null,
])

@php
    $title = data_get($state, 'title', 'Loading drawer');
    $description = $message ?? data_get($state, 'description', 'Drawer content is loading.');
@endphp

<div {{ $attributes->class('flex min-h-64 flex-1 items-center justify-center p-5') }} role="status" aria-live="polite">
    <div class="w-full max-w-sm">
        <p class="text-sm font-semibold text-textPrimary">{{ $title }}</p>
        <p class="mt-1 text-sm text-textSecondary">{{ $description }}</p>
        <div class="mt-5 space-y-3" aria-hidden="true">
            <span class="block h-4 animate-pulse rounded bg-surfaceMuted"></span>
            <span class="block h-4 w-5/6 animate-pulse rounded bg-surfaceMuted"></span>
            <span class="block h-4 w-2/3 animate-pulse rounded bg-surfaceMuted"></span>
        </div>
    </div>
</div>
