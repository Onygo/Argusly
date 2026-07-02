@props([
    'state' => [],
    'message' => null,
])

@php
    $title = data_get($state, 'title', 'Nothing to show');
    $description = $message ?? data_get($state, 'description', 'There is no drawer content available yet.');
@endphp

<div {{ $attributes->class('flex min-h-64 flex-1 items-center justify-center p-5 text-center') }} role="status" aria-live="polite">
    <div class="max-w-sm">
        <p class="text-sm font-semibold text-textPrimary">{{ $title }}</p>
        <p class="mt-1 text-sm text-textSecondary">{{ $description }}</p>
    </div>
</div>
