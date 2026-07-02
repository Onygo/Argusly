@props([
    'tabs' => [],
    'active' => null,
])

@php
    $normalizedTabs = collect($tabs)
        ->map(fn ($tab) => is_string($tab) ? ['key' => $tab, 'label' => $tab] : $tab)
        ->filter(fn ($tab) => filled($tab['label'] ?? null))
        ->values();
    $activeKey = $active ?? data_get($normalizedTabs->first(), 'key');
@endphp

@if ($normalizedTabs->isNotEmpty())
    <nav {{ $attributes->class('shrink-0 border-b border-divider px-5') }} aria-label="Drawer sections">
        <div class="flex gap-4 overflow-x-auto" role="tablist">
            @foreach ($normalizedTabs as $tab)
                @php
                    $tabKey = $tab['key'] ?? $tab['label'];
                    $isActive = $tabKey === $activeKey;
                @endphp
                <a
                    href="{{ $tab['url'] ?? '#' }}"
                    class="inline-flex min-h-11 items-center border-b-2 px-1 text-sm font-medium {{ $isActive ? 'border-primary text-textPrimary' : 'border-transparent text-textSecondary hover:text-textPrimary' }}"
                    role="tab"
                    aria-selected="{{ $isActive ? 'true' : 'false' }}"
                    data-drawer-tab="{{ $tabKey }}"
                >
                    {{ $tab['label'] }}
                </a>
            @endforeach
        </div>
    </nav>
@endif
