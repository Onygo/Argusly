@props([
    'mode' => 'sites',
    'sites' => collect(),
    'selectedSiteId' => null,
    'filters' => [],
    'compact' => false,
    'showHeading' => true,
])

@php
    $titles = [
        'sites' => 'Content',
        'automations' => 'Automations',
        'chains' => 'Chains',
    ];
    $descriptions = [
        'sites' => 'Single lifecycle view for brief, draft, revisions and publishing.',
        'automations' => 'Schedule draft generation, chained planning, and auto publishing.',
        'chains' => 'Long-term archive of content clusters and strategy chains.',
    ];
@endphp

<div @class([$compact ? 'space-y-3' : 'space-y-6'])>
    @if ($showHeading || trim((string) $slot) !== '')
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            @if ($showHeading)
                <div class="min-w-0 flex-1">
                    <h1 @class([
                        'font-semibold tracking-tight text-textPrimary',
                        'text-xl' => $compact,
                        'text-2xl' => ! $compact,
                    ])>
                        {{ $titles[$mode] ?? 'Content' }}
                    </h1>
                    <p @class([
                        'mt-1 text-textSecondary',
                        'text-sm' => $compact,
                    ])>
                        {{ $descriptions[$mode] ?? '' }}
                    </p>
                </div>
            @endif
            @if (trim((string) $slot) !== '')
                <div class="flex w-full justify-start sm:w-auto sm:shrink-0 sm:justify-end">
                    {{ $slot }}
                </div>
            @endif
        </div>
    @endif

    <x-app.content-mode-nav :active="$mode" />

    @if ($mode === 'sites' && $sites->isNotEmpty())
        <div @class([$compact ? 'space-y-2' : 'space-y-4'])>
            <x-app.site-tabs :sites="$sites" :selected="$selectedSiteId" :base-filters="$filters" />
            @unless ($compact)
                <div class="h-px bg-border/70"></div>
            @endunless
        </div>
    @endif
</div>
