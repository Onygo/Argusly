@props([
    'descriptor' => [],
    'href' => null,
    'target' => null,
    'mode' => null,
    'label' => null,
    'type' => 'button',
])

@php
    $descriptorArray = $descriptor instanceof \Illuminate\Contracts\Support\Arrayable
        ? $descriptor->toArray()
        : (is_array($descriptor) ? $descriptor : []);
    $targetArray = data_get($descriptorArray, 'target', []);
    $drawerTarget = $target ?? data_get($targetArray, 'target');
    $drawerMode = $mode ?? data_get($targetArray, 'mode', 'inspect');
    $fallbackHref = $href ?? data_get($descriptorArray, 'href');
    $drawerUrl = data_get($descriptorArray, 'drawer_url', $fallbackHref);
    $triggerAttributes = array_filter(array_merge(data_get($descriptorArray, 'data_attributes', []), [
        'data-drawer-trigger' => 'button',
        'data-drawer-target' => $drawerTarget,
        'data-drawer-mode' => $drawerMode,
        'data-drawer-url' => $drawerUrl,
        'data-drawer-payload' => $descriptorArray === [] ? null : json_encode($descriptorArray),
        'aria-haspopup' => 'dialog',
    ]), fn ($value) => $value !== null && $value !== '');
    $content = $slot->isEmpty() ? ($label ?? data_get($descriptorArray, 'title', 'Open detail')) : $slot;
@endphp

@if (filled($fallbackHref))
    <a {{ $attributes
        ->class('inline-flex items-center justify-center gap-2 rounded-md border border-border bg-surface px-3 py-2 text-sm font-medium text-foreground hover:bg-muted focus:outline-none focus:ring-2 focus:ring-primary/30')
        ->merge(array_merge($triggerAttributes, ['href' => $fallbackHref, 'role' => 'button'])) }}
    >
        {{ $content }}
    </a>
@else
    <button {{ $attributes
        ->class('inline-flex items-center justify-center gap-2 rounded-md border border-border bg-surface px-3 py-2 text-sm font-medium text-foreground hover:bg-muted focus:outline-none focus:ring-2 focus:ring-primary/30')
        ->merge(array_merge($triggerAttributes, ['type' => $type])) }}
    >
        {{ $content }}
    </button>
@endif
