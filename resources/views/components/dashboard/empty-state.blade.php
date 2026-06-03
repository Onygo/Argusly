@props(['title', 'message'])

<div {{ $attributes->merge(['class' => 'rounded-md border border-dashed border-line bg-panel p-5']) }}>
    <p class="text-sm font-semibold text-ink">{{ $title }}</p>
    <p class="mt-1 text-sm leading-6 text-muted">{{ $message }}</p>
</div>
