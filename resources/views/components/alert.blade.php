@props([
    'variant' => 'brand',
    'icon' => true,
    'dismissible' => false,
    'title' => null,
    'iconName' => 'shield-alert',
])

@php
    $isError = $variant === 'error';

    $containerClasses = $isError
        ? 'rounded-md border border-danger/30 bg-danger/5 px-4 py-3 text-sm text-danger'
        : 'rounded-md border border-accentYellow-900/20 bg-accentYellow-100 px-4 py-3 text-sm text-accentYellow-900';

    $iconClasses = $isError ? 'text-danger' : 'text-accentYellow-900';
@endphp

<div {{ $attributes->class([$containerClasses, 'flex items-start gap-3']) }} role="status" data-alert>
    @if ($icon)
        <i data-lucide="{{ $iconName }}" class="mt-0.5 h-4 w-4 shrink-0 {{ $iconClasses }}" aria-hidden="true"></i>
    @endif

    <div class="min-w-0 flex-1">
        @if ($title)
            <p class="font-medium">{{ $title }}</p>
        @endif

        <div>{{ $slot }}</div>

        @isset($actions)
            <div class="mt-3 flex flex-wrap items-center gap-2">
                {{ $actions }}
            </div>
        @endisset
    </div>

    @if ($dismissible)
        <button
            type="button"
            class="inline-flex h-7 w-7 items-center justify-center rounded-md border border-accentYellow-900/20 bg-accentYellow-100 text-accentYellow-900 hover:bg-accentYellow-100/80"
            aria-label="Dismiss alert"
            onclick="this.closest('[data-alert]')?.remove()"
        >
            <i data-lucide="x" class="h-3.5 w-3.5" aria-hidden="true"></i>
        </button>
    @endif
</div>
