@props([
    'value' => 0,
    'label' => null,
])

@php
    $progress = max(0, min(100, (int) $value));
@endphp

<div {{ $attributes->merge(['class' => 'space-y-2']) }}>
    <div class="flex items-center justify-between gap-3 text-xs">
        <span class="font-medium text-textSecondary">{{ $label ?? 'Readiness' }}</span>
        <span class="font-semibold text-textPrimary">{{ $progress }}%</span>
    </div>
    <div class="h-2 overflow-hidden rounded-full bg-surfaceMuted">
        <div class="h-full rounded-full bg-primary transition-all" style="width: {{ $progress }}%"></div>
    </div>
</div>
