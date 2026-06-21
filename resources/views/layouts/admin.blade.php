<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $title ?? 'Admin - '.\App\Support\Brand::product() }}</title>
    @include('partials.brand-meta')
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <script src="https://unpkg.com/lucide@latest"></script>
</head>
<body class="pl-admin-shell pl-work-shell min-h-screen bg-background antialiased text-textPrimary">
@php
    $pageWidth = in_array(($pageWidth ?? 'wide'), ['wide', 'constrained'], true) ? ($pageWidth ?? 'wide') : 'wide';
    $pageShellClass = $pageWidth === 'constrained'
        ? 'pl-page pl-page--constrained'
        : 'pl-page pl-page--wide';
@endphp
<div class="flex min-h-screen w-full">
    <aside id="adminSidebar" data-collapsed="false" class="pl-work-sidebar hidden lg:flex sticky top-0 h-screen w-64 flex-col transition-all duration-300">
        <div class="pl-work-sidebar-brand">
            <x-brand-logo :show-text="false" />
            <div data-sidebar-label class="leading-tight">
                <span class="block text-sm font-semibold text-textPrimary">{{ \App\Support\Brand::product() }} Admin</span>
            </div>
        </div>

        <nav class="flex-1 overflow-y-auto px-2 py-3">
            <div class="pl-work-sidebar-section">
                <p data-sidebar-label class="px-2 pb-1 text-xs font-medium uppercase tracking-wide text-textFaint">Platform</p>
                <div class="space-y-1">
                    <a href="{{ route('admin.dashboard') }}" data-sidebar-item data-sidebar-title="Dashboard" class="group relative flex h-9 items-center justify-start gap-3 rounded-md px-3 text-sm font-medium transition-all {{ request()->routeIs('admin.dashboard') ? 'bg-primary text-white' : 'text-textSecondary hover:bg-surfaceMuted hover:text-textPrimary' }}">
                        <span data-sidebar-icon-wrap class="flex h-5 w-5 shrink-0 items-center justify-center"><i data-lucide="layout-dashboard" class="h-4 w-4"></i></span>
                        <span data-sidebar-label class="truncate">Dashboard</span>
                        <span data-sidebar-tooltip class="pointer-events-none absolute left-full top-1/2 z-50 ml-2 hidden -translate-y-1/2 whitespace-nowrap rounded-md border border-border bg-surface px-2 py-1 text-xs text-textPrimary opacity-0 transition-opacity duration-150 group-hover:opacity-100">Dashboard</span>
                    </a>
                    <a href="{{ route('admin.system-health.index') }}" data-sidebar-item data-sidebar-title="System health" class="group relative flex h-9 items-center justify-start gap-3 rounded-md px-3 text-sm font-medium transition-all {{ request()->routeIs('admin.system-health.*') ? 'bg-primary text-white' : 'text-textSecondary hover:bg-surfaceMuted hover:text-textPrimary' }}">
                        <span data-sidebar-icon-wrap class="flex h-5 w-5 shrink-0 items-center justify-center"><i data-lucide="heart-pulse" class="h-4 w-4"></i></span>
                        <span data-sidebar-label class="truncate">System Health</span>
                        <span data-sidebar-tooltip class="pointer-events-none absolute left-full top-1/2 z-50 ml-2 hidden -translate-y-1/2 whitespace-nowrap rounded-md border border-border bg-surface px-2 py-1 text-xs text-textPrimary opacity-0 transition-opacity duration-150 group-hover:opacity-100">System Health</span>
                    </a>
                    <a href="{{ route('admin.llm-monitor.index') }}" data-sidebar-item data-sidebar-title="LLM monitor" class="group relative flex h-9 items-center justify-start gap-3 rounded-md px-3 text-sm font-medium transition-all {{ request()->routeIs('admin.llm-monitor.*') || request()->routeIs('admin.llm.monitor*') ? 'bg-primary text-white' : 'text-textSecondary hover:bg-surfaceMuted hover:text-textPrimary' }}">
                        <span data-sidebar-icon-wrap class="flex h-5 w-5 shrink-0 items-center justify-center"><i data-lucide="activity" class="h-4 w-4"></i></span>
                        <span data-sidebar-label class="truncate">LLM Monitor</span>
                        <span data-sidebar-tooltip class="pointer-events-none absolute left-full top-1/2 z-50 ml-2 hidden -translate-y-1/2 whitespace-nowrap rounded-md border border-border bg-surface px-2 py-1 text-xs text-textPrimary opacity-0 transition-opacity duration-150 group-hover:opacity-100">LLM Monitor</span>
                    </a>
                    @can('admin-area-superadmin')
                        <a href="{{ route('admin.llm.settings') }}" data-sidebar-item data-sidebar-title="LLM settings" class="group relative flex h-9 items-center justify-start gap-3 rounded-md px-3 text-sm font-medium transition-all {{ request()->routeIs('admin.llm.settings*') ? 'bg-primary text-white' : 'text-textSecondary hover:bg-surfaceMuted hover:text-textPrimary' }}">
                            <span data-sidebar-icon-wrap class="flex h-5 w-5 shrink-0 items-center justify-center"><i data-lucide="sliders-horizontal" class="h-4 w-4"></i></span>
                            <span data-sidebar-label class="truncate">LLM Settings</span>
                            <span data-sidebar-tooltip class="pointer-events-none absolute left-full top-1/2 z-50 ml-2 hidden -translate-y-1/2 whitespace-nowrap rounded-md border border-border bg-surface px-2 py-1 text-xs text-textPrimary opacity-0 transition-opacity duration-150 group-hover:opacity-100">LLM Settings</span>
                        </a>
                    @endcan
                    <a href="{{ route('admin.queues.index') }}" data-sidebar-item data-sidebar-title="Queues" class="group relative flex h-9 items-center justify-start gap-3 rounded-md px-3 text-sm font-medium transition-all {{ request()->routeIs('admin.queues.*') ? 'bg-primary text-white' : 'text-textSecondary hover:bg-surfaceMuted hover:text-textPrimary' }}">
                        <span data-sidebar-icon-wrap class="flex h-5 w-5 shrink-0 items-center justify-center"><i data-lucide="list-checks" class="h-4 w-4"></i></span>
                        <span data-sidebar-label class="truncate">Queues</span>
                        <span data-sidebar-tooltip class="pointer-events-none absolute left-full top-1/2 z-50 ml-2 hidden -translate-y-1/2 whitespace-nowrap rounded-md border border-border bg-surface px-2 py-1 text-xs text-textPrimary opacity-0 transition-opacity duration-150 group-hover:opacity-100">Queues</span>
                    </a>
                    <a href="{{ route('admin.campaigns.index') }}" data-sidebar-item data-sidebar-title="Campaigns" class="group relative flex h-9 items-center justify-start gap-3 rounded-md px-3 text-sm font-medium transition-all {{ request()->routeIs('admin.campaigns.*') ? 'bg-primary text-white' : 'text-textSecondary hover:bg-surfaceMuted hover:text-textPrimary' }}">
                        <span data-sidebar-icon-wrap class="flex h-5 w-5 shrink-0 items-center justify-center"><i data-lucide="network" class="h-4 w-4"></i></span>
                        <span data-sidebar-label class="truncate">Campaigns</span>
                        <span data-sidebar-tooltip class="pointer-events-none absolute left-full top-1/2 z-50 ml-2 hidden -translate-y-1/2 whitespace-nowrap rounded-md border border-border bg-surface px-2 py-1 text-xs text-textPrimary opacity-0 transition-opacity duration-150 group-hover:opacity-100">Campaigns</span>
                    </a>
                    <a href="{{ route('admin.webhooks.index') }}" data-sidebar-item data-sidebar-title="Webhooks" class="group relative flex h-9 items-center justify-start gap-3 rounded-md px-3 text-sm font-medium transition-all {{ request()->routeIs('admin.webhooks.*') ? 'bg-primary text-white' : 'text-textSecondary hover:bg-surfaceMuted hover:text-textPrimary' }}">
                        <span data-sidebar-icon-wrap class="flex h-5 w-5 shrink-0 items-center justify-center"><i data-lucide="webhook" class="h-4 w-4"></i></span>
                        <span data-sidebar-label class="truncate">Webhooks</span>
                        <span data-sidebar-tooltip class="pointer-events-none absolute left-full top-1/2 z-50 ml-2 hidden -translate-y-1/2 whitespace-nowrap rounded-md border border-border bg-surface px-2 py-1 text-xs text-textPrimary opacity-0 transition-opacity duration-150 group-hover:opacity-100">Webhooks</span>
                    </a>
                    <a href="{{ route('admin.sites') }}" data-sidebar-item data-sidebar-title="Sites" class="group relative flex h-9 items-center justify-start gap-3 rounded-md px-3 text-sm font-medium transition-all {{ request()->routeIs('admin.sites.*') || request()->routeIs('admin.sites') ? 'bg-primary text-white' : 'text-textSecondary hover:bg-surfaceMuted hover:text-textPrimary' }}">
                        <span data-sidebar-icon-wrap class="flex h-5 w-5 shrink-0 items-center justify-center"><i data-lucide="globe" class="h-4 w-4"></i></span>
                        <span data-sidebar-label class="truncate">Sites</span>
                        <span data-sidebar-tooltip class="pointer-events-none absolute left-full top-1/2 z-50 ml-2 hidden -translate-y-1/2 whitespace-nowrap rounded-md border border-border bg-surface px-2 py-1 text-xs text-textPrimary opacity-0 transition-opacity duration-150 group-hover:opacity-100">Sites</span>
                    </a>
                </div>
            </div>

            <div class="pl-work-sidebar-section">
                <p data-sidebar-label class="px-2 pb-1 text-xs font-medium uppercase tracking-wide text-textFaint">Customers</p>
                <div class="space-y-1">
                    <a href="{{ route('admin.organizations') }}" data-sidebar-item data-sidebar-title="Organizations" class="group relative flex h-9 items-center justify-start gap-3 rounded-md px-3 text-sm font-medium transition-all {{ request()->routeIs('admin.organizations.*') || request()->routeIs('admin.organizations') ? 'bg-primary text-white' : 'text-textSecondary hover:bg-surfaceMuted hover:text-textPrimary' }}">
                        <span data-sidebar-icon-wrap class="flex h-5 w-5 shrink-0 items-center justify-center"><i data-lucide="building-2" class="h-4 w-4"></i></span>
                        <span data-sidebar-label class="truncate">Organizations</span>
                        <span data-sidebar-tooltip class="pointer-events-none absolute left-full top-1/2 z-50 ml-2 hidden -translate-y-1/2 whitespace-nowrap rounded-md border border-border bg-surface px-2 py-1 text-xs text-textPrimary opacity-0 transition-opacity duration-150 group-hover:opacity-100">Organizations</span>
                    </a>
                    <a href="{{ route('admin.users') }}" data-sidebar-item data-sidebar-title="Users" class="group relative flex h-9 items-center justify-start gap-3 rounded-md px-3 text-sm font-medium transition-all {{ request()->routeIs('admin.users.*') || request()->routeIs('admin.users') ? 'bg-primary text-white' : 'text-textSecondary hover:bg-surfaceMuted hover:text-textPrimary' }}">
                        <span data-sidebar-icon-wrap class="flex h-5 w-5 shrink-0 items-center justify-center"><i data-lucide="users" class="h-4 w-4"></i></span>
                        <span data-sidebar-label class="truncate">Users</span>
                        <span data-sidebar-tooltip class="pointer-events-none absolute left-full top-1/2 z-50 ml-2 hidden -translate-y-1/2 whitespace-nowrap rounded-md border border-border bg-surface px-2 py-1 text-xs text-textPrimary opacity-0 transition-opacity duration-150 group-hover:opacity-100">Users</span>
                    </a>
                    <a href="{{ route('admin.early-access.index') }}" data-sidebar-item data-sidebar-title="Pilot Program" class="group relative flex h-9 items-center justify-start gap-3 rounded-md px-3 text-sm font-medium transition-all {{ request()->routeIs('admin.early-access.*') ? 'bg-primary text-white' : 'text-textSecondary hover:bg-surfaceMuted hover:text-textPrimary' }}">
                        <span data-sidebar-icon-wrap class="flex h-5 w-5 shrink-0 items-center justify-center"><i data-lucide="rocket" class="h-4 w-4"></i></span>
                        <span data-sidebar-label class="truncate">Pilot Program</span>
                        <span data-sidebar-tooltip class="pointer-events-none absolute left-full top-1/2 z-50 ml-2 hidden -translate-y-1/2 whitespace-nowrap rounded-md border border-border bg-surface px-2 py-1 text-xs text-textPrimary opacity-0 transition-opacity duration-150 group-hover:opacity-100">Pilot Program</span>
                    </a>
                    @can('admin-area-superadmin')
                        <a href="{{ route('admin.support.index') }}" data-sidebar-item data-sidebar-title="Support" class="group relative flex h-9 items-center justify-start gap-3 rounded-md px-3 text-sm font-medium transition-all {{ request()->routeIs('admin.support.*') ? 'bg-primary text-white' : 'text-textSecondary hover:bg-surfaceMuted hover:text-textPrimary' }}">
                            <span data-sidebar-icon-wrap class="flex h-5 w-5 shrink-0 items-center justify-center"><i data-lucide="life-buoy" class="h-4 w-4"></i></span>
                            <span data-sidebar-label class="truncate">Support</span>
                            <span data-sidebar-tooltip class="pointer-events-none absolute left-full top-1/2 z-50 ml-2 hidden -translate-y-1/2 whitespace-nowrap rounded-md border border-border bg-surface px-2 py-1 text-xs text-textPrimary opacity-0 transition-opacity duration-150 group-hover:opacity-100">Support</span>
                        </a>
                    @endcan
                </div>
            </div>

            <div class="pl-work-sidebar-section">
                <p data-sidebar-label class="px-2 pb-1 text-xs font-medium uppercase tracking-wide text-textFaint">Product</p>
                <div class="space-y-1">
                    <a href="{{ route('admin.editorial-taxonomy.index') }}" data-sidebar-item data-sidebar-title="Editorial taxonomy" class="group relative flex h-9 items-center justify-start gap-3 rounded-md px-3 text-sm font-medium transition-all {{ request()->routeIs('admin.editorial-taxonomy.*') || request()->routeIs('admin.editorial-taxonomy.index') ? 'bg-primary text-white' : 'text-textSecondary hover:bg-surfaceMuted hover:text-textPrimary' }}">
                        <span data-sidebar-icon-wrap class="flex h-5 w-5 shrink-0 items-center justify-center"><i data-lucide="list-tree" class="h-4 w-4"></i></span>
                        <span data-sidebar-label class="truncate">Editorial Taxonomy</span>
                        <span data-sidebar-tooltip class="pointer-events-none absolute left-full top-1/2 z-50 ml-2 hidden -translate-y-1/2 whitespace-nowrap rounded-md border border-border bg-surface px-2 py-1 text-xs text-textPrimary opacity-0 transition-opacity duration-150 group-hover:opacity-100">Editorial Taxonomy</span>
                    </a>
                    <a href="{{ route('admin.brand-profiles.index') }}" data-sidebar-item data-sidebar-title="Default brand profiles" class="group relative flex h-9 items-center justify-start gap-3 rounded-md px-3 text-sm font-medium transition-all {{ request()->routeIs('admin.brand-profiles.*') ? 'bg-primary text-white' : 'text-textSecondary hover:bg-surfaceMuted hover:text-textPrimary' }}">
                        <span data-sidebar-icon-wrap class="flex h-5 w-5 shrink-0 items-center justify-center"><i data-lucide="palette" class="h-4 w-4"></i></span>
                        <span data-sidebar-label class="truncate">Default Brand Profiles</span>
                        <span data-sidebar-tooltip class="pointer-events-none absolute left-full top-1/2 z-50 ml-2 hidden -translate-y-1/2 whitespace-nowrap rounded-md border border-border bg-surface px-2 py-1 text-xs text-textPrimary opacity-0 transition-opacity duration-150 group-hover:opacity-100">Default Brand Profiles</span>
                    </a>
                    <a href="{{ route('admin.content-policies.index') }}" data-sidebar-item data-sidebar-title="Content policies" class="group relative flex h-9 items-center justify-start gap-3 rounded-md px-3 text-sm font-medium transition-all {{ request()->routeIs('admin.content-policies.*') ? 'bg-primary text-white' : 'text-textSecondary hover:bg-surfaceMuted hover:text-textPrimary' }}">
                        <span data-sidebar-icon-wrap class="flex h-5 w-5 shrink-0 items-center justify-center"><i data-lucide="shield-check" class="h-4 w-4"></i></span>
                        <span data-sidebar-label class="truncate">Content Policies</span>
                        <span data-sidebar-tooltip class="pointer-events-none absolute left-full top-1/2 z-50 ml-2 hidden -translate-y-1/2 whitespace-nowrap rounded-md border border-border bg-surface px-2 py-1 text-xs text-textPrimary opacity-0 transition-opacity duration-150 group-hover:opacity-100">Content Policies</span>
                    </a>
                    <a href="{{ route('admin.faq-intelligence.index') }}" data-sidebar-item data-sidebar-title="FAQ Intelligence" class="group relative flex h-9 items-center justify-start gap-3 rounded-md px-3 text-sm font-medium transition-all {{ request()->routeIs('admin.faq-intelligence.*') ? 'bg-primary text-white' : 'text-textSecondary hover:bg-surfaceMuted hover:text-textPrimary' }}">
                        <span data-sidebar-icon-wrap class="flex h-5 w-5 shrink-0 items-center justify-center"><i data-lucide="messages-square" class="h-4 w-4"></i></span>
                        <span data-sidebar-label class="truncate">FAQ Intelligence</span>
                        <span data-sidebar-tooltip class="pointer-events-none absolute left-full top-1/2 z-50 ml-2 hidden -translate-y-1/2 whitespace-nowrap rounded-md border border-border bg-surface px-2 py-1 text-xs text-textPrimary opacity-0 transition-opacity duration-150 group-hover:opacity-100">FAQ Intelligence</span>
                    </a>
                    <a href="{{ route('admin.feature-flags.index') }}" data-sidebar-item data-sidebar-title="Feature flags" class="group relative flex h-9 items-center justify-start gap-3 rounded-md px-3 text-sm font-medium transition-all {{ request()->routeIs('admin.feature-flags.*') ? 'bg-primary text-white' : 'text-textSecondary hover:bg-surfaceMuted hover:text-textPrimary' }}">
                        <span data-sidebar-icon-wrap class="flex h-5 w-5 shrink-0 items-center justify-center"><i data-lucide="toggle-left" class="h-4 w-4"></i></span>
                        <span data-sidebar-label class="truncate">Feature Flags</span>
                        <span data-sidebar-tooltip class="pointer-events-none absolute left-full top-1/2 z-50 ml-2 hidden -translate-y-1/2 whitespace-nowrap rounded-md border border-border bg-surface px-2 py-1 text-xs text-textPrimary opacity-0 transition-opacity duration-150 group-hover:opacity-100">Feature Flags</span>
                    </a>
                    <a href="{{ route('admin.announcements.index') }}" data-sidebar-item data-sidebar-title="Announcements" class="group relative flex h-9 items-center justify-start gap-3 rounded-md px-3 text-sm font-medium transition-all {{ request()->routeIs('admin.announcements.*') ? 'bg-primary text-white' : 'text-textSecondary hover:bg-surfaceMuted hover:text-textPrimary' }}">
                        <span data-sidebar-icon-wrap class="flex h-5 w-5 shrink-0 items-center justify-center"><i data-lucide="megaphone" class="h-4 w-4"></i></span>
                        <span data-sidebar-label class="truncate">Announcements</span>
                        <span data-sidebar-tooltip class="pointer-events-none absolute left-full top-1/2 z-50 ml-2 hidden -translate-y-1/2 whitespace-nowrap rounded-md border border-border bg-surface px-2 py-1 text-xs text-textPrimary opacity-0 transition-opacity duration-150 group-hover:opacity-100">Announcements</span>
                    </a>
                    <a href="{{ route('admin.product-updates.index') }}" data-sidebar-item data-sidebar-title="Product updates" class="group relative flex h-9 items-center justify-start gap-3 rounded-md px-3 text-sm font-medium transition-all {{ request()->routeIs('admin.product-updates.*') ? 'bg-primary text-white' : 'text-textSecondary hover:bg-surfaceMuted hover:text-textPrimary' }}">
                        <span data-sidebar-icon-wrap class="flex h-5 w-5 shrink-0 items-center justify-center"><i data-lucide="history" class="h-4 w-4"></i></span>
                        <span data-sidebar-label class="truncate">Product Updates</span>
                        <span data-sidebar-tooltip class="pointer-events-none absolute left-full top-1/2 z-50 ml-2 hidden -translate-y-1/2 whitespace-nowrap rounded-md border border-border bg-surface px-2 py-1 text-xs text-textPrimary opacity-0 transition-opacity duration-150 group-hover:opacity-100">Product Updates</span>
                    </a>
                </div>
            </div>

            <div class="pl-work-sidebar-section">
                <p data-sidebar-label class="px-2 pb-1 text-xs font-medium uppercase tracking-wide text-textFaint">Finance</p>
                <div class="space-y-1">
                    <a href="{{ route('admin.billing.index') }}" data-sidebar-item data-sidebar-title="Billing" class="group relative flex h-9 items-center justify-start gap-3 rounded-md px-3 text-sm font-medium transition-all {{ request()->routeIs('admin.billing.index') || request()->routeIs('admin.organizations.billing*') ? 'bg-primary text-white' : 'text-textSecondary hover:bg-surfaceMuted hover:text-textPrimary' }}">
                        <span data-sidebar-icon-wrap class="flex h-5 w-5 shrink-0 items-center justify-center"><i data-lucide="wallet" class="h-4 w-4"></i></span>
                        <span data-sidebar-label class="truncate">Billing</span>
                        <span data-sidebar-tooltip class="pointer-events-none absolute left-full top-1/2 z-50 ml-2 hidden -translate-y-1/2 whitespace-nowrap rounded-md border border-border bg-surface px-2 py-1 text-xs text-textPrimary opacity-0 transition-opacity duration-150 group-hover:opacity-100">Billing</span>
                    </a>
                    <a href="{{ route('admin.billing.pricing-page.index') }}" data-sidebar-item data-sidebar-title="Pricing Page" class="group relative flex h-9 items-center justify-start gap-3 rounded-md px-3 text-sm font-medium transition-all {{ request()->routeIs('admin.billing.pricing-page*') ? 'bg-primary text-white' : 'text-textSecondary hover:bg-surfaceMuted hover:text-textPrimary' }}">
                        <span data-sidebar-icon-wrap class="flex h-5 w-5 shrink-0 items-center justify-center"><i data-lucide="tag" class="h-4 w-4"></i></span>
                        <span data-sidebar-label class="truncate">Pricing Page</span>
                        <span data-sidebar-tooltip class="pointer-events-none absolute left-full top-1/2 z-50 ml-2 hidden -translate-y-1/2 whitespace-nowrap rounded-md border border-border bg-surface px-2 py-1 text-xs text-textPrimary opacity-0 transition-opacity duration-150 group-hover:opacity-100">Pricing Page</span>
                    </a>
                    <a href="{{ route('admin.invoices.index') }}" data-sidebar-item data-sidebar-title="Invoices" class="group relative flex h-9 items-center justify-start gap-3 rounded-md px-3 text-sm font-medium transition-all {{ request()->routeIs('admin.invoices*') ? 'bg-primary text-white' : 'text-textSecondary hover:bg-surfaceMuted hover:text-textPrimary' }}">
                        <span data-sidebar-icon-wrap class="flex h-5 w-5 shrink-0 items-center justify-center"><i data-lucide="receipt" class="h-4 w-4"></i></span>
                        <span data-sidebar-label class="truncate">Invoices</span>
                        <span data-sidebar-tooltip class="pointer-events-none absolute left-full top-1/2 z-50 ml-2 hidden -translate-y-1/2 whitespace-nowrap rounded-md border border-border bg-surface px-2 py-1 text-xs text-textPrimary opacity-0 transition-opacity duration-150 group-hover:opacity-100">Invoices</span>
                    </a>
                </div>
            </div>

            @can('admin-area-superadmin')
            <div class="pl-work-sidebar-section">
                <p data-sidebar-label class="px-2 pb-1 text-xs font-medium uppercase tracking-wide text-textFaint">Settings</p>
                <div class="space-y-1">
                    <a href="{{ route('admin.analytics.index') }}" data-sidebar-item data-sidebar-title="Analytics" class="group relative flex h-9 items-center justify-start gap-3 rounded-md px-3 text-sm font-medium transition-all {{ request()->routeIs('admin.analytics*') ? 'bg-primary text-white' : 'text-textSecondary hover:bg-surfaceMuted hover:text-textPrimary' }}">
                        <span data-sidebar-icon-wrap class="flex h-5 w-5 shrink-0 items-center justify-center"><i data-lucide="bar-chart-3" class="h-4 w-4"></i></span>
                        <span data-sidebar-label class="truncate">Analytics</span>
                        <span data-sidebar-tooltip class="pointer-events-none absolute left-full top-1/2 z-50 ml-2 hidden -translate-y-1/2 whitespace-nowrap rounded-md border border-border bg-surface px-2 py-1 text-xs text-textPrimary opacity-0 transition-opacity duration-150 group-hover:opacity-100">Analytics</span>
                    </a>
                </div>
            </div>
            @endcan
        </nav>

        <div class="border-t border-border p-2">
            <button id="adminCollapseBtn" data-sidebar-item data-sidebar-title="Collapse sidebar" class="group relative flex h-9 w-full items-center justify-start gap-3 rounded-md px-3 text-sm font-medium text-textSecondary hover:bg-surfaceMuted">
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
            <div class="pl-work-topbar">
                <div class="flex min-w-0 flex-1 items-center gap-3">
                    <button id="adminMobileMenuBtn" class="inline-flex items-center justify-center h-9 w-9 rounded-md border border-border text-textSecondary hover:bg-surfaceMuted lg:hidden">
                        <i data-lucide="menu" class="h-4 w-4"></i>
                    </button>
                    <div class="flex min-w-0 items-center gap-2">
                        <span class="pl-work-chip">Admin</span>
                        <span class="pl-work-chip-muted hidden sm:inline-flex">{{ $title ?? 'Platform' }}</span>
                    </div>
                </div>
                <div class="flex w-full items-center justify-end gap-2 sm:w-auto sm:flex-1">
                    @php($adminNotificationBell = $adminNotificationBell ?? ['unread_count' => 0, 'recent' => collect()])
                    @php($adminNotificationBellUnreadCount = (int) ($adminNotificationBell['unread_count'] ?? 0))
                    <div class="relative hidden min-w-[22rem] flex-1 md:block">
                        <form method="GET" action="{{ route('admin.search') }}" class="relative">
                            <i data-lucide="search" class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-textFaint"></i>
                            <input
                                type="text"
                                name="q"
                                value="{{ (string) request()->query('q', '') }}"
                                class="pl-search w-full"
                                placeholder="Search organizations, users, invoices"
                                aria-label="Search"
                                autocomplete="off"
                                data-global-search
                                data-search-endpoint="{{ route('admin.search.suggest') }}"
                            >
                            <div class="absolute left-0 right-0 top-11 z-50 hidden overflow-hidden rounded-md border border-border bg-surface" data-search-dropdown>
                                <div class="max-h-80 overflow-auto py-1" data-search-results></div>
                                <a href="{{ route('admin.search') }}" class="block border-t border-border px-3 py-2 text-xs font-medium text-textSecondary hover:bg-surfaceSubtle" data-search-all-link>View all results</a>
                            </div>
                        </form>
                    </div>
                    <div class="relative" data-notification-bell>
                        <button id="adminNotificationBellBtn" type="button" class="relative pl-icon-btn" aria-label="Notifications" data-notification-bell-toggle>
                            <i data-lucide="bell" class="h-4 w-4"></i>
                            @if ($adminNotificationBellUnreadCount > 0)
                                <span
                                    data-notification-bell-badge
                                    class="absolute -right-1 -top-1 inline-flex min-h-4 min-w-4 items-center justify-center rounded-full bg-primary px-1 text-[10px] font-semibold text-white"
                                >{{ min(99, $adminNotificationBellUnreadCount) }}</span>
                            @endif
                        </button>
                        <div id="adminNotificationBellMenu" data-notification-bell-menu class="pl-elevation-overlay hidden absolute right-0 top-11 z-50 w-96 max-w-[calc(100vw-2rem)] overflow-hidden rounded-md border border-border bg-surface">
                            <p data-notification-bell-error class="hidden border-b border-danger/20 bg-danger/5 px-3 py-2 text-xs text-danger" role="alert"></p>
                            <div data-notification-bell-content>
                                @include('partials.notifications.admin-bell-menu', ['notificationBell' => $adminNotificationBell])
                            </div>
                            <a href="{{ route('admin.notifications.index') }}" class="block border-t border-border px-3 py-2 text-xs font-medium text-textSecondary hover:bg-surfaceSubtle">
                                View all notifications
                            </a>
                        </div>
                    </div>
                    <button id="adminUserMenuBtn" type="button" class="pl-work-avatar" aria-label="Admin user menu">
                        {{ strtoupper(substr(auth()->user()->name ?? 'A', 0, 2)) }}
                    </button>
                    <div id="adminUserMenu" class="hidden absolute right-4 top-16 z-50 w-56 rounded-md border border-border bg-surface md:right-6">
                        <div class="p-1">
                            <a href="{{ route('admin.users.show', auth()->user()) }}" class="flex items-center gap-2 rounded-md px-2 py-2 text-sm text-textSecondary hover:bg-surfaceMuted hover:text-textPrimary">
                                <i data-lucide="user" class="h-4 w-4"></i> Profile
                            </a>
                            <div class="my-1 h-px bg-divider"></div>
                            <form method="POST" action="{{ route('admin.logout') }}">
                                @csrf
                                <button type="submit" class="flex w-full items-center gap-2 rounded-md px-2 py-2 text-sm text-danger hover:bg-surfaceMuted">
                                    <i data-lucide="log-out" class="h-4 w-4"></i> Logout
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </header>

        <div id="adminMobileSidebar" class="hidden fixed inset-0 z-50 lg:hidden">
            <div class="fixed inset-0 bg-black/35" id="adminMobileOverlay"></div>
            <div class="fixed inset-y-0 left-0 w-72 bg-surface border-r border-border z-50">
                <div class="flex h-14 items-center justify-between border-b border-border px-4">
                    <div class="flex items-center gap-2">
                        <x-brand-logo :show-text="false" />
                        <div class="leading-tight">
                            <span class="block text-sm font-semibold text-textPrimary">{{ \App\Support\Brand::product() }} Admin</span>
                        </div>
                    </div>
                    <button id="adminCloseMobileMenu" class="pl-icon-btn border-0" aria-label="Close menu"><i data-lucide="x" class="h-4 w-4"></i></button>
                </div>
                <nav class="space-y-3 p-4">
                    <div>
                        <p class="mb-1 px-3 text-xs font-medium uppercase tracking-wide text-textFaint">Platform</p>
                        <div class="space-y-1">
                            <a href="{{ route('admin.dashboard') }}" class="flex items-center gap-3 rounded-md px-3 py-2 text-sm {{ request()->routeIs('admin.dashboard') ? 'bg-primary text-white' : 'text-textSecondary hover:bg-surfaceMuted hover:text-textPrimary' }}"><i data-lucide="layout-dashboard" class="h-4 w-4"></i> Dashboard</a>
                            <a href="{{ route('admin.system-health.index') }}" class="flex items-center gap-3 rounded-md px-3 py-2 text-sm {{ request()->routeIs('admin.system-health.*') ? 'bg-primary text-white' : 'text-textSecondary hover:bg-surfaceMuted hover:text-textPrimary' }}"><i data-lucide="heart-pulse" class="h-4 w-4"></i> System Health</a>
                            <a href="{{ route('admin.llm-monitor.index') }}" class="flex items-center gap-3 rounded-md px-3 py-2 text-sm {{ request()->routeIs('admin.llm-monitor.*') || request()->routeIs('admin.llm.monitor*') ? 'bg-primary text-white' : 'text-textSecondary hover:bg-surfaceMuted hover:text-textPrimary' }}"><i data-lucide="activity" class="h-4 w-4"></i> LLM Monitor</a>
                            @can('admin-area-superadmin')
                                <a href="{{ route('admin.llm.settings') }}" class="flex items-center gap-3 rounded-md px-3 py-2 text-sm {{ request()->routeIs('admin.llm.settings*') ? 'bg-primary text-white' : 'text-textSecondary hover:bg-surfaceMuted hover:text-textPrimary' }}"><i data-lucide="sliders-horizontal" class="h-4 w-4"></i> LLM Settings</a>
                            @endcan
                            <a href="{{ route('admin.queues.index') }}" class="flex items-center gap-3 rounded-md px-3 py-2 text-sm {{ request()->routeIs('admin.queues.*') ? 'bg-primary text-white' : 'text-textSecondary hover:bg-surfaceMuted hover:text-textPrimary' }}"><i data-lucide="list-checks" class="h-4 w-4"></i> Queues</a>
                            <a href="{{ route('admin.campaigns.index') }}" class="flex items-center gap-3 rounded-md px-3 py-2 text-sm {{ request()->routeIs('admin.campaigns.*') ? 'bg-primary text-white' : 'text-textSecondary hover:bg-surfaceMuted hover:text-textPrimary' }}"><i data-lucide="network" class="h-4 w-4"></i> Campaigns</a>
                            <a href="{{ route('admin.webhooks.index') }}" class="flex items-center gap-3 rounded-md px-3 py-2 text-sm {{ request()->routeIs('admin.webhooks.*') ? 'bg-primary text-white' : 'text-textSecondary hover:bg-surfaceMuted hover:text-textPrimary' }}"><i data-lucide="webhook" class="h-4 w-4"></i> Webhooks</a>
                            <a href="{{ route('admin.sites') }}" class="flex items-center gap-3 rounded-md px-3 py-2 text-sm {{ request()->routeIs('admin.sites.*') || request()->routeIs('admin.sites') ? 'bg-primary text-white' : 'text-textSecondary hover:bg-surfaceMuted hover:text-textPrimary' }}"><i data-lucide="globe" class="h-4 w-4"></i> Sites</a>
                        </div>
                    </div>
                    <div>
                        <p class="mb-1 px-3 text-xs font-medium uppercase tracking-wide text-textFaint">Customers</p>
                        <div class="space-y-1">
                            <a href="{{ route('admin.organizations') }}" class="flex items-center gap-3 rounded-md px-3 py-2 text-sm {{ request()->routeIs('admin.organizations.*') || request()->routeIs('admin.organizations') ? 'bg-primary text-white' : 'text-textSecondary hover:bg-surfaceMuted hover:text-textPrimary' }}"><i data-lucide="building-2" class="h-4 w-4"></i> Organizations</a>
                            <a href="{{ route('admin.users') }}" class="flex items-center gap-3 rounded-md px-3 py-2 text-sm {{ request()->routeIs('admin.users.*') || request()->routeIs('admin.users') ? 'bg-primary text-white' : 'text-textSecondary hover:bg-surfaceMuted hover:text-textPrimary' }}"><i data-lucide="users" class="h-4 w-4"></i> Users</a>
                            <a href="{{ route('admin.early-access.index') }}" class="flex items-center gap-3 rounded-md px-3 py-2 text-sm {{ request()->routeIs('admin.early-access.*') ? 'bg-primary text-white' : 'text-textSecondary hover:bg-surfaceMuted hover:text-textPrimary' }}"><i data-lucide="rocket" class="h-4 w-4"></i> Pilot Program</a>
                            @can('admin-area-superadmin')
                                <a href="{{ route('admin.support.index') }}" class="flex items-center gap-3 rounded-md px-3 py-2 text-sm {{ request()->routeIs('admin.support.*') ? 'bg-primary text-white' : 'text-textSecondary hover:bg-surfaceMuted hover:text-textPrimary' }}"><i data-lucide="life-buoy" class="h-4 w-4"></i> Support</a>
                            @endcan
                        </div>
                    </div>
                    <div>
                        <p class="mb-1 px-3 text-xs font-medium uppercase tracking-wide text-textFaint">Product</p>
                        <div class="space-y-1">
                            <a href="{{ route('admin.editorial-taxonomy.index') }}" class="flex items-center gap-3 rounded-md px-3 py-2 text-sm {{ request()->routeIs('admin.editorial-taxonomy.*') || request()->routeIs('admin.editorial-taxonomy.index') ? 'bg-primary text-white' : 'text-textSecondary hover:bg-surfaceMuted hover:text-textPrimary' }}"><i data-lucide="list-tree" class="h-4 w-4"></i> Editorial Taxonomy</a>
                            <a href="{{ route('admin.brand-profiles.index') }}" class="flex items-center gap-3 rounded-md px-3 py-2 text-sm {{ request()->routeIs('admin.brand-profiles.*') ? 'bg-primary text-white' : 'text-textSecondary hover:bg-surfaceMuted hover:text-textPrimary' }}"><i data-lucide="palette" class="h-4 w-4"></i> Default Brand Profiles</a>
                            <a href="{{ route('admin.content-policies.index') }}" class="flex items-center gap-3 rounded-md px-3 py-2 text-sm {{ request()->routeIs('admin.content-policies.*') ? 'bg-primary text-white' : 'text-textSecondary hover:bg-surfaceMuted hover:text-textPrimary' }}"><i data-lucide="shield-check" class="h-4 w-4"></i> Content Policies</a>
                            <a href="{{ route('admin.feature-flags.index') }}" class="flex items-center gap-3 rounded-md px-3 py-2 text-sm {{ request()->routeIs('admin.feature-flags.*') ? 'bg-primary text-white' : 'text-textSecondary hover:bg-surfaceMuted hover:text-textPrimary' }}"><i data-lucide="toggle-left" class="h-4 w-4"></i> Feature Flags</a>
                            <a href="{{ route('admin.announcements.index') }}" class="flex items-center gap-3 rounded-md px-3 py-2 text-sm {{ request()->routeIs('admin.announcements.*') ? 'bg-primary text-white' : 'text-textSecondary hover:bg-surfaceMuted hover:text-textPrimary' }}"><i data-lucide="megaphone" class="h-4 w-4"></i> Announcements</a>
                            <a href="{{ route('admin.product-updates.index') }}" class="flex items-center gap-3 rounded-md px-3 py-2 text-sm {{ request()->routeIs('admin.product-updates.*') ? 'bg-primary text-white' : 'text-textSecondary hover:bg-surfaceMuted hover:text-textPrimary' }}"><i data-lucide="history" class="h-4 w-4"></i> Product Updates</a>
                        </div>
                    </div>
                    <div>
                        <p class="mb-1 px-3 text-xs font-medium uppercase tracking-wide text-textFaint">Finance</p>
                        <div class="space-y-1">
                            <a href="{{ route('admin.billing.index') }}" class="flex items-center gap-3 rounded-md px-3 py-2 text-sm {{ request()->routeIs('admin.billing.index') || request()->routeIs('admin.organizations.billing*') ? 'bg-primary text-white' : 'text-textSecondary hover:bg-surfaceMuted hover:text-textPrimary' }}"><i data-lucide="wallet" class="h-4 w-4"></i> Billing</a>
                            <a href="{{ route('admin.billing.pricing-page.index') }}" class="flex items-center gap-3 rounded-md px-3 py-2 text-sm {{ request()->routeIs('admin.billing.pricing-page*') ? 'bg-primary text-white' : 'text-textSecondary hover:bg-surfaceMuted hover:text-textPrimary' }}"><i data-lucide="tag" class="h-4 w-4"></i> Pricing Page</a>
                            <a href="{{ route('admin.invoices.index') }}" class="flex items-center gap-3 rounded-md px-3 py-2 text-sm {{ request()->routeIs('admin.invoices*') ? 'bg-primary text-white' : 'text-textSecondary hover:bg-surfaceMuted hover:text-textPrimary' }}"><i data-lucide="receipt" class="h-4 w-4"></i> Invoices</a>
                        </div>
                    </div>
                    @can('admin-area-superadmin')
                    <div>
                        <p class="mb-1 px-3 text-xs font-medium uppercase tracking-wide text-textFaint">Settings</p>
                        <div class="space-y-1">
                            <a href="{{ route('admin.analytics.index') }}" class="flex items-center gap-3 rounded-md px-3 py-2 text-sm {{ request()->routeIs('admin.analytics*') ? 'bg-primary text-white' : 'text-textSecondary hover:bg-surfaceMuted hover:text-textPrimary' }}"><i data-lucide="bar-chart-3" class="h-4 w-4"></i> Analytics</a>
                        </div>
                    </div>
                    @endcan
                </nav>
            </div>
        </div>

        <main class="flex-1 overflow-auto">
            <div class="{{ $pageShellClass }}">
                @php($supportCtx = app(\App\Services\Support\SupportContext::class))
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
                @yield('content')
            </div>
        </main>
    </div>
</div>

<script>
    if (window.lucide) {
        lucide.createIcons();
    }

    var adminUserMenuBtn = document.getElementById('adminUserMenuBtn');
    var adminUserMenu = document.getElementById('adminUserMenu');
    if (adminUserMenuBtn && adminUserMenu) {
        adminUserMenuBtn.addEventListener('click', function (event) {
            event.preventDefault();
            adminUserMenu.classList.toggle('hidden');
        });
    }

    var adminNotificationBellBtn = document.getElementById('adminNotificationBellBtn');
    var adminNotificationBellMenu = document.getElementById('adminNotificationBellMenu');
    if (adminNotificationBellBtn && adminNotificationBellMenu) {
        adminNotificationBellBtn.addEventListener('click', function (event) {
            event.preventDefault();
            adminNotificationBellMenu.classList.toggle('hidden');
        });

        document.addEventListener('click', function (event) {
            if (adminNotificationBellMenu.classList.contains('hidden')) {
                return;
            }

            if (adminNotificationBellBtn.contains(event.target) || adminNotificationBellMenu.contains(event.target)) {
                return;
            }

            adminNotificationBellMenu.classList.add('hidden');
        });
    }

    var adminMobileMenuBtn = document.getElementById('adminMobileMenuBtn');
    var adminMobileSidebar = document.getElementById('adminMobileSidebar');
    var adminMobileOverlay = document.getElementById('adminMobileOverlay');
    var adminCloseMobileMenu = document.getElementById('adminCloseMobileMenu');
    if (adminMobileMenuBtn && adminMobileSidebar) {
        adminMobileMenuBtn.addEventListener('click', function () {
            adminMobileSidebar.classList.remove('hidden');
        });
    }
    if (adminMobileOverlay) {
        adminMobileOverlay.addEventListener('click', function () {
            adminMobileSidebar.classList.add('hidden');
        });
    }
    if (adminCloseMobileMenu) {
        adminCloseMobileMenu.addEventListener('click', function () {
            adminMobileSidebar.classList.add('hidden');
        });
    }

    var adminCollapseBtn = document.getElementById('adminCollapseBtn');
    var adminSidebar = document.getElementById('adminSidebar');
    if (adminCollapseBtn && adminSidebar) {
        var adminSidebarStateKey = 'pl_admin_sidebar_collapsed';
        var setAdminSidebarCollapsed = function (collapsed) {
            adminSidebar.dataset.collapsed = collapsed ? 'true' : 'false';
            adminSidebar.classList.toggle('w-20', collapsed);
            adminSidebar.classList.toggle('w-64', !collapsed);

            adminSidebar.querySelectorAll('[data-sidebar-item]').forEach(function (item) {
                item.classList.toggle('justify-center', collapsed);
                item.classList.toggle('justify-start', !collapsed);
                item.classList.toggle('gap-0', collapsed);
                item.classList.toggle('gap-3', !collapsed);
                item.classList.toggle('px-2', collapsed);
                item.classList.toggle('px-3', !collapsed);

                var title = item.getAttribute('data-sidebar-title') || '';
                item.setAttribute('title', collapsed ? title : '');
            });

            adminSidebar.querySelectorAll('[data-sidebar-label]').forEach(function (el) {
                el.classList.toggle('hidden', collapsed);
            });

            adminSidebar.querySelectorAll('[data-sidebar-tooltip]').forEach(function (el) {
                el.classList.toggle('hidden', !collapsed);
            });

            var expandedIcon = adminSidebar.querySelector('[data-collapse-icon="expanded"]');
            var collapsedIcon = adminSidebar.querySelector('[data-collapse-icon="collapsed"]');
            if (expandedIcon) {
                expandedIcon.classList.toggle('hidden', collapsed);
            }
            if (collapsedIcon) {
                collapsedIcon.classList.toggle('hidden', !collapsed);
            }
        };

        var adminStoredState = localStorage.getItem(adminSidebarStateKey);
        var adminStartCollapsed = adminStoredState === '1';
        setAdminSidebarCollapsed(adminStartCollapsed);

        adminCollapseBtn.addEventListener('click', function () {
            var adminNextCollapsed = adminSidebar.dataset.collapsed !== 'true';
            setAdminSidebarCollapsed(adminNextCollapsed);
            localStorage.setItem(adminSidebarStateKey, adminNextCollapsed ? '1' : '0');
        });
    }
</script>
</body>
</html>
