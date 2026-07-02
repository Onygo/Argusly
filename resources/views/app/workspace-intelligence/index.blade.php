@extends('layouts.app', ['title' => __('app.runtime.Workspace Intelligence')])

@section('pageHeader')
    <x-page-header>
        <x-slot:title>{{ __('app.runtime.Workspace Intelligence') }}</x-slot:title>
        <x-slot:description>Turn enrichment output into reusable operating context. Review approved brand context, personas, and team profiles in one place, then apply only the changes that improve your workspace.</x-slot:description>
    </x-page-header>
@endsection

@section('content')
    <div class="space-y-6" data-workspace-intelligence data-workspace-intelligence-active-tab="{{ $activeTab }}">
        <header class="overflow-hidden rounded-lg border border-border bg-surface">
            <div class="grid gap-6 px-6 py-6 lg:grid-cols-[minmax(0,1.25fr),minmax(280px,0.75fr)] lg:px-8">
                <div class="space-y-5">
                    <div class="flex flex-wrap items-center gap-3">
                        <x-workspace-intelligence.status-badge
                            :label="data_get($hub, 'status.label', 'Draft')"
                            :tone="data_get($hub, 'status.tone', 'slate')"
                            :icon="data_get($hub, 'status.icon', 'sparkles')"
                        />
                        <p class="text-sm text-textSecondary">{{ data_get($hub, 'status.description', '') }}</p>
                    </div>

                    <div>
                        <p class="mt-3 max-w-3xl text-sm leading-6 text-textSecondary">
                            Turn enrichment output into reusable operating context. Review approved brand context, personas, and team profiles in one place, then apply only the changes that improve your workspace.
                        </p>
                    </div>

                    <div class="flex flex-wrap gap-2">
                        <a
                            href="{{ route('app.workspace-intelligence.index', ['tab' => 'brand-profile']) }}#workspace-intelligence-actions"
                            data-workspace-tab-link="brand-profile"
                            class="inline-flex items-center justify-center rounded-md bg-primary px-4 py-2 text-sm font-medium text-textInverse"
                        >
                            Run enrichment
                        </a>
                        <a href="{{ route('app.brand.company-profile') }}" class="inline-flex items-center justify-center rounded-md border border-border px-4 py-2 text-sm font-medium text-textPrimary transition hover:bg-surfaceSubtle">
                            Edit profile
                        </a>
                        <a
                            href="{{ route('app.workspace-intelligence.index', ['tab' => 'insights']) }}#pending-runs"
                            data-workspace-tab-link="insights"
                            class="inline-flex items-center justify-center rounded-md border border-border px-4 py-2 text-sm font-medium text-textPrimary transition hover:bg-surfaceSubtle"
                        >
                            Approve changes
                        </a>
                    </div>
                </div>

                <div class="grid gap-3 sm:grid-cols-2">
                    @foreach (($hub['metrics'] ?? []) as $metric)
                        <div class="rounded-lg border border-border bg-background px-4 py-4">
                            <div class="flex items-center gap-3">
                                <span class="inline-flex h-10 w-10 items-center justify-center rounded-full bg-primarySoftBg text-primary">
                                    <i data-lucide="{{ $metric['icon'] ?? 'sparkles' }}" class="h-4 w-4"></i>
                                </span>
                                <div>
                                    <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-textMuted">{{ $metric['label'] }}</p>
                                    <p class="mt-1 text-xl font-semibold text-textPrimary">{{ $metric['value'] }}</p>
                                </div>
                            </div>
                            <p class="mt-3 text-sm leading-6 text-textSecondary">{{ $metric['hint'] }}</p>
                        </div>
                    @endforeach
                </div>
            </div>
        </header>

        @if (session('status'))
            <x-alert>{{ session('status') }}</x-alert>
        @endif

        @if (session('workspace_intelligence_result'))
            <x-alert title="Changes applied">
                Approved update processed successfully.
            </x-alert>
        @endif

        @if ($errors->any())
            <div class="rounded-lg border border-rose-500/30 bg-rose-500/10 px-4 py-3 text-sm text-rose-800">
                {{ $errors->first() }}
            </div>
        @endif

        <div class="rounded-lg border border-border bg-surface p-2">
            <div class="flex flex-wrap gap-2" role="tablist" aria-label="Workspace intelligence sections">
                @foreach (($hub['tabs'] ?? []) as $tab)
                    @php($isActive = $activeTab === $tab['id'])
                    <a
                        href="{{ route('app.workspace-intelligence.index', ['tab' => $tab['id']]) }}"
                        data-workspace-tab-trigger="{{ $tab['id'] }}"
                        role="tab"
                        tabindex="{{ $isActive ? '0' : '-1' }}"
                        aria-selected="{{ $isActive ? 'true' : 'false' }}"
                        class="{{ $isActive ? 'bg-background text-textPrimary shadow-sm' : 'text-textSecondary hover:bg-background/70 hover:text-textPrimary' }} inline-flex items-center gap-2 rounded-lg px-4 py-3 text-sm font-medium transition"
                    >
                        <span>{{ $tab['label'] }}</span>
                        <span class="inline-flex min-w-6 items-center justify-center rounded-full border border-border bg-surface px-2 py-0.5 text-xs text-textSecondary">{{ $tab['count'] }}</span>
                    </a>
                @endforeach
            </div>
        </div>

        <section
            data-workspace-tab-panel="brand-profile"
            role="tabpanel"
            aria-hidden="{{ $activeTab === 'brand-profile' ? 'false' : 'true' }}"
            @class([$activeTab === 'brand-profile' ? 'block' : 'hidden'])
        >
            @include('app.workspace-intelligence.partials.brand-profile')
        </section>

        <section
            data-workspace-tab-panel="personas"
            role="tabpanel"
            aria-hidden="{{ $activeTab === 'personas' ? 'false' : 'true' }}"
            @class([$activeTab === 'personas' ? 'block' : 'hidden'])
        >
            @include('app.workspace-intelligence.partials.personas')
        </section>

        <section
            data-workspace-tab-panel="team"
            role="tabpanel"
            aria-hidden="{{ $activeTab === 'team' ? 'false' : 'true' }}"
            @class([$activeTab === 'team' ? 'block' : 'hidden'])
        >
            @include('app.workspace-intelligence.partials.team')
        </section>

        <section
            data-workspace-tab-panel="insights"
            role="tabpanel"
            aria-hidden="{{ $activeTab === 'insights' ? 'false' : 'true' }}"
            @class([$activeTab === 'insights' ? 'block' : 'hidden'])
        >
            @include('app.workspace-intelligence.partials.insights')
        </section>
    </div>
@endsection
