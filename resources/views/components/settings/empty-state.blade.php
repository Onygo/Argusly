@props([
    'title',
    'description',
])

<div {{ $attributes->class(['rounded-lg border border-dashed border-border bg-background px-4 py-5 text-center']) }}>
    <p class="text-sm font-medium text-textPrimary">{{ $title }}</p>
    <p class="mt-1 text-xs text-textSecondary">{{ $description }}</p>
</div>
