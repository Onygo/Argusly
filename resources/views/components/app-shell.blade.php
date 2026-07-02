@props([
    'title' => null,
    'description' => null,
    'breadcrumbs' => [],
    'class' => '',
])

@php
    $normalizedBreadcrumbs = collect($breadcrumbs)
        ->map(function ($crumb) {
            if (is_string($crumb)) {
                return ['label' => $crumb, 'url' => null];
            }

            return [
                'label' => $crumb['label'] ?? $crumb['title'] ?? null,
                'url' => $crumb['url'] ?? $crumb['href'] ?? null,
            ];
        })
        ->filter(fn ($crumb) => filled($crumb['label']))
        ->values()
        ->all();
@endphp

<div {{ $attributes->class(['pl-app-framework', $class])->merge(['data-app-shell' => true]) }}>
    <div class="pl-app-framework__region" data-shell-region="breadcrumb">
        @isset($breadcrumb)
            {{ $breadcrumb }}
        @elseif (! empty($normalizedBreadcrumbs))
            <x-breadcrumb :items="$normalizedBreadcrumbs" />
        @endif
    </div>

    <div class="pl-app-framework__region" data-shell-region="page-header">
        @isset($pageHeader)
            {{ $pageHeader }}
        @elseif (filled($title) || filled($description))
            <x-page-header :title="$title">
                @if (filled($description))
                    <x-slot:description>{{ $description }}</x-slot:description>
                @endif
            </x-page-header>
        @endif
    </div>

    <div class="pl-app-framework__region" data-shell-region="description">
        @isset($pageDescription)
            {{ $pageDescription }}
        @endif
    </div>

    <div class="pl-app-framework__region" data-shell-region="primary-actions">
        @isset($primaryActions)
            <x-action-bar>{{ $primaryActions }}</x-action-bar>
        @endif
    </div>

    <div class="pl-app-framework__region" data-shell-region="filter-bar">
        @isset($filterBar)
            <x-filter-bar>{{ $filterBar }}</x-filter-bar>
        @endif
    </div>

    <div class="pl-app-framework__region" data-shell-region="kpi-section">
        @isset($metricSection)
            {{ $metricSection }}
        @endif
    </div>

    <div class="pl-app-framework__main" data-shell-region="main-content">
        {{ $slot }}
    </div>

    <div class="pl-app-framework__region" data-shell-region="detail-drawer">
        @isset($detailDrawer)
            {{ $detailDrawer }}
        @endif
    </div>

    <div class="pl-app-framework__region" data-shell-region="footer-actions">
        @isset($footerActions)
            <x-action-bar align="end">{{ $footerActions }}</x-action-bar>
        @endif
    </div>
</div>
