@props([
    'descriptor' => [],
    'href' => null,
    'target' => null,
    'mode' => null,
    'title' => null,
    'subtitle' => null,
])

@php
    $descriptorArray = $descriptor instanceof \Illuminate\Contracts\Support\Arrayable
        ? $descriptor->toArray()
        : (is_array($descriptor) ? $descriptor : []);
    $targetArray = data_get($descriptorArray, 'target', []);
    $drawerTarget = $target ?? data_get($targetArray, 'target');
    $drawerMode = $mode ?? data_get($targetArray, 'mode', 'preview');
    $fallbackHref = $href ?? data_get($descriptorArray, 'href', '#');
    $drawerUrl = data_get($descriptorArray, 'drawer_url', $fallbackHref);
    $previewTitle = $title ?? data_get($descriptorArray, 'title', 'Preview');
    $previewSubtitle = $subtitle ?? data_get($descriptorArray, 'subtitle');
    $badges = data_get($descriptorArray, 'badges', []);
    $triggerAttributes = array_filter(array_merge(data_get($descriptorArray, 'data_attributes', []), [
        'href' => $fallbackHref,
        'data-drawer-trigger' => 'preview',
        'data-drawer-target' => $drawerTarget,
        'data-drawer-mode' => $drawerMode,
        'data-drawer-url' => $drawerUrl,
        'data-drawer-payload' => $descriptorArray === [] ? null : json_encode($descriptorArray),
        'aria-haspopup' => 'dialog',
    ]), fn ($value) => $value !== null && $value !== '');
@endphp

<a {{ $attributes
    ->class('group block rounded-md border border-border bg-surface p-3 text-left hover:border-primary/40 hover:bg-muted/40 focus:outline-none focus:ring-2 focus:ring-primary/30')
    ->merge($triggerAttributes) }}
>
    <span class="flex items-start justify-between gap-3">
        <span class="min-w-0">
            <span class="block truncate text-sm font-medium text-foreground">{{ $previewTitle }}</span>
            @if (filled($previewSubtitle))
                <span class="mt-1 block truncate text-xs text-muted-foreground">{{ $previewSubtitle }}</span>
            @endif
        </span>
        @if ($badges !== [])
            <span class="flex shrink-0 flex-wrap justify-end gap-1">
                @foreach ($badges as $badge)
                    <span class="rounded border border-border px-1.5 py-0.5 text-[11px] font-medium text-muted-foreground">
                        {{ data_get($badge, 'label') }}
                    </span>
                @endforeach
            </span>
        @endif
    </span>

    @if ($slot->isNotEmpty())
        <span class="mt-2 block text-sm text-muted-foreground">{{ $slot }}</span>
    @endif
</a>
