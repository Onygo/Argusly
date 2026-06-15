<!DOCTYPE html>
<html lang="{{ $appLang ?? app()->getLocale() }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $title ?? \App\Support\Brand::product() }}</title>
    @include('partials.brand-meta')
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <script src="https://unpkg.com/lucide@latest"></script>
</head>
<body class="pl-app-shell pl-work-shell min-h-screen bg-background antialiased text-textPrimary">
@php
    $researchLayerFlag = app(\App\Support\FeatureFlags::class)->isEnabled('research_layer', false);
    $agenticMarketingFlag = app(\App\Support\FeatureFlags::class)->isEnabled('agentic_marketing', false);
    $signalIntelligenceFlag = app(\App\Support\FeatureFlags::class)->isEnabled('signal_intelligence', false);
    $programmaticGrowthNavActive = request()->routeIs('app.growth-programs.*')
        || request()->routeIs('app.programmatic-growth.*')
        || request()->routeIs('app.programmatic-opportunities.*')
        || request()->routeIs('app.programmatic-clusters.*')
        || request()->routeIs('app.programmatic-brief-blueprints.*')
        || request()->routeIs('app.programmatic-draft-requests.*')
        || request()->routeIs('app.programmatic-draft-reviews.*')
        || request()->routeIs('app.programmatic-publication-readiness.*')
        || request()->routeIs('app.programmatic-publication-plans.*');
    $sitesNavActive = request()->routeIs('app.sites') || request()->routeIs('app.sites.show') || request()->routeIs('app.sites.wordpress-plugin.download');
    $insightsNavActive = request()->routeIs('app.insights*')
        || request()->routeIs('app.sites.insights*')
        || request()->routeIs('app.sites.llm-tracking*')
        || request()->routeIs('app.sites.competitors*')
        || request()->routeIs('app.sites.seo-audits*')
        || request()->routeIs('app.sites.analytics*')
        || request()->routeIs('app.sites.learnings*');
    $advancedModeUser = auth()->user();
    $advancedModeCanEnable = \App\Support\AdvancedMode::canEnable($advancedModeUser);
    $advancedModeEnabled = \App\Support\AdvancedMode::enabled(request());
    $advancedModeCanSeeWorkflowInternals = \App\Support\AdvancedMode::canSeeWorkflowInternals($advancedModeUser);
    $advancedModeCanSeeOrganizationControls = \App\Support\AdvancedMode::canSeeOrganizationControls($advancedModeUser);
    $advancedModeCanSeeDeveloperTools = \App\Support\AdvancedMode::canSeeDeveloperTools($advancedModeUser);
    $contentIntelligenceWorkspace = auth()->user()?->organization_id
        ? \App\Models\Workspace::query()
            ->where('organization_id', auth()->user()->organization_id)
            ->orderBy('created_at')
            ->first(['id', 'organization_id'])
        : null;
    $opportunitiesRoute = route('app.opportunities.index');
    $opportunitiesNavActive = request()->routeIs('app.agentic-marketing.intelligence.*')
        || request()->routeIs('app.opportunities.*')
        || request()->routeIs('app.opportunity-intelligence.*')
        || request()->routeIs('app.signal-intelligence.*')
        || request()->routeIs('app.opportunity-review.*')
        || request()->routeIs('app.agentic-marketing.content-opportunities.*');
    $contentNavActive = request()->routeIs('app.content*')
        || request()->routeIs('app.briefs*')
        || request()->routeIs('app.drafts*')
        || request()->routeIs('app.research*')
        || $programmaticGrowthNavActive;
    $resultsNavActive = $insightsNavActive
        || request()->routeIs('app.agentic-marketing.learning.*')
        || request()->routeIs('app.workspaces.content-quality.*')
        || request()->routeIs('app.content-network.*');
    $brandNavActive = request()->routeIs('app.brand.*')
        || request()->routeIs('app.workspace-intelligence.*');
    $settingsNavActive = request()->routeIs('app.settings*')
        || request()->routeIs('app.billing.*')
        || request()->routeIs('app.developer.*');
    $pageWidth = in_array(($pageWidth ?? 'wide'), ['wide', 'constrained'], true) ? ($pageWidth ?? 'wide') : 'wide';
    $pageShellClass = $pageWidth === 'constrained'
        ? 'pl-page pl-page--constrained'
        : 'pl-page pl-page--wide';
    $impersonationActive = session()->has('admin_impersonator_id');
    $impersonationLabel = trim((string) (auth()->user()?->organization?->name ?? 'this workspace'));
    $impersonatorName = $impersonationActive
        ? (\App\Models\User::query()->whereKey(session('admin_impersonator_id'))->value('name') ?? 'Admin')
        : null;
    $impersonationTargetName = trim((string) (auth()->user()?->name ?? $impersonationLabel));
@endphp
<div class="flex min-h-screen w-full">
    <aside id="sidebar" data-collapsed="false" class="pl-work-sidebar hidden lg:flex sticky top-0 h-screen w-64 flex-col transition-all duration-300">
            <div class="pl-work-sidebar-brand">
            <x-brand-logo :show-text="false" />
            <div data-sidebar-label class="leading-tight">
                <span class="block text-sm font-semibold text-textPrimary">{{ \App\Support\Brand::product() }}</span>
            </div>
        </div>

        <nav class="flex-1 overflow-y-auto px-2 py-3">
            <div class="pl-work-sidebar-section">
                <p data-sidebar-label class="px-2 pb-1 text-xs font-medium uppercase tracking-wide text-textFaint">GROWTH</p>
                <div class="space-y-1">
                    <a href="{{ route('app.dashboard') }}" data-sidebar-item data-sidebar-title="Home" class="group relative flex h-9 items-center justify-start gap-3 rounded-md px-3 text-sm font-medium transition-all {{ request()->routeIs('app.dashboard') || request()->routeIs('app.activation.*') || request()->routeIs('app.setup.*') ? 'pl-work-sidebar-active' : 'text-textSecondary hover:bg-surfaceMuted hover:text-textPrimary' }}">
                        <span data-sidebar-icon-wrap class="flex h-5 w-5 shrink-0 items-center justify-center"><i data-lucide="layout-dashboard" class="h-4 w-4"></i></span>
                        <span data-sidebar-label class="truncate">Home</span>
                        <span data-sidebar-tooltip class="pointer-events-none absolute left-full top-1/2 z-50 ml-2 hidden -translate-y-1/2 whitespace-nowrap rounded-md border border-border bg-surface px-2 py-1 text-xs text-textPrimary opacity-0 transition-opacity duration-150 group-hover:opacity-100">Home</span>
                    </a>
                    <a href="{{ route('app.recommended-actions.index') }}" data-sidebar-item data-sidebar-title="Actions" class="group relative flex h-9 items-center justify-start gap-3 rounded-md px-3 text-sm font-medium transition-all {{ request()->routeIs('app.recommended-actions.*') ? 'pl-work-sidebar-active' : 'text-textSecondary hover:bg-surfaceMuted hover:text-textPrimary' }}">
                        <span data-sidebar-icon-wrap class="flex h-5 w-5 shrink-0 items-center justify-center"><i data-lucide="list-checks" class="h-4 w-4"></i></span>
                        <span data-sidebar-label class="truncate">Actions</span>
                        <span data-sidebar-tooltip class="pointer-events-none absolute left-full top-1/2 z-50 ml-2 hidden -translate-y-1/2 whitespace-nowrap rounded-md border border-border bg-surface px-2 py-1 text-xs text-textPrimary opacity-0 transition-opacity duration-150 group-hover:opacity-100">Actions</span>
                    </a>
                    <a href="{{ $opportunitiesRoute }}" data-sidebar-item data-sidebar-title="Opportunities" class="group relative flex h-9 items-center justify-start gap-3 rounded-md px-3 text-sm font-medium transition-all {{ $opportunitiesNavActive ? 'pl-work-sidebar-active' : 'text-textSecondary hover:bg-surfaceMuted hover:text-textPrimary' }}">
                        <span data-sidebar-icon-wrap class="flex h-5 w-5 shrink-0 items-center justify-center"><i data-lucide="radar" class="h-4 w-4"></i></span>
                        <span data-sidebar-label class="truncate">Opportunities</span>
                        <span data-sidebar-tooltip class="pointer-events-none absolute left-full top-1/2 z-50 ml-2 hidden -translate-y-1/2 whitespace-nowrap rounded-md border border-border bg-surface px-2 py-1 text-xs text-textPrimary opacity-0 transition-opacity duration-150 group-hover:opacity-100">Opportunities</span>
                    </a>
                    <a href="{{ route('app.content.pipeline.index') }}" data-sidebar-item data-sidebar-title="Content" class="group relative flex h-9 items-center justify-start gap-3 rounded-md px-3 text-sm font-medium transition-all {{ $contentNavActive ? 'pl-work-sidebar-active' : 'text-textSecondary hover:bg-surfaceMuted hover:text-textPrimary' }}">
                        <span data-sidebar-icon-wrap class="flex h-5 w-5 shrink-0 items-center justify-center"><i data-lucide="folder-kanban" class="h-4 w-4"></i></span>
                        <span data-sidebar-label class="truncate">Content</span>
                        <span data-sidebar-tooltip class="pointer-events-none absolute left-full top-1/2 z-50 ml-2 hidden -translate-y-1/2 whitespace-nowrap rounded-md border border-border bg-surface px-2 py-1 text-xs text-textPrimary opacity-0 transition-opacity duration-150 group-hover:opacity-100">Content</span>
                    </a>
                    <a href="{{ route('app.insights.index') }}" data-sidebar-item data-sidebar-title="Results" class="group relative flex h-9 items-center justify-start gap-3 rounded-md px-3 text-sm font-medium transition-all {{ $resultsNavActive ? 'pl-work-sidebar-active' : 'text-textSecondary hover:bg-surfaceMuted hover:text-textPrimary' }}">
                        <span data-sidebar-icon-wrap class="flex h-5 w-5 shrink-0 items-center justify-center"><i data-lucide="line-chart" class="h-4 w-4"></i></span>
                        <span data-sidebar-label class="truncate">Results</span>
                        <span data-sidebar-tooltip class="pointer-events-none absolute left-full top-1/2 z-50 ml-2 hidden -translate-y-1/2 whitespace-nowrap rounded-md border border-border bg-surface px-2 py-1 text-xs text-textPrimary opacity-0 transition-opacity duration-150 group-hover:opacity-100">Results</span>
                    </a>
                </div>
            </div>

            <div class="pl-work-sidebar-section">
                <p data-sidebar-label class="px-2 pb-1 text-xs font-medium uppercase tracking-wide text-textFaint">WORKSPACE</p>
                <div class="space-y-1">
                    <a href="{{ route('app.brand.company-profile') }}" data-sidebar-item data-sidebar-title="Brand" class="group relative flex h-9 items-center justify-start gap-3 rounded-md px-3 text-sm font-medium transition-all {{ $brandNavActive ? 'pl-work-sidebar-active' : 'text-textSecondary hover:bg-surfaceMuted hover:text-textPrimary' }}">
                        <span data-sidebar-icon-wrap class="flex h-5 w-5 shrink-0 items-center justify-center"><i data-lucide="palette" class="h-4 w-4"></i></span>
                        <span data-sidebar-label class="truncate">Brand</span>
                        <span data-sidebar-tooltip class="pointer-events-none absolute left-full top-1/2 z-50 ml-2 hidden -translate-y-1/2 whitespace-nowrap rounded-md border border-border bg-surface px-2 py-1 text-xs text-textPrimary opacity-0 transition-opacity duration-150 group-hover:opacity-100">Brand</span>
                    </a>
                    <a href="{{ route('app.sites') }}" data-sidebar-item data-sidebar-title="Sites" class="group relative flex h-9 items-center justify-start gap-3 rounded-md px-3 text-sm font-medium transition-all {{ $sitesNavActive ? 'pl-work-sidebar-active' : 'text-textSecondary hover:bg-surfaceMuted hover:text-textPrimary' }}">
                        <span data-sidebar-icon-wrap class="flex h-5 w-5 shrink-0 items-center justify-center"><i data-lucide="globe" class="h-4 w-4"></i></span>
                        <span data-sidebar-label class="truncate">Sites</span>
                        <span data-sidebar-tooltip class="pointer-events-none absolute left-full top-1/2 z-50 ml-2 hidden -translate-y-1/2 whitespace-nowrap rounded-md border border-border bg-surface px-2 py-1 text-xs text-textPrimary opacity-0 transition-opacity duration-150 group-hover:opacity-100">Sites</span>
                    </a>
                    <a href="{{ route('app.settings') }}" data-sidebar-item data-sidebar-title="Settings" class="group relative flex h-9 items-center justify-start gap-3 rounded-md px-3 text-sm font-medium transition-all {{ $settingsNavActive ? 'pl-work-sidebar-active' : 'text-textSecondary hover:bg-surfaceMuted hover:text-textPrimary' }}">
                        <span data-sidebar-icon-wrap class="flex h-5 w-5 shrink-0 items-center justify-center"><i data-lucide="settings" class="h-4 w-4"></i></span>
                        <span data-sidebar-label class="truncate">Settings</span>
                        <span data-sidebar-tooltip class="pointer-events-none absolute left-full top-1/2 z-50 ml-2 hidden -translate-y-1/2 whitespace-nowrap rounded-md border border-border bg-surface px-2 py-1 text-xs text-textPrimary opacity-0 transition-opacity duration-150 group-hover:opacity-100">Settings</span>
                    </a>
                </div>
            </div>

            @if ($advancedModeEnabled)
                <div class="pl-work-sidebar-section">
                    <p data-sidebar-label class="px-2 pb-1 text-xs font-medium uppercase tracking-wide text-textFaint">ADVANCED</p>
                    <div class="space-y-1">
                        <a href="{{ route('app.setup.index') }}" data-sidebar-item data-sidebar-title="Setup" class="group relative flex h-9 items-center justify-start gap-3 rounded-md px-3 text-sm font-medium transition-all {{ request()->routeIs('app.setup.*') ? 'pl-work-sidebar-active' : 'text-textSecondary hover:bg-surfaceMuted hover:text-textPrimary' }}"><span data-sidebar-icon-wrap class="flex h-5 w-5 shrink-0 items-center justify-center"><i data-lucide="list-checks" class="h-4 w-4"></i></span><span data-sidebar-label class="truncate">Setup</span><span data-sidebar-tooltip class="pointer-events-none absolute left-full top-1/2 z-50 ml-2 hidden -translate-y-1/2 whitespace-nowrap rounded-md border border-border bg-surface px-2 py-1 text-xs text-textPrimary opacity-0 transition-opacity duration-150 group-hover:opacity-100">Setup</span></a>
                        <a href="{{ route('app.activation.index') }}" data-sidebar-item data-sidebar-title="Activation" class="group relative flex h-9 items-center justify-start gap-3 rounded-md px-3 text-sm font-medium transition-all {{ request()->routeIs('app.activation.*') ? 'pl-work-sidebar-active' : 'text-textSecondary hover:bg-surfaceMuted hover:text-textPrimary' }}"><span data-sidebar-icon-wrap class="flex h-5 w-5 shrink-0 items-center justify-center"><i data-lucide="rocket" class="h-4 w-4"></i></span><span data-sidebar-label class="truncate">Activation</span><span data-sidebar-tooltip class="pointer-events-none absolute left-full top-1/2 z-50 ml-2 hidden -translate-y-1/2 whitespace-nowrap rounded-md border border-border bg-surface px-2 py-1 text-xs text-textPrimary opacity-0 transition-opacity duration-150 group-hover:opacity-100">Activation</span></a>
                        <a href="{{ route('app.content.lifecycle.index') }}" data-sidebar-item data-sidebar-title="Lifecycle" class="group relative flex h-9 items-center justify-start gap-3 rounded-md px-3 text-sm font-medium transition-all {{ request()->routeIs('app.content.lifecycle*') ? 'pl-work-sidebar-active' : 'text-textSecondary hover:bg-surfaceMuted hover:text-textPrimary' }}"><span data-sidebar-icon-wrap class="flex h-5 w-5 shrink-0 items-center justify-center"><i data-lucide="git-branch" class="h-4 w-4"></i></span><span data-sidebar-label class="truncate">Lifecycle</span><span data-sidebar-tooltip class="pointer-events-none absolute left-full top-1/2 z-50 ml-2 hidden -translate-y-1/2 whitespace-nowrap rounded-md border border-border bg-surface px-2 py-1 text-xs text-textPrimary opacity-0 transition-opacity duration-150 group-hover:opacity-100">Lifecycle</span></a>
                        @if ($contentIntelligenceWorkspace)
                            <a href="{{ route('app.workspaces.content-quality.index', $contentIntelligenceWorkspace) }}" data-sidebar-item data-sidebar-title="Content Health" class="group relative flex h-9 items-center justify-start gap-3 rounded-md px-3 text-sm font-medium transition-all {{ request()->routeIs('app.workspaces.content-quality.*') ? 'pl-work-sidebar-active' : 'text-textSecondary hover:bg-surfaceMuted hover:text-textPrimary' }}"><span data-sidebar-icon-wrap class="flex h-5 w-5 shrink-0 items-center justify-center"><i data-lucide="scan-search" class="h-4 w-4"></i></span><span data-sidebar-label class="truncate">Content Health</span><span data-sidebar-tooltip class="pointer-events-none absolute left-full top-1/2 z-50 ml-2 hidden -translate-y-1/2 whitespace-nowrap rounded-md border border-border bg-surface px-2 py-1 text-xs text-textPrimary opacity-0 transition-opacity duration-150 group-hover:opacity-100">Content Health</span></a>
                        @endif
                        @if ($agenticMarketingFlag)
                            <a href="{{ route('app.agentic-marketing.index') }}" data-sidebar-item data-sidebar-title="Growth Agent" class="group relative flex h-9 items-center justify-start gap-3 rounded-md px-3 text-sm font-medium transition-all {{ request()->routeIs('app.agentic-marketing.index') ? 'pl-work-sidebar-active' : 'text-textSecondary hover:bg-surfaceMuted hover:text-textPrimary' }}"><span data-sidebar-icon-wrap class="flex h-5 w-5 shrink-0 items-center justify-center"><i data-lucide="workflow" class="h-4 w-4"></i></span><span data-sidebar-label class="truncate">Growth Agent</span><span data-sidebar-tooltip class="pointer-events-none absolute left-full top-1/2 z-50 ml-2 hidden -translate-y-1/2 whitespace-nowrap rounded-md border border-border bg-surface px-2 py-1 text-xs text-textPrimary opacity-0 transition-opacity duration-150 group-hover:opacity-100">Growth Agent</span></a>
                            <a href="{{ route('app.agentic-marketing.approvals.index') }}" data-sidebar-item data-sidebar-title="Decision Inbox" class="group relative flex h-9 items-center justify-start gap-3 rounded-md px-3 text-sm font-medium transition-all {{ request()->routeIs('app.agentic-marketing.approvals.*') ? 'pl-work-sidebar-active' : 'text-textSecondary hover:bg-surfaceMuted hover:text-textPrimary' }}"><span data-sidebar-icon-wrap class="flex h-5 w-5 shrink-0 items-center justify-center"><i data-lucide="check-check" class="h-4 w-4"></i></span><span data-sidebar-label class="truncate">Decision Inbox</span><span data-sidebar-tooltip class="pointer-events-none absolute left-full top-1/2 z-50 ml-2 hidden -translate-y-1/2 whitespace-nowrap rounded-md border border-border bg-surface px-2 py-1 text-xs text-textPrimary opacity-0 transition-opacity duration-150 group-hover:opacity-100">Decision Inbox</span></a>
                            <a href="{{ route('app.agentic-marketing.campaign-planner.index') }}" data-sidebar-item data-sidebar-title="Planner" class="group relative flex h-9 items-center justify-start gap-3 rounded-md px-3 text-sm font-medium transition-all {{ request()->routeIs('app.agentic-marketing.campaign-planner.*') ? 'pl-work-sidebar-active' : 'text-textSecondary hover:bg-surfaceMuted hover:text-textPrimary' }}"><span data-sidebar-icon-wrap class="flex h-5 w-5 shrink-0 items-center justify-center"><i data-lucide="map" class="h-4 w-4"></i></span><span data-sidebar-label class="truncate">Planner</span><span data-sidebar-tooltip class="pointer-events-none absolute left-full top-1/2 z-50 ml-2 hidden -translate-y-1/2 whitespace-nowrap rounded-md border border-border bg-surface px-2 py-1 text-xs text-textPrimary opacity-0 transition-opacity duration-150 group-hover:opacity-100">Planner</span></a>
                            <a href="{{ route('app.agentic-marketing.distribution.index') }}" data-sidebar-item data-sidebar-title="Distribution" class="group relative flex h-9 items-center justify-start gap-3 rounded-md px-3 text-sm font-medium transition-all {{ request()->routeIs('app.agentic-marketing.distribution.*') ? 'pl-work-sidebar-active' : 'text-textSecondary hover:bg-surfaceMuted hover:text-textPrimary' }}"><span data-sidebar-icon-wrap class="flex h-5 w-5 shrink-0 items-center justify-center"><i data-lucide="send" class="h-4 w-4"></i></span><span data-sidebar-label class="truncate">Distribution</span><span data-sidebar-tooltip class="pointer-events-none absolute left-full top-1/2 z-50 ml-2 hidden -translate-y-1/2 whitespace-nowrap rounded-md border border-border bg-surface px-2 py-1 text-xs text-textPrimary opacity-0 transition-opacity duration-150 group-hover:opacity-100">Distribution</span></a>
                            <a href="{{ route('app.agentic-marketing.learning.index') }}" data-sidebar-item data-sidebar-title="Learning" class="group relative flex h-9 items-center justify-start gap-3 rounded-md px-3 text-sm font-medium transition-all {{ request()->routeIs('app.agentic-marketing.learning.*') ? 'pl-work-sidebar-active' : 'text-textSecondary hover:bg-surfaceMuted hover:text-textPrimary' }}"><span data-sidebar-icon-wrap class="flex h-5 w-5 shrink-0 items-center justify-center"><i data-lucide="chart-no-axes-combined" class="h-4 w-4"></i></span><span data-sidebar-label class="truncate">Learning</span><span data-sidebar-tooltip class="pointer-events-none absolute left-full top-1/2 z-50 ml-2 hidden -translate-y-1/2 whitespace-nowrap rounded-md border border-border bg-surface px-2 py-1 text-xs text-textPrimary opacity-0 transition-opacity duration-150 group-hover:opacity-100">Learning</span></a>
                            <a href="{{ route('app.agentic-marketing.workflows.index') }}" data-sidebar-item data-sidebar-title="Workflows" class="group relative flex h-9 items-center justify-start gap-3 rounded-md px-3 text-sm font-medium transition-all {{ request()->routeIs('app.agentic-marketing.workflows.*') ? 'pl-work-sidebar-active' : 'text-textSecondary hover:bg-surfaceMuted hover:text-textPrimary' }}"><span data-sidebar-icon-wrap class="flex h-5 w-5 shrink-0 items-center justify-center"><i data-lucide="route" class="h-4 w-4"></i></span><span data-sidebar-label class="truncate">Workflows</span><span data-sidebar-tooltip class="pointer-events-none absolute left-full top-1/2 z-50 ml-2 hidden -translate-y-1/2 whitespace-nowrap rounded-md border border-border bg-surface px-2 py-1 text-xs text-textPrimary opacity-0 transition-opacity duration-150 group-hover:opacity-100">Workflows</span></a>
                        @endif
                        @if ($signalIntelligenceFlag && $contentIntelligenceWorkspace)
                            <a href="{{ route('app.signal-intelligence.index') }}" data-sidebar-item data-sidebar-title="Market Monitoring" class="group relative flex h-9 items-center justify-start gap-3 rounded-md px-3 text-sm font-medium transition-all {{ request()->routeIs('app.signal-intelligence.*') ? 'pl-work-sidebar-active' : 'text-textSecondary hover:bg-surfaceMuted hover:text-textPrimary' }}"><span data-sidebar-icon-wrap class="flex h-5 w-5 shrink-0 items-center justify-center"><i data-lucide="activity" class="h-4 w-4"></i></span><span data-sidebar-label class="truncate">Market Monitoring</span><span data-sidebar-tooltip class="pointer-events-none absolute left-full top-1/2 z-50 ml-2 hidden -translate-y-1/2 whitespace-nowrap rounded-md border border-border bg-surface px-2 py-1 text-xs text-textPrimary opacity-0 transition-opacity duration-150 group-hover:opacity-100">Market Monitoring</span></a>
                            <a href="{{ route('app.opportunity-review.index') }}" data-sidebar-item data-sidebar-title="Opportunity Review" class="group relative flex h-9 items-center justify-start gap-3 rounded-md px-3 text-sm font-medium transition-all {{ request()->routeIs('app.opportunity-review.*') ? 'pl-work-sidebar-active' : 'text-textSecondary hover:bg-surfaceMuted hover:text-textPrimary' }}"><span data-sidebar-icon-wrap class="flex h-5 w-5 shrink-0 items-center justify-center"><i data-lucide="eye" class="h-4 w-4"></i></span><span data-sidebar-label class="truncate">Opportunity Review</span><span data-sidebar-tooltip class="pointer-events-none absolute left-full top-1/2 z-50 ml-2 hidden -translate-y-1/2 whitespace-nowrap rounded-md border border-border bg-surface px-2 py-1 text-xs text-textPrimary opacity-0 transition-opacity duration-150 group-hover:opacity-100">Opportunity Review</span></a>
                        @endif
                        @if ($researchLayerFlag)
                            <a href="{{ route('app.research.index') }}" data-sidebar-item data-sidebar-title="Research" class="group relative flex h-9 items-center justify-start gap-3 rounded-md px-3 text-sm font-medium transition-all {{ request()->routeIs('app.research*') ? 'pl-work-sidebar-active' : 'text-textSecondary hover:bg-surfaceMuted hover:text-textPrimary' }}"><span data-sidebar-icon-wrap class="flex h-5 w-5 shrink-0 items-center justify-center"><i data-lucide="search-check" class="h-4 w-4"></i></span><span data-sidebar-label class="truncate">Research</span><span data-sidebar-tooltip class="pointer-events-none absolute left-full top-1/2 z-50 ml-2 hidden -translate-y-1/2 whitespace-nowrap rounded-md border border-border bg-surface px-2 py-1 text-xs text-textPrimary opacity-0 transition-opacity duration-150 group-hover:opacity-100">Research</span></a>
                        @endif
                        @if ($agenticMarketingFlag && $advancedModeCanSeeWorkflowInternals)
                            <details data-sidebar-label class="rounded-md" @if($programmaticGrowthNavActive) open @endif>
                                <summary class="group relative flex h-9 cursor-pointer list-none items-center justify-start gap-3 rounded-md px-3 text-sm font-medium transition-all {{ $programmaticGrowthNavActive ? 'pl-work-sidebar-active' : 'text-textSecondary hover:bg-surfaceMuted hover:text-textPrimary' }}"><span class="flex h-5 w-5 shrink-0 items-center justify-center"><i data-lucide="network" class="h-4 w-4"></i></span><span class="truncate">Scale Content</span></summary>
                                <div class="ml-8 mt-1 space-y-1 border-l border-border pl-2 text-xs">
                                    <a href="{{ route('app.growth-programs.index') }}" class="block rounded px-2 py-1 {{ request()->routeIs('app.growth-programs.*') ? 'text-primary' : 'text-textSecondary hover:text-textPrimary' }}">Growth Programs</a>
                                    <a href="{{ route('app.programmatic-opportunities.index') }}" class="block rounded px-2 py-1 {{ request()->routeIs('app.programmatic-opportunities.*') ? 'text-primary' : 'text-textSecondary hover:text-textPrimary' }}">Programmatic Opportunities</a>
                                    <a href="{{ route('app.programmatic-clusters.index') }}" class="block rounded px-2 py-1 {{ request()->routeIs('app.programmatic-clusters.*') ? 'text-primary' : 'text-textSecondary hover:text-textPrimary' }}">Topic Groups</a>
                                    <a href="{{ route('app.programmatic-brief-blueprints.index') }}" class="block rounded px-2 py-1 {{ request()->routeIs('app.programmatic-brief-blueprints.*') ? 'text-primary' : 'text-textSecondary hover:text-textPrimary' }}">Planned Briefs</a>
                                    <a href="{{ route('app.programmatic-draft-requests.index') }}" class="block rounded px-2 py-1 {{ request()->routeIs('app.programmatic-draft-requests.*') ? 'text-primary' : 'text-textSecondary hover:text-textPrimary' }}">Queued Drafts</a>
                                    <a href="{{ route('app.programmatic-draft-reviews.index') }}" class="block rounded px-2 py-1 {{ request()->routeIs('app.programmatic-draft-reviews.*') ? 'text-primary' : 'text-textSecondary hover:text-textPrimary' }}">Content Reviews</a>
                                    <a href="{{ route('app.programmatic-publication-readiness.index') }}" class="block rounded px-2 py-1 {{ request()->routeIs('app.programmatic-publication-readiness.*') ? 'text-primary' : 'text-textSecondary hover:text-textPrimary' }}">Publish Checks</a>
                                    <a href="{{ route('app.programmatic-publication-plans.index') }}" class="block rounded px-2 py-1 {{ request()->routeIs('app.programmatic-publication-plans.*') ? 'text-primary' : 'text-textSecondary hover:text-textPrimary' }}">Publishing Schedule</a>
                                    @if (auth()->user()?->is_admin || in_array((string) auth()->user()?->role, ['owner', 'admin'], true))
                                        <a href="{{ route('app.programmatic-growth.beta-report') }}" class="block rounded px-2 py-1 {{ request()->routeIs('app.programmatic-growth.beta-report') ? 'text-primary' : 'text-textSecondary hover:text-textPrimary' }}">Beta Report</a>
                                    @endif
                                </div>
                            </details>
                        @endif
                        <a href="{{ route('app.workspace-intelligence.index') }}" data-sidebar-item data-sidebar-title="AI Brand Setup" class="group relative flex h-9 items-center justify-start gap-3 rounded-md px-3 text-sm font-medium transition-all {{ request()->routeIs('app.workspace-intelligence.*') ? 'pl-work-sidebar-active' : 'text-textSecondary hover:bg-surfaceMuted hover:text-textPrimary' }}"><span data-sidebar-icon-wrap class="flex h-5 w-5 shrink-0 items-center justify-center"><i data-lucide="sparkles" class="h-4 w-4"></i></span><span data-sidebar-label class="truncate">AI Brand Setup</span><span data-sidebar-tooltip class="pointer-events-none absolute left-full top-1/2 z-50 ml-2 hidden -translate-y-1/2 whitespace-nowrap rounded-md border border-border bg-surface px-2 py-1 text-xs text-textPrimary opacity-0 transition-opacity duration-150 group-hover:opacity-100">AI Brand Setup</span></a>
                        @if ($advancedModeCanSeeOrganizationControls)
                            <a href="{{ route('app.billing.index') }}" data-sidebar-item data-sidebar-title="Billing" class="group relative flex h-9 items-center justify-start gap-3 rounded-md px-3 text-sm font-medium transition-all {{ request()->routeIs('app.billing.*') ? 'pl-work-sidebar-active' : 'text-textSecondary hover:bg-surfaceMuted hover:text-textPrimary' }}"><span data-sidebar-icon-wrap class="flex h-5 w-5 shrink-0 items-center justify-center"><i data-lucide="wallet" class="h-4 w-4"></i></span><span data-sidebar-label class="truncate">Billing</span><span data-sidebar-tooltip class="pointer-events-none absolute left-full top-1/2 z-50 ml-2 hidden -translate-y-1/2 whitespace-nowrap rounded-md border border-border bg-surface px-2 py-1 text-xs text-textPrimary opacity-0 transition-opacity duration-150 group-hover:opacity-100">Billing</span></a>
                        @endif
                        @if ($advancedModeCanSeeDeveloperTools)
                            <a href="{{ route('app.developer.index') }}" data-sidebar-item data-sidebar-title="Developer" class="group relative flex h-9 items-center justify-start gap-3 rounded-md px-3 text-sm font-medium transition-all {{ request()->routeIs('app.developer.*') ? 'pl-work-sidebar-active' : 'text-textSecondary hover:bg-surfaceMuted hover:text-textPrimary' }}"><span data-sidebar-icon-wrap class="flex h-5 w-5 shrink-0 items-center justify-center"><i data-lucide="code-2" class="h-4 w-4"></i></span><span data-sidebar-label class="truncate">Developer</span><span data-sidebar-tooltip class="pointer-events-none absolute left-full top-1/2 z-50 ml-2 hidden -translate-y-1/2 whitespace-nowrap rounded-md border border-border bg-surface px-2 py-1 text-xs text-textPrimary opacity-0 transition-opacity duration-150 group-hover:opacity-100">Developer</span></a>
                        @endif
                    </div>
                </div>
            @endif
        </nav>

        <div class="space-y-2 border-t border-border p-2">
            @if ($advancedModeCanEnable)
                <form method="POST" action="{{ route('app.settings.advanced-mode.update') }}">
                    @csrf
                    <input type="hidden" name="advanced_mode" value="{{ $advancedModeEnabled ? '0' : '1' }}">
                    <button type="submit" data-sidebar-item data-sidebar-title="Advanced Mode" class="group relative flex h-9 w-full items-center justify-start gap-3 rounded-md px-3 text-sm font-medium {{ $advancedModeEnabled ? 'border border-primary/25 bg-primarySoftBg text-textPrimary' : 'text-textSecondary hover:bg-surfaceMuted hover:text-textPrimary' }}">
                        <span data-sidebar-icon-wrap class="flex h-5 w-5 shrink-0 items-center justify-center">
                            <i data-lucide="sliders-horizontal" class="h-4 w-4"></i>
                        </span>
                        <span data-sidebar-label class="truncate">Advanced {{ $advancedModeEnabled ? 'on' : 'off' }}</span>
                        <span data-sidebar-tooltip class="pointer-events-none absolute left-full top-1/2 z-50 ml-2 hidden -translate-y-1/2 whitespace-nowrap rounded-md border border-border bg-surface px-2 py-1 text-xs text-textPrimary opacity-0 transition-opacity duration-150 group-hover:opacity-100">Advanced Mode</span>
                    </button>
                </form>
            @endif
            <button id="collapseBtn" data-sidebar-item data-sidebar-title="Collapse sidebar" class="group relative flex h-9 w-full items-center justify-start gap-3 rounded-md px-3 text-sm font-medium text-textSecondary hover:bg-surfaceMuted">
                <span data-sidebar-icon-wrap class="flex h-5 w-5 shrink-0 items-center justify-center">
                    <i data-collapse-icon="expanded" data-lucide="panel-left-close" class="h-4 w-4"></i>
                    <i data-collapse-icon="collapsed" data-lucide="panel-left-open" class="hidden h-4 w-4"></i>
                </span>
                <span data-sidebar-label class="truncate">Collapse</span>
                <span data-sidebar-tooltip class="pointer-events-none absolute left-full top-1/2 z-50 ml-2 hidden -translate-y-1/2 whitespace-nowrap rounded-md border border-border bg-surface px-2 py-1 text-xs text-textPrimary opacity-0 transition-opacity duration-150 group-hover:opacity-100">Expand sidebar</span>
            </button>
        </div>
    </aside>

    <div class="flex flex-1 flex-col min-w-0">
        <header class="pl-work-header">
            @if ($impersonationActive)
                <div class="pl-work-impersonation">
                    <div class="flex min-w-0 items-center gap-2">
                        <i data-lucide="shield-user" class="h-4 w-4 shrink-0"></i>
                        <span class="min-w-0 truncate font-semibold">
                            Impersonation active: {{ $impersonatorName }} is viewing {{ $impersonationLabel }} as {{ $impersonationTargetName }}.
                        </span>
                    </div>
                    <form method="POST" action="{{ route('impersonation.stop') }}" class="shrink-0">
                        @csrf
                        <button type="submit" class="inline-flex h-9 items-center rounded-md border border-amber-300 bg-white px-4 text-sm font-semibold text-amber-900 transition-colors hover:bg-amber-100">
                            Stop impersonating
                        </button>
                    </form>
                </div>
            @endif
            <div class="pl-work-topbar">
                <div class="flex min-w-0 flex-1 items-center gap-3">
                    <button id="mobileMenuBtn" class="inline-flex items-center justify-center h-9 w-9 rounded-md border border-border text-textSecondary hover:bg-surfaceMuted lg:hidden">
                        <i data-lucide="menu" class="h-4 w-4"></i>
                    </button>
                    <div class="flex min-w-0 items-center gap-2">
                        <span class="pl-work-chip">{{ auth()->user()?->organization?->name ?? 'Workspace' }}</span>
                        @if (! empty($appAccessOverrideBanner))
                            <span class="inline-flex h-9 shrink-0 items-center gap-1.5 rounded-md border border-primary/20 bg-primarySoftBg px-2.5 text-xs font-medium text-primary">
                                <i data-lucide="shield-check" class="h-3.5 w-3.5"></i>
                                <span class="hidden sm:inline">{{ $appAccessOverrideBanner['label'] }}</span>
                            </span>
                        @endif
                        <span class="pl-work-chip-muted hidden max-w-[24rem] truncate sm:inline-flex" title="{{ $title ?? 'Workspace' }}">{{ $title ?? 'Workspace' }}</span>
                    </div>
                </div>
                <div class="flex w-full items-center justify-end gap-2 sm:w-auto sm:flex-1">
                    @php
                        $notificationBell = $appNotificationBell ?? ['workspace_id' => null, 'unread_count' => 0, 'recent' => collect()];
                        $notificationBellUnreadCount = (int) ($notificationBell['unread_count'] ?? 0);
                    @endphp
                    @if (($appCreditNav['available'] ?? null) !== null)
                        @php
                            $creditNavIsLow = (bool) ($appCreditNav['is_low'] ?? false);
                            $creditNavCanTopUp = ($appCreditNav['show_upgrade'] ?? false) === true;
                            $creditTopUpUrl = (string) ($appCreditNav['top_up_url'] ?? route('app.billing.index').'#buy-credit-packs');
                            $creditNavBaseClass = $creditNavIsLow
                                ? 'border-rose-300 bg-rose-50 text-rose-800 hover:bg-rose-100'
                                : 'border-border bg-surface text-textSecondary hover:bg-surfaceMuted';
                        @endphp
                        <div class="inline-flex h-9 items-stretch">
                            <a
                                href="{{ route('app.billing.index') }}"
                                class="inline-flex items-center gap-2 border px-2.5 text-sm {{ $creditNavBaseClass }} {{ $creditNavCanTopUp ? 'rounded-l-md border-r-0' : 'rounded-md' }}"
                                title="{{ $creditNavIsLow ? 'Credits are running low' : 'Available credits' }}"
                            >
                                <i data-lucide="coins" class="h-4 w-4"></i>
                                <span class="hidden sm:inline">Credits</span>
                                <span class="font-semibold">{{ number_format((int) ($appCreditNav['available'] ?? 0)) }}</span>
                            </a>
                            @if ($creditNavCanTopUp)
                                <a
                                    href="{{ $creditTopUpUrl }}"
                                    class="inline-flex w-9 items-center justify-center rounded-r-md border text-sm {{ $creditNavIsLow ? 'border-rose-300 bg-rose-100 text-rose-900 hover:bg-rose-200' : 'border-border bg-surface text-textSecondary hover:bg-surfaceMuted' }}"
                                    title="Add credits"
                                    aria-label="Add credits"
                                >
                                    <i data-lucide="plus" class="h-4 w-4"></i>
                                </a>
                            @endif
                        </div>
                    @endif
                    <div class="relative hidden min-w-[18rem] flex-1 md:block">
                        <form method="GET" action="{{ route('app.search') }}" class="relative">
                            <i data-lucide="search" class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-textFaint"></i>
                            <input
                                type="text"
                                name="q"
                                value="{{ (string) request()->query('q', '') }}"
                                class="pl-search w-full"
                                placeholder="Search content, campaigns, contacts, topics..."
                                aria-label="Search"
                                autocomplete="off"
                                data-global-search
                                data-search-endpoint="{{ route('app.search.suggest') }}"
                            >
                            <div class="absolute left-0 right-0 top-11 z-50 hidden overflow-hidden rounded-md border border-border bg-surface" data-search-dropdown>
                                <div class="max-h-80 overflow-auto py-1" data-search-results></div>
                                <a href="{{ route('app.search') }}" class="block border-t border-border px-3 py-2 text-xs font-medium text-textSecondary hover:bg-surfaceSubtle" data-search-all-link>View all results</a>
                            </div>
                        </form>
                    </div>
                    <div class="relative" data-notification-bell>
                        <button id="notificationBellBtn" type="button" class="relative pl-icon-btn" aria-label="Notifications" data-notification-bell-toggle>
                            <i data-lucide="bell" class="h-4 w-4"></i>
                            @if ($notificationBellUnreadCount > 0)
                                <span
                                    data-notification-bell-badge
                                    class="absolute -right-1 -top-1 inline-flex min-h-4 min-w-4 items-center justify-center rounded-full bg-primary px-1 text-[10px] font-semibold text-white"
                                >{{ min(99, $notificationBellUnreadCount) }}</span>
                            @endif
                        </button>
                        <div id="notificationBellMenu" data-notification-bell-menu class="pl-elevation-overlay hidden absolute right-0 top-11 z-50 w-96 max-w-[calc(100vw-2rem)] overflow-hidden rounded-md border border-border bg-surface">
                            <p data-notification-bell-error class="hidden border-b border-danger/20 bg-danger/5 px-3 py-2 text-xs text-danger" role="alert"></p>
                            <div data-notification-bell-content>
                                @include('partials.notifications.app-bell-menu', ['notificationBell' => $notificationBell])
                            </div>
                            <a href="{{ route('app.notifications.index', array_filter(['workspace_id' => $notificationBell['workspace_id'] ?? null])) }}" class="block border-t border-border px-3 py-2 text-xs font-medium text-textSecondary hover:bg-surfaceSubtle">
                                View all notifications
                            </a>
                        </div>
                    </div>
                    @php
                        $appLocale = (string) ($appLang ?? app()->getLocale());
                        $languageSwitchQuery = request()->query();
                        unset($languageSwitchQuery['lang'], $languageSwitchQuery['selected_date']);
                        $languageSwitchBaseUrl = url()->current();
                        $languageSwitchToNl = $languageSwitchBaseUrl.(count($languageSwitchQuery) || $appLocale !== 'nl' ? '?'.http_build_query(array_merge($languageSwitchQuery, ['lang' => 'nl'])) : '');
                        $languageSwitchToEn = $languageSwitchBaseUrl.(count($languageSwitchQuery) || $appLocale !== 'en' ? '?'.http_build_query(array_merge($languageSwitchQuery, ['lang' => 'en'])) : '');
                    @endphp
                    <select class="pl-work-select hidden md:block" onchange="if (this.value) window.location.href = this.value;" aria-label="{{ __('app.runtime.Language') }}">
                        <option value="{{ $languageSwitchToEn }}" @selected($appLocale === 'en')>English</option>
                        <option value="{{ $languageSwitchToNl }}" @selected($appLocale === 'nl')>Nederlands</option>
                    </select>
                    <button id="userMenuBtn" type="button" class="pl-work-avatar" aria-label="User menu">
                        {{ strtoupper(substr(auth()->user()->name ?? 'U', 0, 2)) }}
                    </button>
                    <div id="userMenu" class="hidden absolute right-4 {{ $impersonationActive ? 'top-[7.25rem]' : 'top-16' }} z-50 w-56 rounded-md border border-border bg-surface md:right-6">
                        <div class="p-1">
                            <a href="{{ route('app.settings') }}" class="flex items-center gap-2 rounded-md px-2 py-2 text-sm text-textSecondary hover:bg-surfaceMuted hover:text-textPrimary">
                                <i data-lucide="user" class="h-4 w-4"></i> {{ __('app.nav.profile') }}
                            </a>
                            <div class="my-1 h-px bg-divider"></div>
                            <form method="POST" action="{{ route('logout') }}">
                                @csrf
                                <button type="submit" class="flex w-full items-center gap-2 rounded-md px-2 py-2 text-sm text-danger hover:bg-surfaceMuted">
                                    <i data-lucide="log-out" class="h-4 w-4"></i> {{ __('app.nav.logout') }}
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </header>

        <div id="mobileSidebar" class="hidden fixed inset-0 z-50 lg:hidden">
            <div class="fixed inset-0 bg-black/35" id="mobileOverlay"></div>
            <div class="fixed inset-y-0 left-0 w-72 bg-surface border-r border-border z-50">
                <div class="flex h-14 items-center justify-between border-b border-border px-4">
                    <div class="flex items-center gap-2">
                        <x-brand-logo :show-text="false" />
                        <div class="leading-tight">
                            <span class="block text-sm font-semibold text-textPrimary">{{ \App\Support\Brand::product() }}</span>
                            @if (config('brand.show_parent_branding', true))
                                <span class="block text-[10px] text-textMuted">by {{ \App\Support\Brand::parentLinked() }}</span>
                            @endif
                        </div>
                    </div>
                    <button id="closeMobileMenu" class="pl-icon-btn border-0" aria-label="Close menu">
                        <i data-lucide="x" class="h-4 w-4"></i>
                    </button>
                </div>
                <nav class="space-y-3 p-4">
                    <div>
                        <p class="px-3 pb-1 text-xs font-medium uppercase tracking-wide text-textFaint">GROWTH</p>
                        <div class="space-y-1">
                            <a href="{{ route('app.dashboard') }}" class="flex items-center gap-3 rounded-md px-3 py-2 text-sm {{ request()->routeIs('app.dashboard') || request()->routeIs('app.activation.*') || request()->routeIs('app.setup.*') ? 'pl-work-sidebar-active' : 'text-textSecondary hover:bg-surfaceMuted hover:text-textPrimary' }}"><i data-lucide="layout-dashboard" class="h-4 w-4"></i> Home</a>
                            <a href="{{ route('app.recommended-actions.index') }}" class="flex items-center gap-3 rounded-md px-3 py-2 text-sm {{ request()->routeIs('app.recommended-actions.*') ? 'pl-work-sidebar-active' : 'text-textSecondary hover:bg-surfaceMuted hover:text-textPrimary' }}"><i data-lucide="list-checks" class="h-4 w-4"></i> Actions</a>
                            <a href="{{ $opportunitiesRoute }}" class="flex items-center gap-3 rounded-md px-3 py-2 text-sm {{ $opportunitiesNavActive ? 'pl-work-sidebar-active' : 'text-textSecondary hover:bg-surfaceMuted hover:text-textPrimary' }}"><i data-lucide="radar" class="h-4 w-4"></i> Opportunities</a>
                            <a href="{{ route('app.content.pipeline.index') }}" class="flex items-center gap-3 rounded-md px-3 py-2 text-sm {{ $contentNavActive ? 'pl-work-sidebar-active' : 'text-textSecondary hover:bg-surfaceMuted hover:text-textPrimary' }}"><i data-lucide="folder-kanban" class="h-4 w-4"></i> Content</a>
                            <a href="{{ route('app.insights.index') }}" class="flex items-center gap-3 rounded-md px-3 py-2 text-sm {{ $resultsNavActive ? 'pl-work-sidebar-active' : 'text-textSecondary hover:bg-surfaceMuted hover:text-textPrimary' }}"><i data-lucide="line-chart" class="h-4 w-4"></i> Results</a>
                        </div>
                    </div>
                    <div>
                        <p class="px-3 pb-1 text-xs font-medium uppercase tracking-wide text-textFaint">WORKSPACE</p>
                        <div class="space-y-1">
                            <a href="{{ route('app.brand.company-profile') }}" class="flex items-center gap-3 rounded-md px-3 py-2 text-sm {{ $brandNavActive ? 'pl-work-sidebar-active' : 'text-textSecondary hover:bg-surfaceMuted hover:text-textPrimary' }}"><i data-lucide="palette" class="h-4 w-4"></i> Brand</a>
                            <a href="{{ route('app.sites') }}" class="flex items-center gap-3 rounded-md px-3 py-2 text-sm {{ $sitesNavActive ? 'pl-work-sidebar-active' : 'text-textSecondary hover:bg-surfaceMuted hover:text-textPrimary' }}"><i data-lucide="globe" class="h-4 w-4"></i> Sites</a>
                            <a href="{{ route('app.settings') }}" class="flex items-center gap-3 rounded-md px-3 py-2 text-sm {{ $settingsNavActive ? 'pl-work-sidebar-active' : 'text-textSecondary hover:bg-surfaceMuted hover:text-textPrimary' }}"><i data-lucide="settings" class="h-4 w-4"></i> Settings</a>
                        </div>
                    </div>
                    @if ($advancedModeEnabled)
                        <div>
                            <p class="px-3 pb-1 text-xs font-medium uppercase tracking-wide text-textFaint">ADVANCED</p>
                            <div class="space-y-1">
                                <a href="{{ route('app.setup.index') }}" class="flex items-center gap-3 rounded-md px-3 py-2 text-sm {{ request()->routeIs('app.setup.*') ? 'pl-work-sidebar-active' : 'text-textSecondary hover:bg-surfaceMuted hover:text-textPrimary' }}"><i data-lucide="list-checks" class="h-4 w-4"></i> Setup</a>
                                <a href="{{ route('app.activation.index') }}" class="flex items-center gap-3 rounded-md px-3 py-2 text-sm {{ request()->routeIs('app.activation.*') ? 'pl-work-sidebar-active' : 'text-textSecondary hover:bg-surfaceMuted hover:text-textPrimary' }}"><i data-lucide="rocket" class="h-4 w-4"></i> Activation</a>
                                <a href="{{ route('app.content.lifecycle.index') }}" class="flex items-center gap-3 rounded-md px-3 py-2 text-sm {{ request()->routeIs('app.content.lifecycle*') ? 'pl-work-sidebar-active' : 'text-textSecondary hover:bg-surfaceMuted hover:text-textPrimary' }}"><i data-lucide="git-branch" class="h-4 w-4"></i> Lifecycle</a>
                                @if ($contentIntelligenceWorkspace)
                                    <a href="{{ route('app.workspaces.content-quality.index', $contentIntelligenceWorkspace) }}" class="flex items-center gap-3 rounded-md px-3 py-2 text-sm {{ request()->routeIs('app.workspaces.content-quality.*') ? 'pl-work-sidebar-active' : 'text-textSecondary hover:bg-surfaceMuted hover:text-textPrimary' }}"><i data-lucide="scan-search" class="h-4 w-4"></i> Content Health</a>
                                @endif
                                @if ($agenticMarketingFlag)
                                    <a href="{{ route('app.agentic-marketing.index') }}" class="flex items-center gap-3 rounded-md px-3 py-2 text-sm {{ request()->routeIs('app.agentic-marketing.index') ? 'pl-work-sidebar-active' : 'text-textSecondary hover:bg-surfaceMuted hover:text-textPrimary' }}"><i data-lucide="workflow" class="h-4 w-4"></i> Growth Agent</a>
                                    <a href="{{ route('app.agentic-marketing.approvals.index') }}" class="flex items-center gap-3 rounded-md px-3 py-2 text-sm {{ request()->routeIs('app.agentic-marketing.approvals.*') ? 'pl-work-sidebar-active' : 'text-textSecondary hover:bg-surfaceMuted hover:text-textPrimary' }}"><i data-lucide="check-check" class="h-4 w-4"></i> Decision Inbox</a>
                                    <a href="{{ route('app.agentic-marketing.campaign-planner.index') }}" class="flex items-center gap-3 rounded-md px-3 py-2 text-sm {{ request()->routeIs('app.agentic-marketing.campaign-planner.*') ? 'pl-work-sidebar-active' : 'text-textSecondary hover:bg-surfaceMuted hover:text-textPrimary' }}"><i data-lucide="map" class="h-4 w-4"></i> Planner</a>
                                    <a href="{{ route('app.agentic-marketing.distribution.index') }}" class="flex items-center gap-3 rounded-md px-3 py-2 text-sm {{ request()->routeIs('app.agentic-marketing.distribution.*') ? 'pl-work-sidebar-active' : 'text-textSecondary hover:bg-surfaceMuted hover:text-textPrimary' }}"><i data-lucide="send" class="h-4 w-4"></i> Distribution</a>
                                    @if ($advancedModeCanSeeWorkflowInternals)
                                        <a href="{{ route('app.growth-programs.index') }}" class="flex items-center gap-3 rounded-md px-3 py-2 text-sm {{ $programmaticGrowthNavActive ? 'pl-work-sidebar-active' : 'text-textSecondary hover:bg-surfaceMuted hover:text-textPrimary' }}"><i data-lucide="network" class="h-4 w-4"></i> Scale Content</a>
                                    @endif
                                    <a href="{{ route('app.agentic-marketing.workflows.index') }}" class="flex items-center gap-3 rounded-md px-3 py-2 text-sm {{ request()->routeIs('app.agentic-marketing.workflows.*') ? 'pl-work-sidebar-active' : 'text-textSecondary hover:bg-surfaceMuted hover:text-textPrimary' }}"><i data-lucide="route" class="h-4 w-4"></i> Workflows</a>
                                @endif
                                @if ($signalIntelligenceFlag && $contentIntelligenceWorkspace)
                                    <a href="{{ route('app.signal-intelligence.index') }}" class="flex items-center gap-3 rounded-md px-3 py-2 text-sm {{ request()->routeIs('app.signal-intelligence.*') ? 'pl-work-sidebar-active' : 'text-textSecondary hover:bg-surfaceMuted hover:text-textPrimary' }}"><i data-lucide="activity" class="h-4 w-4"></i> Market Monitoring</a>
                                @endif
                                @if ($researchLayerFlag)
                                    <a href="{{ route('app.research.index') }}" class="flex items-center gap-3 rounded-md px-3 py-2 text-sm {{ request()->routeIs('app.research*') ? 'pl-work-sidebar-active' : 'text-textSecondary hover:bg-surfaceMuted hover:text-textPrimary' }}"><i data-lucide="search-check" class="h-4 w-4"></i> Research</a>
                                @endif
                                <a href="{{ route('app.workspace-intelligence.index') }}" class="flex items-center gap-3 rounded-md px-3 py-2 text-sm {{ request()->routeIs('app.workspace-intelligence.*') ? 'pl-work-sidebar-active' : 'text-textSecondary hover:bg-surfaceMuted hover:text-textPrimary' }}"><i data-lucide="sparkles" class="h-4 w-4"></i> AI Brand Setup</a>
                                @if ($advancedModeCanSeeOrganizationControls)
                                    <a href="{{ route('app.billing.index') }}" class="flex items-center gap-3 rounded-md px-3 py-2 text-sm {{ request()->routeIs('app.billing.*') ? 'pl-work-sidebar-active' : 'text-textSecondary hover:bg-surfaceMuted hover:text-textPrimary' }}"><i data-lucide="wallet" class="h-4 w-4"></i> Billing</a>
                                @endif
                                @if ($advancedModeCanSeeDeveloperTools)
                                    <a href="{{ route('app.developer.index') }}" class="flex items-center gap-3 rounded-md px-3 py-2 text-sm {{ request()->routeIs('app.developer.*') ? 'pl-work-sidebar-active' : 'text-textSecondary hover:bg-surfaceMuted hover:text-textPrimary' }}"><i data-lucide="code-2" class="h-4 w-4"></i> Developer</a>
                                @endif
                            </div>
                        </div>
                    @endif
                    @if ($advancedModeCanEnable)
                        <form method="POST" action="{{ route('app.settings.advanced-mode.update') }}" class="border-t border-border pt-3">
                            @csrf
                            <input type="hidden" name="advanced_mode" value="{{ $advancedModeEnabled ? '0' : '1' }}">
                            <button type="submit" class="flex w-full items-center gap-3 rounded-md px-3 py-2 text-sm {{ $advancedModeEnabled ? 'border border-primary/25 bg-primarySoftBg text-textPrimary' : 'text-textSecondary hover:bg-surfaceMuted hover:text-textPrimary' }}">
                                <i data-lucide="sliders-horizontal" class="h-4 w-4"></i>
                                Advanced {{ $advancedModeEnabled ? 'on' : 'off' }}
                            </button>
                        </form>
                    @endif
                </nav>
            </div>
        </div>

        <main class="flex-1 overflow-auto">
            <div class="{{ $pageShellClass }}">
                @php
                    $supportCtx = app(\App\Services\Support\SupportContext::class);
                @endphp
                @if ($supportCtx->isEnabled() && $supportCtx->targetUser() && $supportCtx->targetCompany())
                    <x-alert class="mb-4 md:items-center md:justify-between" :icon="true">
                        <x-slot:title>Support Mode active</x-slot:title>
                        Target: {{ $supportCtx->targetUser()->name }} at {{ $supportCtx->targetCompany()->name }}. Read only. Actions are blocked.
                        <x-slot:actions>
                            <a href="{{ route('admin.support.diagnostics') }}" class="inline-flex h-9 items-center rounded-md border border-accentYellow-900/25 text-accentYellow-900 px-3 text-xs font-medium text-accentYellow-900 hover:text-accentYellow-900/80">View diagnostics</a>
                            <a href="{{ route('admin.support.snapshot') }}" class="inline-flex h-9 items-center rounded-md border border-accentYellow-900/25 text-accentYellow-900 px-3 text-xs font-medium text-accentYellow-900 hover:text-accentYellow-900/80">Download snapshot</a>
                            <form method="POST" action="{{ route('admin.support.stop') }}">
                                @csrf
                                <button type="submit" class="inline-flex h-9 items-center rounded-md border border-accentYellow-900/25 text-accentYellow-900 px-3 text-xs font-medium text-accentYellow-900 hover:text-accentYellow-900/80">Stop Support Mode</button>
                            </form>
                        </x-slot:actions>
                    </x-alert>
                @endif
                @php
                    $journeyRouteIsAllowed = request()->routeIs(
                        'app.dashboard',
                        'app.activation.*',
                        'app.sites.llm-tracking*',
                        'app.signal-intelligence.*',
                        'app.agentic-marketing.intelligence.*',
                        'app.opportunity-intelligence.*',
                        'app.content.workspace.*',
                        'app.drafts.show'
                    );
                    $journeyRouteIsExcluded = request()->routeIs(
                        'app.billing.*',
                        'app.settings*',
                        'app.developer.*'
                    );
                    $journeyRoute = request()->route();
                    $journeyWorkspace = null;

                    if ($journeyRouteIsAllowed && ! $journeyRouteIsExcluded && auth()->check()) {
                        $routeWorkspace = $journeyRoute?->parameter('workspace');
                        $routeSite = $journeyRoute?->parameter('site');
                        $routeOpportunity = $journeyRoute?->parameter('opportunity');
                        $routePlan = $journeyRoute?->parameter('plan');
                        $routeBrief = $journeyRoute?->parameter('brief');
                        $routeDraft = $journeyRoute?->parameter('draft');

                        if ($routeWorkspace instanceof \App\Models\Workspace) {
                            $journeyWorkspace = $routeWorkspace;
                        } elseif ($routeSite instanceof \App\Models\ClientSite) {
                            $journeyWorkspace = $routeSite->workspace;
                        } elseif ($routeOpportunity instanceof \App\Models\Opportunity) {
                            $journeyWorkspace = $routeOpportunity->workspace;
                        } elseif ($routePlan instanceof \App\Models\OpportunityExecutionPlan) {
                            $journeyWorkspace = $routePlan->workspace;
                        } elseif ($routeBrief instanceof \App\Models\Brief) {
                            $journeyWorkspace = $routeBrief->clientSite?->workspace;
                        } elseif ($routeDraft instanceof \App\Models\Draft) {
                            $journeyWorkspace = $routeDraft->clientSite?->workspace ?? $routeDraft->brief?->clientSite?->workspace;
                        }

                        if (! $journeyWorkspace) {
                            $journeyWorkspace = \App\Models\Workspace::query()
                                ->where('organization_id', auth()->user()->organization_id)
                                ->when(request()->query('workspace'), fn ($query, $id) => $query->whereKey($id))
                                ->orderBy('created_at')
                                ->first();
                        }

                        if ($journeyWorkspace && (int) $journeyWorkspace->organization_id !== (int) auth()->user()->organization_id) {
                            $journeyWorkspace = null;
                        }
                    }
                @endphp
                @if ($journeyWorkspace)
                    <x-intelligence-journey :workspace="$journeyWorkspace" />
                @endif
                @yield('content')
            </div>
        </main>
    </div>
</div>

<script>
    if (window.lucide) {
        lucide.createIcons();
    }

    var userMenuBtn = document.getElementById('userMenuBtn');
    var userMenu = document.getElementById('userMenu');
    if (userMenuBtn && userMenu) {
        userMenuBtn.addEventListener('click', function (event) {
            event.preventDefault();
            userMenu.classList.toggle('hidden');
        });
    }

    var notificationBellBtn = document.getElementById('notificationBellBtn');
    var notificationBellMenu = document.getElementById('notificationBellMenu');
    if (notificationBellBtn && notificationBellMenu) {
        notificationBellBtn.addEventListener('click', function (event) {
            event.preventDefault();
            notificationBellMenu.classList.toggle('hidden');
        });

        document.addEventListener('click', function (event) {
            if (notificationBellMenu.classList.contains('hidden')) {
                return;
            }

            if (notificationBellBtn.contains(event.target) || notificationBellMenu.contains(event.target)) {
                return;
            }

            notificationBellMenu.classList.add('hidden');
        });
    }

    var mobileMenuBtn = document.getElementById('mobileMenuBtn');
    var mobileSidebar = document.getElementById('mobileSidebar');
    var mobileOverlay = document.getElementById('mobileOverlay');
    var closeMobileMenu = document.getElementById('closeMobileMenu');
    if (mobileMenuBtn && mobileSidebar) {
        mobileMenuBtn.addEventListener('click', function () {
            mobileSidebar.classList.remove('hidden');
        });
    }
    if (mobileOverlay) {
        mobileOverlay.addEventListener('click', function () {
            mobileSidebar.classList.add('hidden');
        });
    }
    if (closeMobileMenu) {
        closeMobileMenu.addEventListener('click', function () {
            mobileSidebar.classList.add('hidden');
        });
    }

    var collapseBtn = document.getElementById('collapseBtn');
    var sidebar = document.getElementById('sidebar');
    if (collapseBtn && sidebar) {
        var sidebarStateKey = 'pl_app_sidebar_collapsed';
        var setSidebarCollapsed = function (collapsed) {
            sidebar.dataset.collapsed = collapsed ? 'true' : 'false';
            sidebar.classList.toggle('w-20', collapsed);
            sidebar.classList.toggle('w-64', !collapsed);

            sidebar.querySelectorAll('[data-sidebar-item]').forEach(function (item) {
                item.classList.toggle('justify-center', collapsed);
                item.classList.toggle('justify-start', !collapsed);
                item.classList.toggle('gap-0', collapsed);
                item.classList.toggle('gap-3', !collapsed);
                item.classList.toggle('px-2', collapsed);
                item.classList.toggle('px-3', !collapsed);

                var title = item.getAttribute('data-sidebar-title') || '';
                item.setAttribute('title', collapsed ? title : '');
            });

            sidebar.querySelectorAll('[data-sidebar-label]').forEach(function (el) {
                el.classList.toggle('hidden', collapsed);
            });

            sidebar.querySelectorAll('[data-sidebar-tooltip]').forEach(function (el) {
                el.classList.toggle('hidden', !collapsed);
            });

            var expandedIcon = sidebar.querySelector('[data-collapse-icon="expanded"]');
            var collapsedIcon = sidebar.querySelector('[data-collapse-icon="collapsed"]');
            if (expandedIcon) {
                expandedIcon.classList.toggle('hidden', collapsed);
            }
            if (collapsedIcon) {
                collapsedIcon.classList.toggle('hidden', !collapsed);
            }
        };

        var storedState = localStorage.getItem(sidebarStateKey);
        var startCollapsed = storedState === '1';
        setSidebarCollapsed(startCollapsed);

        collapseBtn.addEventListener('click', function () {
            var nextCollapsed = sidebar.dataset.collapsed !== 'true';
            setSidebarCollapsed(nextCollapsed);
            localStorage.setItem(sidebarStateKey, nextCollapsed ? '1' : '0');
        });
    }
</script>
</body>
</html>
