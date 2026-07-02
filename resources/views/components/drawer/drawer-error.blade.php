@props([
    'state' => [],
    'message' => null,
])

@php
    $title = data_get($state, 'title', 'Drawer unavailable');
    $description = $message ?? data_get($state, 'description', 'The drawer could not be rendered safely.');
@endphp

<div {{ $attributes->class('flex min-h-64 flex-1 items-start p-5') }} role="alert">
    <div class="w-full rounded-md border border-rose-200 bg-rose-50 p-4 text-rose-900">
        <p class="text-sm font-semibold">{{ $title }}</p>
        <p class="mt-1 text-sm text-rose-800">{{ $description }}</p>
    </div>
</div>
