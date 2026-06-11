@props([
    'site' => null,
    'title',
    'description',
    'active' => 'overview',
    'navItems' => null,
    'metaItems' => [],
])

@php
    $navItems = $navItems ?? ($site ? [
        [
            'id' => 'overview',
            'label' => 'Overview',
            'url' => route('app.sites.insights.index', $site),
            'active' => $active === 'overview',
        ],
        [
            'id' => 'llm',
            'label' => 'LLM Visibility',
            'url' => route('app.sites.llm-tracking.index', $site),
            'active' => $active === 'llm',
        ],
        [
            'id' => 'audits',
            'label' => 'Audits',
            'url' => route('app.sites.seo-audits.index', $site),
            'active' => $active === 'audits',
        ],
        [
            'id' => 'competitors',
            'label' => 'Competitors',
            'url' => route('app.sites.competitors.index', $site),
            'active' => $active === 'competitors',
        ],
        [
            'id' => 'competitor-intelligence',
            'label' => 'Competitor Intelligence',
            'url' => route('app.sites.competitor-intelligence.index', $site),
            'active' => $active === 'competitor-intelligence',
        ],
        [
            'id' => 'analytics',
            'label' => 'Analytics',
            'url' => route('app.sites.analytics.show', $site),
            'active' => $active === 'analytics',
        ],
        [
            'id' => 'learnings',
            'label' => 'Learnings',
            'url' => route('app.sites.learnings.index', $site),
            'active' => $active === 'learnings',
        ],
    ] : []);

    $actions = trim((string) $slot);
@endphp

<div class="space-y-6">
    <header class="flex flex-wrap items-start justify-between gap-4">
        <div class="space-y-2">
            <div>
                <h1 class="text-2xl font-semibold tracking-tight text-textPrimary">{{ $title }}</h1>
                <p class="mt-1 text-textSecondary">{{ $description }}</p>
            </div>
            <div class="flex flex-wrap items-center gap-2 text-xs text-textSecondary">
                @if ($site)
                    <span class="inline-flex items-center rounded-md border border-border bg-surface px-2.5 py-1">Site: {{ $site->name }}</span>
                    <span class="inline-flex items-center rounded-md border border-border bg-surface px-2.5 py-1">Workspace: {{ $site->workspace?->name ?? 'n/a' }}</span>
                    <span class="inline-flex items-center rounded-md border border-border bg-surface px-2.5 py-1">URL: {{ $site->base_url ?: $site->site_url }}</span>
                @endif
                @foreach ($metaItems as $item)
                    <span class="inline-flex items-center rounded-md border border-border bg-surface px-2.5 py-1">{{ $item }}</span>
                @endforeach
            </div>
        </div>

        @if ($actions !== '')
            <div class="flex flex-wrap items-center gap-2">
                {{ $slot }}
            </div>
        @endif
    </header>

    @if (! empty($navItems))
        <x-app.section-nav :items="$navItems" />
    @endif
</div>
