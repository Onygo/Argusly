@props([
    'descriptor' => [],
    'href' => null,
    'target' => null,
    'mode' => null,
    'label' => null,
])

@php
    $descriptorArray = $descriptor instanceof \Illuminate\Contracts\Support\Arrayable
        ? $descriptor->toArray()
        : (is_array($descriptor) ? $descriptor : []);
    $targetArray = data_get($descriptorArray, 'target', []);
    $drawerTarget = $target ?? data_get($targetArray, 'target');
    $drawerMode = $mode ?? data_get($targetArray, 'mode', 'inspect');
    $fallbackHref = $href ?? data_get($descriptorArray, 'href', '#');
    $drawerUrl = data_get($descriptorArray, 'drawer_url', $fallbackHref);
    $triggerAttributes = array_filter(array_merge(data_get($descriptorArray, 'data_attributes', []), [
        'href' => $fallbackHref,
        'data-drawer-trigger' => 'link',
        'data-drawer-target' => $drawerTarget,
        'data-drawer-mode' => $drawerMode,
        'data-drawer-url' => $drawerUrl,
        'data-drawer-payload' => $descriptorArray === [] ? null : json_encode($descriptorArray),
        'aria-haspopup' => 'dialog',
    ]), fn ($value) => $value !== null && $value !== '');
@endphp

<a {{ $attributes
    ->class('inline-flex items-center gap-2 text-sm font-medium text-primary underline-offset-4 hover:underline focus:outline-none focus:ring-2 focus:ring-primary/30')
    ->merge($triggerAttributes) }}
>
    {{ $slot->isEmpty() ? ($label ?? data_get($descriptorArray, 'title', 'Open detail')) : $slot }}
</a>
