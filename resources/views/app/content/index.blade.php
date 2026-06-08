@extends('layouts.app', ['title' => 'Content', 'pageWidth' => 'wide'])

@section('content')
    @php
        $activeInbox = (string) ($filters['inbox'] ?? '');
        $viewErrors = $errors ?? new \Illuminate\Support\ViewErrorBag();
        $newContentSiteId = old('site_id', $newContentDefaults['site_id'] ?? '');
        $newContentScheduledAt = old('scheduled_publish_at', $newContentDefaults['scheduled_publish_at'] ?? '');
        $inboxFilters = [
            '' => 'All',
            'needs_brief' => 'Needs brief',
            'brief_in_review' => 'Brief in review',
            'needs_draft' => 'Needs draft',
            'draft_in_review' => 'Draft in review',
            'ready_publish' => 'Ready for publish',
            'published' => 'Published',
        ];
        $statusFilters = [
            '' => 'All',
            'brief' => 'Brief',
            'draft' => 'Draft',
            'review' => 'Review',
            'published' => 'Published',
            'archived' => 'Archived',
        ];
        $secondaryFilterKeys = [
            'origin',
            'series',
            'automation',
            'author',
            'locale',
            'publication_state',
            'translation_state',
            'workflow_state',
            'locale_scope',
            'preset',
            'publish_status',
            'created_from',
            'created_to',
            'published_from',
            'published_to',
            'inbox',
            'show_deleted',
        ];
        $activeSecondaryFilterCount = collect($secondaryFilterKeys)
            ->filter(fn ($key) => filled($filters[$key] ?? null))
            ->count();
        $localeBadgeClasses = [
            'source' => 'border-emerald-200 bg-emerald-50 text-emerald-700',
            'variant' => 'border-sky-200 bg-sky-50 text-sky-700',
            'missing' => 'border-border bg-surfaceSubtle text-textSecondary',
        ];
        $publicationStateOptions = $publicationStateOptions ?? [];
        $translationStateOptions = $translationStateOptions ?? [];
        $workflowStateOptions = $workflowStateOptions ?? [];
        $localeScopeOptions = $localeScopeOptions ?? [];
        $quickFilterPresets = $quickFilterPresets ?? [];
        $filterCounts = $filterCounts ?? [];
        $activeFilterChips = collect([
            'publication_state' => ['label' => 'Publication', 'value' => data_get($publicationStateOptions, $filters['publication_state'] ?? '')],
            'translation_state' => ['label' => 'Translation', 'value' => data_get($translationStateOptions, $filters['translation_state'] ?? '')],
            'workflow_state' => ['label' => 'Workflow', 'value' => data_get($workflowStateOptions, $filters['workflow_state'] ?? '')],
            'locale_scope' => ['label' => 'Locale scope', 'value' => data_get($localeScopeOptions, $filters['locale_scope'] ?? '')],
            'locale' => ['label' => 'Locale', 'value' => filled($filters['locale'] ?? '') ? strtoupper((string) $filters['locale']) : null],
            'origin' => ['label' => 'Origin', 'value' => collect($originOptions ?? [])->firstWhere('value', $filters['origin'] ?? '')?->label()],
            'series' => ['label' => 'Chain', 'value' => collect($contentSeriesList ?? [])->firstWhere('id', $filters['series'] ?? '')?->name],
            'automation' => ['label' => 'Automation', 'value' => collect($contentAutomations ?? [])->firstWhere('id', $filters['automation'] ?? '')?->name],
            'author' => ['label' => 'Author', 'value' => collect($authors ?? [])->firstWhere('id', $filters['author'] ?? '')?->name],
            'inbox' => ['label' => 'Workflow lane', 'value' => $inboxFilters[$filters['inbox'] ?? ''] ?? null],
        ])->filter(fn ($chip) => filled($chip['value'] ?? null));
    @endphp

    <x-app.content-area-header mode="sites" :sites="$sites" :selected-site-id="$filters['site'] ?? null" :filters="$filters" compact>
        @can('create', \App\Models\Content::class)
            <details @if ($createContentFormOpen || $viewErrors->has('title') || $viewErrors->has('primary_keyword') || $viewErrors->has('site_id') || $viewErrors->has('scheduled_publish_at')) open @endif class="relative w-full sm:w-auto sm:shrink-0">
                <summary class="pl-btn-primary w-full list-none cursor-pointer sm:w-auto [&::-webkit-details-marker]:hidden">
                    <i data-lucide="plus" class="h-4 w-4"></i>
                    <span>New Content</span>
                </summary>
                <div class="absolute left-0 right-0 z-30 mt-2 rounded-lg bg-surface p-4 shadow-xl ring-1 ring-border sm:left-auto sm:w-[28rem]">
                    <div class="mb-4 flex flex-wrap gap-2">
                        <a href="{{ route('app.content.batches.create') }}" class="pl-btn-secondary h-9 text-xs">
                            <i data-lucide="layers-3" class="h-4 w-4"></i>
                            Generate multiple articles
                        </a>
                        <a href="{{ route('app.content.create') }}#source-briefing" class="pl-btn-secondary h-9 text-xs">
                            <i data-lucide="link" class="h-4 w-4"></i>
                            Generate from URL
                        </a>
                        @if (config('features.content_network_analysis'))
                            <a href="{{ route('app.content-network.index') }}" class="pl-btn-ghost h-9 text-xs">Content network intelligence</a>
                        @endif
                    </div>
                    <form method="POST" action="{{ route('app.content.store') }}" class="grid gap-3">
                        @csrf
                        <div>
                            <label class="mb-1 block text-xs text-textSecondary" for="new-content-title">Title</label>
                            <input id="new-content-title" type="text" name="title" class="pl-input w-full" required maxlength="255" value="{{ old('title') }}">
                        </div>
                        <div>
                            <label class="mb-1 block text-xs text-textSecondary" for="new-content-primary-keyword">Primary keyword</label>
                            <input id="new-content-primary-keyword" type="text" name="primary_keyword" class="pl-input w-full" maxlength="255" value="{{ old('primary_keyword') }}">
                        </div>
                        <div>
                            <label class="mb-1 block text-xs text-textSecondary" for="new-content-site">Site (optional)</label>
                            <select id="new-content-site" class="pl-select w-full bg-surface" name="site_id">
                                <option value="">Auto select active site</option>
                                @foreach ($sites as $site)
                                    <option value="{{ $site->id }}" @selected($newContentSiteId === (string) $site->id)>{{ $site->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="mb-1 block text-xs text-textSecondary" for="new-content-scheduled-publish-at">Planned publish time (optional)</label>
                            <input
                                id="new-content-scheduled-publish-at"
                                type="datetime-local"
                                name="scheduled_publish_at"
                                class="pl-input w-full"
                                value="{{ $newContentScheduledAt }}"
                            >
                            @if (filled($newContentScheduledAt))
                                <p class="mt-1 text-[11px] text-textSecondary">Prefilled from the content calendar. You can adjust it before opening the workspace.</p>
                            @endif
                        </div>
                        <div class="flex justify-end">
                            <button type="submit" class="pl-btn-secondary">Create and open</button>
                        </div>
                    </form>
                </div>
            </details>
        @endcan
    </x-app.content-area-header>

    <form method="GET" class="mt-4 space-y-3">
        @if (filled($filters['site'] ?? ''))
            <input type="hidden" name="site" value="{{ $filters['site'] }}">
        @endif
        @if (filled($filters['status'] ?? ''))
            <input type="hidden" name="status" value="{{ $filters['status'] }}">
        @endif
        <x-filter-bar class="pl-filter-bar--compact">
            <div class="grid gap-2 xl:grid-cols-[minmax(18rem,1.6fr)_minmax(10rem,0.75fr)_minmax(10rem,0.75fr)_minmax(9rem,0.7fr)_minmax(9rem,0.7fr)_auto_minmax(10rem,0.8fr)]">
                <div class="relative min-w-0 flex-1">
                    <i data-lucide="search" class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-textFaint"></i>
                    <input class="pl-search" name="q" value="{{ $filters['q'] }}" placeholder="Search title or keyword">
                </div>
                <select class="pl-select w-full bg-surface text-sm" name="publication_state" aria-label="Publication status filter" onchange="this.form.submit()">
                    <option value="">Publication status</option>
                    @foreach ($publicationStateOptions as $optionValue => $optionLabel)
                        <option value="{{ $optionValue }}" @selected(($filters['publication_state'] ?? '') === $optionValue)>
                            {{ $optionLabel }}@if (is_numeric(data_get($filterCounts, 'publication.' . $optionValue))) ({{ data_get($filterCounts, 'publication.' . $optionValue) }}) @endif
                        </option>
                    @endforeach
                </select>
                <select class="pl-select w-full bg-surface text-sm" name="translation_state" aria-label="Translation status filter" onchange="this.form.submit()">
                    <option value="">Translation status</option>
                    @foreach ($translationStateOptions as $optionValue => $optionLabel)
                        <option value="{{ $optionValue }}" @selected(($filters['translation_state'] ?? '') === $optionValue)>
                            {{ $optionLabel }}@if (is_numeric(data_get($filterCounts, 'translation.' . $optionValue))) ({{ data_get($filterCounts, 'translation.' . $optionValue) }}) @endif
                        </option>
                    @endforeach
                </select>
                <select class="pl-select w-full bg-surface text-sm" name="workflow_state" aria-label="Workflow filter" onchange="this.form.submit()">
                    <option value="">Workflow</option>
                    @foreach ($workflowStateOptions as $optionValue => $optionLabel)
                        <option value="{{ $optionValue }}" @selected(($filters['workflow_state'] ?? '') === $optionValue)>
                            {{ $optionLabel }}@if (is_numeric(data_get($filterCounts, 'workflow.' . $optionValue))) ({{ data_get($filterCounts, 'workflow.' . $optionValue) }}) @endif
                        </option>
                    @endforeach
                </select>
                <select class="pl-select w-full bg-surface text-sm" name="locale_scope" aria-label="Locale coverage filter" onchange="this.form.submit()">
                    <option value="">Locale coverage</option>
                    @foreach ($localeScopeOptions as $optionValue => $optionLabel)
                        <option value="{{ $optionValue }}" @selected(($filters['locale_scope'] ?? '') === $optionValue)>
                            {{ $optionLabel }}@if (is_numeric(data_get($filterCounts, 'locale_scope.' . $optionValue))) ({{ data_get($filterCounts, 'locale_scope.' . $optionValue) }}) @endif
                        </option>
                    @endforeach
                </select>
                <a href="{{ route('app.content.calendar') }}" class="pl-btn-ghost h-10 w-10 shrink-0 px-0" aria-label="Open calendar view" title="Open calendar view">
                    <i data-lucide="calendar-days" class="h-4 w-4"></i>
                </a>
                <select class="pl-select min-w-0 bg-surface" name="sort" aria-label="Sort content" onchange="this.form.submit()">
                    @foreach ($sortOptions as $sortValue => $sortLabel)
                        <option value="{{ $sortValue }}" @selected(($filters['sort'] ?? 'newest_created') === $sortValue)>{{ $sortLabel }}</option>
                    @endforeach
                </select>
            </div>
            <div class="pl-filter-chip-row">
                <div class="pl-filter-chip-row__inner">
                    @foreach ($quickFilterPresets as $presetKey => $presetConfig)
                        @php
                            $presetQuery = array_merge($filters, ['preset' => $presetKey, 'page' => null]);
                            $presetActive = (string) ($filters['preset'] ?? '') === $presetKey;
                        @endphp
                        <a
                            href="{{ route('app.content.index', $presetQuery) }}"
                            class="shrink-0 rounded-full px-3 py-1.5 text-xs font-medium transition {{ $presetActive ? 'bg-primary text-textInverse' : 'bg-surfaceSubtle text-textSecondary hover:bg-surface hover:text-textPrimary' }}"
                        >
                            {{ $presetConfig['label'] }}
                            @if (is_numeric(data_get($filterCounts, 'presets.' . $presetKey)))
                                <span class="ml-1 opacity-80">{{ data_get($filterCounts, 'presets.' . $presetKey) }}</span>
                            @endif
                        </a>
                    @endforeach
                </div>
            </div>
            <div class="pl-filter-chip-row">
                <div class="pl-filter-chip-row__inner">
                @foreach ($statusFilters as $statusValue => $label)
                    @php
                        $statusQuery = array_merge($filters, ['status' => $statusValue]);
                        unset($statusQuery['page']);
                        $isActive = (string) ($filters['status'] ?? '') === $statusValue;
                    @endphp
                    <a
                        href="{{ route('app.content.index', $statusQuery) }}"
                        class="shrink-0 rounded-full px-3 py-1.5 text-xs font-medium transition {{ $isActive ? 'bg-primary text-textInverse' : 'bg-surfaceSubtle text-textSecondary hover:bg-surface hover:text-textPrimary' }}"
                    >
                        {{ $label }}
                    </a>
                @endforeach
                </div>
            </div>
            @if ($activeFilterChips->isNotEmpty())
                <div class="flex flex-wrap items-center gap-2">
                    @foreach ($activeFilterChips as $filterKey => $chip)
                        @php
                            $removeQuery = $filters;
                            $removeQuery[$filterKey] = null;
                            if ($filterKey !== 'preset') {
                                $removeQuery['preset'] = null;
                            }
                            unset($removeQuery['page']);
                        @endphp
                        <a href="{{ route('app.content.index', $removeQuery) }}" class="inline-flex items-center gap-2 rounded-full border border-border bg-surface px-3 py-1.5 text-xs text-textPrimary hover:bg-surfaceSubtle">
                            <span class="text-textSecondary">{{ $chip['label'] }}</span>
                            <span>{{ $chip['value'] }}</span>
                            <i data-lucide="x" class="h-3.5 w-3.5 text-textSecondary"></i>
                        </a>
                    @endforeach
                </div>
            @endif
            <details class="rounded-lg border border-border/80 bg-surfaceSubtle px-3 py-2">
                <summary class="flex cursor-pointer list-none items-center justify-between gap-4 text-sm font-medium text-textPrimary [&::-webkit-details-marker]:hidden">
                <span class="inline-flex items-center gap-2">
                    <i data-lucide="sliders-horizontal" class="h-4 w-4 text-textSecondary"></i>
                    More filters
                    @if ($activeSecondaryFilterCount > 0)
                        <span class="rounded-full bg-primary px-2 py-0.5 text-[11px] font-semibold text-textInverse">{{ $activeSecondaryFilterCount }} active</span>
                    @endif
                </span>
                <i data-lucide="chevron-down" class="h-4 w-4 text-textSecondary"></i>
                </summary>
                <div class="mt-3 grid gap-3 lg:grid-cols-4">
                <div>
                    <label class="mb-1 block text-xs text-textSecondary" for="content-filter-origin">Origin</label>
                    <select id="content-filter-origin" class="pl-select w-full bg-surface" name="origin">
                        <option value="">All origins</option>
                        @foreach ($originOptions as $originOption)
                            <option value="{{ $originOption->value }}" @selected(($filters['origin'] ?? '') === $originOption->value)>{{ $originOption->label() }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="mb-1 block text-xs text-textSecondary" for="content-filter-series">Chains</label>
                    <select id="content-filter-series" class="pl-select w-full bg-surface" name="series">
                        <option value="">All chains</option>
                        @foreach ($contentSeriesList as $seriesItem)
                            <option value="{{ $seriesItem->id }}" @selected(($filters['series'] ?? '') === (string) $seriesItem->id)>{{ $seriesItem->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="mb-1 block text-xs text-textSecondary" for="content-filter-automation">Automations</label>
                    <select id="content-filter-automation" class="pl-select w-full bg-surface" name="automation">
                        <option value="">All automations</option>
                        @foreach ($contentAutomations as $automationItem)
                            <option value="{{ $automationItem->id }}" @selected(($filters['automation'] ?? '') === (string) $automationItem->id)>{{ $automationItem->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="mb-1 block text-xs text-textSecondary" for="content-filter-author">Author</label>
                    <select id="content-filter-author" class="pl-select w-full bg-surface" name="author">
                        <option value="">All authors</option>
                        @foreach ($authors as $author)
                            <option value="{{ $author->id }}" @selected($filters['author'] === (string) $author->id)>{{ $author->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="mb-1 block text-xs text-textSecondary" for="content-filter-locale">Locale</label>
                    <select id="content-filter-locale" class="pl-select w-full bg-surface" name="locale">
                        <option value="">All locales</option>
                        @foreach ($localeOptions as $localeOption)
                            <option value="{{ $localeOption->value }}" @selected(($filters['locale'] ?? '') === $localeOption->value)>{{ strtoupper($localeOption->value) }} · {{ $localeOption->englishLabel() }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="mb-1 block text-xs text-textSecondary" for="content-filter-publish-status">Publish state</label>
                    <select id="content-filter-publish-status" class="pl-select w-full bg-surface" name="publish_status">
                        <option value="">All publish states</option>
                        @foreach (['draft', 'scheduled', 'publishing', 'published', 'failed'] as $state)
                            <option value="{{ $state }}" @selected(($filters['publish_status'] ?? '') === $state)>{{ $state }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="flex items-end">
                    <label class="inline-flex items-center gap-2 rounded-md border border-border bg-surface px-3 py-2 text-sm text-textPrimary">
                        <input type="checkbox" name="show_deleted" value="1" @checked($filters['show_deleted'] ?? false)>
                        <span>Show deleted</span>
                    </label>
                </div>
                <div>
                    <label class="mb-1 block text-xs text-textSecondary" for="content-filter-inbox">Workflow state</label>
                    <select id="content-filter-inbox" class="pl-select w-full bg-surface" name="inbox">
                        @foreach ($inboxFilters as $inboxValue => $label)
                            <option value="{{ $inboxValue }}" @selected($activeInbox === $inboxValue)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="grid gap-3 sm:grid-cols-2">
                    <div>
                        <label class="mb-1 block text-xs text-textSecondary" for="content-filter-created-from">Created from</label>
                        <input id="content-filter-created-from" type="date" name="created_from" value="{{ $filters['created_from'] ?? '' }}" class="pl-input w-full text-xs">
                    </div>
                    <div>
                        <label class="mb-1 block text-xs text-textSecondary" for="content-filter-created-to">Created to</label>
                        <input id="content-filter-created-to" type="date" name="created_to" value="{{ $filters['created_to'] ?? '' }}" class="pl-input w-full text-xs">
                    </div>
                    <div>
                        <label class="mb-1 block text-xs text-textSecondary" for="content-filter-published-from">Published from</label>
                        <input id="content-filter-published-from" type="date" name="published_from" value="{{ $filters['published_from'] ?? '' }}" class="pl-input w-full text-xs">
                    </div>
                    <div>
                        <label class="mb-1 block text-xs text-textSecondary" for="content-filter-published-to">Published to</label>
                        <input id="content-filter-published-to" type="date" name="published_to" value="{{ $filters['published_to'] ?? '' }}" class="pl-input w-full text-xs">
                    </div>
                </div>
                </div>
                <div class="mt-3 flex flex-wrap items-center justify-end gap-2">
                    <a href="{{ route('app.content.index', filled($filters['site'] ?? '') ? ['site' => $filters['site']] : []) }}" class="pl-btn-ghost">Reset</a>
                    <button class="pl-btn-secondary">Apply filters</button>
                </div>
            </details>
        </x-filter-bar>
    </form>

    <form id="bulk-schedule-form" method="POST" action="{{ route('app.content.schedule-bulk') }}" class="sticky top-4 z-20 mt-6 mb-4 hidden rounded-lg bg-textPrimary px-4 py-3 text-textInverse shadow-xl" data-bulk-action-bar>
        @csrf
        <div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
            <div class="text-sm font-medium"><span data-bulk-selected-count>0</span> selected</div>
            <div class="flex flex-wrap items-center gap-2">
                <label class="sr-only" for="bulk-scheduled-publish-at">Schedule datetime</label>
                <input id="bulk-scheduled-publish-at" type="datetime-local" name="scheduled_publish_at" class="h-9 rounded-md border border-white/20 bg-white/10 px-2 text-xs text-textInverse">
                <button class="rounded-md bg-white px-3 py-2 text-xs font-medium text-textPrimary">Apply schedule</button>
                <button formaction="{{ route('app.content.sync-bulk') }}" class="rounded-md bg-white/10 px-3 py-2 text-xs font-medium text-textInverse hover:bg-white/15">Queue sync</button>
            </div>
        </div>
    </form>

    <x-mobile-card-list class="mt-6">
        @forelse (($contentTree ?? collect()) as $group)
            @if (($group['kind'] ?? '') === 'chain')
                <div class="rounded-xl border border-border/80 bg-surfaceSubtle px-4 py-3 text-xs text-textSecondary">
                    <span class="font-medium text-textPrimary">{{ $group['title'] }}</span>
                    <span class="ml-2">
                        {{ data_get($group, 'summary.visible_article_count', data_get($group, 'summary.article_count', 0)) }}
                        @if ($activeSecondaryFilterCount > 0 || filled($filters['q'] ?? '') || filled($filters['status'] ?? ''))
                            / {{ data_get($group, 'summary.article_count', 0) }}
                        @endif
                        articles
                    </span>
                    <span class="ml-2">{{ data_get($group, 'summary.available_locales', 0) }}/{{ data_get($group, 'summary.expected_locales', 0) }} locales</span>
                </div>
            @endif

            @foreach (($group['articles'] ?? []) as $article)
                @php
                    $canonical = $article['canonical_content'];
                    $variants = collect($article['visible_variants'] ?? []);
                    $allVariants = collect($article['all_variants'] ?? []);
                    $originType = $canonical->origin_type ?? \App\Enums\ContentOriginType::UNKNOWN;
                    if (is_string($originType)) {
                        $originType = \App\Enums\ContentOriginType::tryFrom($originType) ?? \App\Enums\ContentOriginType::UNKNOWN;
                    }
                @endphp
                <article class="pl-mobile-card">
                    <div class="pl-mobile-card__header">
                        <div class="min-w-0 flex-1">
                            <a class="pl-mobile-card__title pl-line-clamp-2" href="{{ route('app.content.show', $canonical) }}">{{ $article['title'] }}</a>
                            <div class="pl-mobile-card__badges">
                                <span class="pl-badge border-border bg-surfaceSubtle text-textSecondary"><span class="pl-badge__label">{{ $article['role_label'] }}</span></span>
                                <span class="pl-badge border-border bg-surfaceSubtle text-textSecondary"><span class="pl-badge__label">{{ $originType->label() }}</span></span>
                                @if ($canonical->trashed())
                                    <span class="pl-badge border-rose-200 bg-rose-50 text-rose-700"><span class="pl-badge__label">Deleted</span></span>
                                @endif
                            </div>
                        </div>
                        <x-status-badge
                            :label="data_get($article, 'summary.status_label', 'In progress')"
                            :color="data_get($article, 'summary.status_color', 'slate')"
                            :tooltip="data_get($article, 'summary.status_tooltip')"
                        />
                    </div>

                    <div class="pl-mobile-card__badges">
                        @foreach ($allVariants as $variant)
                            <x-locale-badge
                                :label="$variant['locale']"
                                :tone="($variant['is_source'] ?? false) ? 'source' : 'variant'"
                                :source="(bool) ($variant['is_source'] ?? false)"
                            />
                        @endforeach
                    </div>

                    <div class="pl-mobile-card__meta">
                        <x-metadata-row label="Publish" :value="data_get($article, 'summary.published_variants', 0) . '/' . (data_get($article, 'summary.available_locales', 0) ?: data_get($article, 'summary.expected_locales', 0)) . ' published'" />
                        <x-metadata-row label="Site" :value="$article['site_label']" />
                        <x-metadata-row label="Created" :value="$canonical->created_at?->format('M j') ?? '-'" />
                        <x-metadata-row label="Published" :value="$canonical->first_published_at?->format('M j') ?? '-'" />
                    </div>
                    @if (collect(data_get($article, 'summary.status_reasons', []))->isNotEmpty())
                        <div class="mt-2 flex flex-wrap gap-1.5">
                            @foreach (data_get($article, 'summary.status_reasons', []) as $reason)
                                <span class="inline-flex items-center rounded-full bg-surfaceSubtle px-2 py-1 text-[11px] text-textSecondary">{{ $reason }}</span>
                            @endforeach
                        </div>
                    @endif

                    @if ($variants->isNotEmpty() || collect($article['translation_targets'] ?? [])->isNotEmpty())
                        <details class="mt-3 border-t border-divider pt-3">
                            <summary class="flex cursor-pointer items-center justify-between text-sm font-medium text-textPrimary">
                                <span>Details</span>
                                <i data-lucide="chevron-down" class="h-4 w-4 text-textSecondary"></i>
                            </summary>
                            <div class="mt-3 space-y-3">
                                @foreach (collect($article['translation_targets'] ?? [])->take(3) as $target)
                                    <div class="rounded-xl border border-border/70 bg-surfaceSubtle px-3 py-2 text-xs text-textPrimary">
                                        <div class="flex items-center justify-between gap-2">
                                            <span class="font-medium">{{ $target['label'] }}</span>
                                            <span class="text-textSecondary">{{ ucfirst((string) ($target['state'] ?? 'ready')) }}</span>
                                        </div>
                                    </div>
                                @endforeach

                                @foreach ($variants as $variant)
                                    @php
                                        $variantContent = $variant['content'];
                                    @endphp
                                    <div class="rounded-xl border border-border/70 bg-surfaceSubtle px-3 py-3">
                                        <div class="flex items-start justify-between gap-2">
                                            <div class="min-w-0">
                                                <div class="flex flex-wrap items-center gap-1.5">
                                                    <x-locale-badge
                                                        :label="$variant['locale']"
                                                        :tone="($variant['is_source'] ?? false) ? 'source' : 'variant'"
                                                        :source="(bool) ($variant['is_source'] ?? false)"
                                                    />
                                                    @if (! empty($variant['source_locale']))
                                                        <span class="pl-badge border-border bg-surface text-textSecondary"><span class="pl-badge__label">SRC {{ $variant['source_locale'] }}</span></span>
                                                    @endif
                                                </div>
                                                <a href="{{ route('app.content.show', $variantContent) }}" class="mt-2 block text-sm font-medium text-textPrimary hover:underline">{{ $variantContent->title }}</a>
                                                <div class="mt-1 text-xs text-textSecondary">{{ $variant['site_label'] }} · {{ $variant['destination_label'] }}</div>
                                            </div>
                                            <a href="{{ route('app.content.show', $variantContent) }}" class="pl-btn-secondary h-9 px-3 text-xs">Open</a>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </details>
                    @endif

                    <div class="pl-mobile-card__actions">
                        <a class="pl-btn-primary flex-1 justify-center" href="{{ route('app.content.show', $canonical) }}">Open</a>
                        <x-action-menu>
                            @if ($canonical->trashed())
                                <form method="POST" action="{{ route('app.content.restore', $canonical->id) }}">
                                    @csrf
                                    <button class="pl-action-menu__item">Restore item</button>
                                </form>
                            @else
                                <button
                                    type="button"
                                    class="pl-action-menu__item"
                                    data-delete-trigger
                                    data-action="{{ route('app.content.delete', $canonical->id) }}"
                                    data-scope="single"
                                    data-count="1"
                                    data-title="{{ $canonical->title }}"
                                    data-published="{{ ((string) $canonical->publish_status === 'published' || (string) $canonical->status === 'published') ? '1' : '0' }}"
                                    data-automation="{{ filled($canonical->automation_id) ? '1' : '0' }}"
                                >
                                    Delete item
                                </button>
                                <button
                                    type="button"
                                    class="pl-action-menu__item"
                                    data-delete-trigger
                                    data-action="{{ route('app.content.delete', $canonical->id) }}"
                                    data-scope="family"
                                    data-count="{{ max(1, $allVariants->count()) }}"
                                    data-title="{{ $canonical->title }}"
                                    data-published="{{ $allVariants->contains(fn ($variant) => ((string) data_get($variant, 'content.publish_status') === 'published' || (string) data_get($variant, 'content.status') === 'published')) ? '1' : '0' }}"
                                    data-automation="{{ $allVariants->contains(fn ($variant) => filled(data_get($variant, 'content.automation_id'))) ? '1' : '0' }}"
                                >
                                    Delete all variants
                                </button>
                            @endif
                        </x-action-menu>
                    </div>
                </article>
            @endforeach
        @empty
            <div class="pl-mobile-card text-sm text-textSecondary">No content matches the current filters.</div>
        @endforelse
    </x-mobile-card-list>

    <div class="pl-desktop-table mt-6">
    <div class="overflow-x-auto rounded-lg border border-border/80 bg-surface p-3 md:p-4">
        <table class="w-full min-w-[1120px] table-auto text-sm">
            <thead>
                <tr class="text-left text-xs uppercase tracking-wide text-textSecondary">
                    <th class="w-14 pb-2 pr-4 font-medium whitespace-nowrap">Item</th>
                    <th class="min-w-[24rem] pb-2 pr-4 font-medium">Content</th>
                    <th class="min-w-[9rem] pb-2 pr-3 font-medium whitespace-nowrap">Locales</th>
                    <th class="min-w-[7rem] pb-2 pr-3 font-medium whitespace-nowrap">Status</th>
                    <th class="min-w-[9rem] pb-2 pr-3 font-medium whitespace-nowrap">Publish</th>
                    <th class="min-w-[8rem] pb-2 pr-3 font-medium whitespace-nowrap">Site</th>
                    <th class="min-w-[6rem] pb-2 pr-3 font-medium whitespace-nowrap">Created</th>
                    <th class="min-w-[6rem] pb-2 pr-3 font-medium whitespace-nowrap">Published</th>
                    <th class="min-w-[10rem] pb-2 font-medium whitespace-nowrap">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-border">
                @forelse (($contentTree ?? collect()) as $group)
                    @if (($group['kind'] ?? '') === 'chain')
                        <tr class="bg-background/60">
                            <td colspan="9" class="px-3 py-2 text-xs text-textSecondary">
                                <span class="font-medium text-textPrimary">{{ $group['title'] }}</span>
                                <span class="ml-2">
                                    {{ data_get($group, 'summary.visible_article_count', data_get($group, 'summary.article_count', 0)) }}
                                    @if ($activeSecondaryFilterCount > 0 || filled($filters['q'] ?? '') || filled($filters['status'] ?? ''))
                                        / {{ data_get($group, 'summary.article_count', 0) }}
                                    @endif
                                    articles
                                </span>
                                <span class="ml-2 rounded-full bg-surfaceSubtle px-2 py-0.5">{{ data_get($group, 'summary.available_locales', 0) }}/{{ data_get($group, 'summary.expected_locales', 0) }} locales</span>
                            </td>
                        </tr>
                    @endif

                    @foreach (($group['articles'] ?? []) as $article)
                        @php
                            $canonical = $article['canonical_content'];
                            $variants = collect($article['visible_variants'] ?? []);
                            $allVariants = collect($article['all_variants'] ?? []);
                            $hasChildren = $variants->count() > 0;
                            $articleInsight = $contentInsights[$canonical->id] ?? [];
                        @endphp
                        <tr
                            class="content-tree-parent-row align-top transition-colors hover:bg-background/70"
                            @if ($hasChildren)
                                data-content-tree-row
                                data-target="{{ $article['key'] }}"
                                data-expanded="false"
                                tabindex="0"
                                role="button"
                                aria-expanded="false"
                            @endif
                        >
                            <td class="py-3 pr-4">
                                @if ($hasChildren)
                                    <button
                                        type="button"
                                        class="inline-flex h-8 w-8 items-center justify-center rounded border border-border text-textSecondary transition hover:bg-surfaceSubtle hover:text-textPrimary"
                                        data-content-tree-toggle
                                        data-target="{{ $article['key'] }}"
                                        aria-expanded="false"
                                        aria-controls="content-tree-children-{{ $article['key'] }}"
                                        data-no-row-toggle
                                    >
                                        <i data-lucide="chevron-right" class="h-4 w-4 transition-transform duration-150 ease-out"></i>
                                    </button>
                                @endif
                            </td>
                            <td class="py-4 pr-4">
                                @php
                                    $originType = $canonical->origin_type ?? \App\Enums\ContentOriginType::UNKNOWN;
                                    if (is_string($originType)) {
                                        $originType = \App\Enums\ContentOriginType::tryFrom($originType) ?? \App\Enums\ContentOriginType::UNKNOWN;
                                    }
                                @endphp
                                <a class="block text-base font-semibold leading-6 text-textPrimary hover:underline" href="{{ route('app.content.show', $canonical) }}">
                                    {{ $article['title'] }}
                                </a>
                                <div class="mt-2 flex flex-wrap items-center gap-1.5">
                                    <span class="inline-flex items-center rounded-full bg-surfaceSubtle px-2 py-0.5 text-[11px] font-medium text-textSecondary">{{ $article['role_label'] }}</span>
                                    <span class="inline-flex items-center rounded-full bg-surfaceSubtle px-2 py-0.5 text-[11px] font-medium text-textSecondary">{{ $originType->label() }}</span>
                                    @if ($canonical->series)
                                        <a href="{{ route('app.content.index', array_merge($filters, ['series' => $canonical->series_id, 'page' => null])) }}" class="inline-flex max-w-[12rem] items-center rounded-full bg-surfaceSubtle px-2 py-0.5 text-[11px] font-medium text-textSecondary hover:text-textPrimary" title="{{ $canonical->series->name }}">
                                            <span class="truncate">{{ $canonical->series->name }}</span>
                                        </a>
                                    @endif
                                    @if ($canonical->automation)
                                        <a href="{{ route('app.content.index', array_merge($filters, ['automation' => $canonical->automation_id, 'page' => null])) }}" class="inline-flex max-w-[12rem] items-center rounded-full bg-surfaceSubtle px-2 py-0.5 text-[11px] font-medium text-textSecondary hover:text-textPrimary" title="{{ $canonical->automation->name }}">
                                            <span class="truncate">{{ $canonical->automation->name }}</span>
                                        </a>
                                    @endif
                                    @if ($canonical->trashed())
                                        <span class="inline-flex items-center rounded-full border border-rose-200 bg-rose-50 px-2 py-0.5 text-[11px] font-medium text-rose-700">Deleted</span>
                                    @endif
                                </div>
                            </td>
                            <td class="py-3 pr-3">
                                <div class="flex flex-wrap gap-1">
                                    @foreach ($allVariants as $variant)
                                        <span class="inline-flex items-center rounded-full border px-1.5 py-0.5 text-[10px] font-medium {{ ($variant['is_source'] ?? false) ? $localeBadgeClasses['source'] : $localeBadgeClasses['variant'] }}">
                                            {{ $variant['locale'] }}
                                        </span>
                                    @endforeach
                                </div>
                            </td>
                            <td class="py-3 pr-3 whitespace-nowrap">
                                <x-status-badge
                                    :label="data_get($article, 'summary.status_label', 'In progress')"
                                    :color="data_get($article, 'summary.status_color', 'slate')"
                                    :tooltip="data_get($article, 'summary.status_tooltip')"
                                />
                                @if (collect(data_get($article, 'summary.status_reasons', []))->isNotEmpty())
                                    <div class="mt-1 flex flex-wrap gap-1">
                                        @foreach (data_get($article, 'summary.status_reasons', []) as $reason)
                                            <span class="inline-flex items-center rounded-full bg-surfaceSubtle px-2 py-0.5 text-[10px] text-textSecondary">{{ $reason }}</span>
                                        @endforeach
                                    </div>
                                @endif
                            </td>
                            <td class="py-3 pr-3">
                                <span class="inline-flex items-center rounded-full bg-surfaceSubtle px-2 py-1 text-xs font-medium text-textPrimary">
                                    {{ data_get($article, 'summary.published_variants', 0) }}/{{ data_get($article, 'summary.available_locales', 0) ?: data_get($article, 'summary.expected_locales', 0) }} published
                                </span>
                                @if (data_get($article, 'summary.failed_deliveries', 0) > 0)
                                    <div class="mt-1 text-[10px] text-amber-600">{{ data_get($article, 'summary.failed_deliveries') }} failed</div>
                                @endif
                            </td>
                            <td class="py-3 pr-3">
                                <div class="text-xs text-textPrimary truncate max-w-[7rem]" title="{{ $article['site_label'] }}">{{ $article['site_label'] }}</div>
                            </td>
                            <td class="py-3 pr-3 whitespace-nowrap text-xs text-textSecondary" title="{{ $canonical->created_at?->format('Y-m-d H:i:s') }}">
                                {{ $canonical->created_at?->format('M j') }}
                            </td>
                            <td class="py-3 pr-3 whitespace-nowrap text-xs text-textSecondary" title="{{ $canonical->first_published_at?->format('Y-m-d H:i:s') }}">
                                {{ $canonical->first_published_at?->format('M j') ?? '-' }}
                            </td>
                            <td class="py-3">
                                <div class="flex flex-wrap items-center gap-2">
                                    @if (! $canonical->trashed())
                                        <a class="text-link underline hover:text-linkHover" href="{{ route('app.content.show', $canonical) }}">Open</a>
                                    @endif
                                    @foreach (collect($article['translation_targets'] ?? [])->take(2) as $target)
                                        @if (! $canonical->trashed())
                                            <div class="rounded border border-border px-2 py-1 text-xs text-textPrimary">
                                                <div class="flex items-center gap-1">
                                                    <span>{{ $target['label'] }}</span>
                                                    @if (($target['state'] ?? 'none') === 'queued')
                                                        <span class="rounded-full bg-amber-100 px-1.5 py-0.5 text-[10px] font-medium text-amber-800">Queued</span>
                                                    @elseif (($target['state'] ?? 'none') === 'processing')
                                                        <span class="rounded-full bg-sky-100 px-1.5 py-0.5 text-[10px] font-medium text-sky-800">Processing</span>
                                                    @elseif (($target['state'] ?? 'none') === 'completed')
                                                        <span class="rounded-full bg-emerald-100 px-1.5 py-0.5 text-[10px] font-medium text-emerald-800">Translated</span>
                                                    @elseif (($target['state'] ?? 'none') === 'failed')
                                                        <span class="rounded-full bg-rose-100 px-1.5 py-0.5 text-[10px] font-medium text-rose-800">Failed</span>
                                                    @endif
                                                </div>
                                                @if (! in_array((string) ($target['state'] ?? 'none'), ['queued', 'processing', 'completed'], true))
                                                    <form method="POST" action="{{ route('app.content.translate', $canonical) }}" class="mt-1">
                                                        @csrf
                                                        <input type="hidden" name="target_locale" value="{{ $target['locale'] }}">
                                                        <button class="rounded border border-border px-2 py-1 text-xs text-textPrimary hover:bg-surfaceSubtle">
                                                            {{ $target['verb'] }} {{ $target['label'] }}
                                                        </button>
                                                    </form>
                                                @endif
                                            </div>
                                        @endif
                                    @endforeach
                                    <details class="relative">
                                        <summary class="cursor-pointer rounded border border-border px-2 py-1 text-xs text-textPrimary hover:bg-surfaceSubtle list-none [&::-webkit-details-marker]:hidden">...</summary>
                                        <div class="absolute right-0 z-20 mt-2 w-52 rounded-md border border-border bg-surface p-2 shadow-lg">
                                            @if ($canonical->trashed())
                                                <form method="POST" action="{{ route('app.content.restore', $canonical->id) }}">
                                                    @csrf
                                                    <button class="w-full rounded px-2 py-2 text-left text-xs text-textPrimary hover:bg-surfaceSubtle">Restore item</button>
                                                </form>
                                            @else
                                                <button
                                                    type="button"
                                                    class="w-full rounded px-2 py-2 text-left text-xs text-textPrimary hover:bg-surfaceSubtle"
                                                    data-delete-trigger
                                                    data-action="{{ route('app.content.delete', $canonical->id) }}"
                                                    data-scope="single"
                                                    data-count="1"
                                                    data-title="{{ $canonical->title }}"
                                                    data-published="{{ ((string) $canonical->publish_status === 'published' || (string) $canonical->status === 'published') ? '1' : '0' }}"
                                                    data-automation="{{ filled($canonical->automation_id) ? '1' : '0' }}"
                                                >
                                                    Delete item
                                                </button>
                                                <button
                                                    type="button"
                                                    class="w-full rounded px-2 py-2 text-left text-xs text-textPrimary hover:bg-surfaceSubtle"
                                                    data-delete-trigger
                                                    data-action="{{ route('app.content.delete', $canonical->id) }}"
                                                    data-scope="family"
                                                    data-count="{{ max(1, $allVariants->count()) }}"
                                                    data-title="{{ $canonical->title }}"
                                                    data-published="{{ $allVariants->contains(fn ($variant) => ((string) data_get($variant, 'content.publish_status') === 'published' || (string) data_get($variant, 'content.status') === 'published')) ? '1' : '0' }}"
                                                    data-automation="{{ $allVariants->contains(fn ($variant) => filled(data_get($variant, 'content.automation_id'))) ? '1' : '0' }}"
                                                >
                                                    Delete all variants
                                                </button>
                                            @endif
                                        </div>
                                    </details>
                                </div>
                            </td>
                        </tr>
                        <tr
                            id="content-tree-children-{{ $article['key'] }}"
                            data-content-tree-children="{{ $article['key'] }}"
                            class="hidden"
                        >
                            <td colspan="9" class="pb-4 pl-8 pr-0">
                                <div
                                    data-content-tree-panel
                                    class="overflow-hidden rounded-md border border-border bg-background/80 px-3 py-3 opacity-0 transition-all duration-150 ease-out"
                                    style="max-height: 0; transform: translateY(-4px);"
                                    aria-hidden="true"
                                >
                                    <div class="space-y-2">
                                        @foreach ($variants as $variant)
                                            @php
                                                $variantContent = $variant['content'];
                                                $variantPresenter = $variant['presenter'];
                                                $variantInsight = $contentInsights[$variantContent->id] ?? [];
                                            @endphp
                                            <div class="grid gap-3 rounded-md border border-border/70 bg-surface px-3 py-3 shadow-sm md:ml-3 md:grid-cols-[minmax(0,2fr)_minmax(0,1fr)_minmax(0,1fr)_minmax(0,1fr)_minmax(0,1fr)]">
                                                <div class="min-w-0">
                                                    <div class="flex flex-wrap items-center gap-2">
                                                        <input form="bulk-schedule-form" type="checkbox" name="content_ids[]" value="{{ $variantContent->id }}" data-bulk-checkbox>
                                                        <span class="inline-flex items-center gap-1 rounded-full border px-2 py-1 text-[11px] font-medium {{ ($variant['is_source'] ?? false) ? $localeBadgeClasses['source'] : $localeBadgeClasses['variant'] }}">
                                                            <span>{{ $variant['locale'] }}</span>
                                                        </span>
                                                        @if (! empty($variant['source_locale']))
                                                            <span class="inline-flex items-center rounded-full border border-border bg-surfaceSubtle px-2 py-1 text-[11px] font-medium text-textSecondary">SRC {{ $variant['source_locale'] }}</span>
                                                        @endif
                                                        @if ($variant['is_source'] ?? false)
                                                            <span class="inline-flex items-center rounded-full border border-border bg-surfaceSubtle px-2 py-1 text-[11px] font-medium text-textSecondary">Source</span>
                                                        @endif
                                                        @if ($variantContent->trashed())
                                                            <span class="inline-flex items-center rounded-full border border-rose-200 bg-rose-50 px-2 py-1 text-[11px] font-medium text-rose-700">Deleted</span>
                                                        @endif
                                                    </div>
                                                    @if (! $variantContent->trashed())
                                                        <a class="mt-2 block min-w-0 truncate text-sm font-medium text-textPrimary hover:underline" href="{{ route('app.content.show', $variantContent) }}">
                                                            {{ $variantContent->title }}
                                                        </a>
                                                    @else
                                                        <div class="mt-2 block min-w-0 truncate text-sm font-medium text-textPrimary">{{ $variantContent->title }}</div>
                                                    @endif
                                                    <div class="mt-1 text-xs text-textSecondary">{{ $variant['site_label'] }} · {{ $variant['destination_label'] }}</div>
                                                </div>
                                                <div>
                                                    <div class="text-[11px] uppercase tracking-wide text-textSecondary">Draft</div>
                                                    <div class="mt-1"><x-content-status :content="$variantContent" :show-remote="false" /></div>
                                                </div>
                                                <div>
                                                    <div class="text-[11px] uppercase tracking-wide text-textSecondary">Publish</div>
                                                    <div class="mt-1">
                                                        <x-status-badge
                                                            :status="$variantPresenter->deliveryStatus()"
                                                            :tooltip="$variantPresenter->lastErrorMessage()"
                                                        />
                                                    </div>
                                                </div>
                                                <div>
                                                    <div class="text-[11px] uppercase tracking-wide text-textSecondary">Performance</div>
                                                    <div class="mt-1 text-xs text-textPrimary">
                                                        @if (is_numeric(data_get($variantInsight, 'roi_score')))
                                                            ROI {{ number_format((float) data_get($variantInsight, 'roi_score'), 1) }}
                                                        @else
                                                            {{ (string) data_get($variant, 'performance.message', data_get($variantInsight, 'status_message', 'Waiting for data.')) }}
                                                        @endif
                                                    </div>
                                                </div>
                                                <div class="text-xs text-textSecondary">
                                                    <div>{{ $variantContent->updated_at?->format('Y-m-d H:i') ?? 'n/a' }}</div>
                                                    <div class="mt-2 flex flex-wrap items-center gap-2">
                                                        @if (! $variantContent->trashed())
                                                            <a class="text-link underline hover:text-linkHover" href="{{ route('app.content.show', $variantContent) }}">Open</a>
                                                            <form method="POST" action="{{ route('app.content.schedule', $variantContent) }}" class="flex items-center gap-1">
                                                                @csrf
                                                                <input type="datetime-local" name="scheduled_publish_at" value="{{ optional($variantContent->scheduled_publish_at)->format('Y-m-d\\TH:i') }}" class="w-[10rem] rounded border border-border bg-background px-2 py-1 text-[11px]">
                                                                <button class="rounded border border-border px-2 py-1 text-[11px] text-textPrimary">Save</button>
                                                            </form>
                                                        @endif
                                                        <details class="relative">
                                                            <summary class="cursor-pointer rounded border border-border px-2 py-1 text-[11px] text-textPrimary hover:bg-surfaceSubtle list-none [&::-webkit-details-marker]:hidden">...</summary>
                                                            <div class="absolute right-0 z-20 mt-2 w-52 rounded-md border border-border bg-surface p-2 shadow-lg">
                                                                @if ($variantContent->trashed())
                                                                    <form method="POST" action="{{ route('app.content.restore', $variantContent->id) }}">
                                                                        @csrf
                                                                        <button class="w-full rounded px-2 py-2 text-left text-[11px] text-textPrimary hover:bg-surfaceSubtle">Restore item</button>
                                                                    </form>
                                                                @else
                                                                    <button
                                                                        type="button"
                                                                        class="w-full rounded px-2 py-2 text-left text-[11px] text-textPrimary hover:bg-surfaceSubtle"
                                                                        data-delete-trigger
                                                                        data-action="{{ route('app.content.delete', $variantContent->id) }}"
                                                                        data-scope="single"
                                                                        data-count="1"
                                                                        data-title="{{ $variantContent->title }}"
                                                                        data-published="{{ ((string) $variantContent->publish_status === 'published' || (string) $variantContent->status === 'published') ? '1' : '0' }}"
                                                                        data-automation="{{ filled($variantContent->automation_id) ? '1' : '0' }}"
                                                                    >
                                                                        Delete item
                                                                    </button>
                                                                    <button
                                                                        type="button"
                                                                        class="w-full rounded px-2 py-2 text-left text-[11px] text-textPrimary hover:bg-surfaceSubtle"
                                                                        data-delete-trigger
                                                                        data-action="{{ route('app.content.delete', $variantContent->id) }}"
                                                                        data-scope="family"
                                                                        data-count="{{ max(1, $allVariants->count()) }}"
                                                                        data-title="{{ $variantContent->title }}"
                                                                        data-published="{{ $allVariants->contains(fn ($variant) => ((string) data_get($variant, 'content.publish_status') === 'published' || (string) data_get($variant, 'content.status') === 'published')) ? '1' : '0' }}"
                                                                        data-automation="{{ $allVariants->contains(fn ($variant) => filled(data_get($variant, 'content.automation_id'))) ? '1' : '0' }}"
                                                                    >
                                                                        Delete all variants
                                                                    </button>
                                                                @endif
                                                            </div>
                                                        </details>
                                                    </div>
                                                </div>
                                            </div>
                                        @endforeach
                                    </div>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                @empty
                    <tr>
                        <td colspan="9">
                            <div class="py-12 text-center">
                                <div class="mx-auto mb-4 flex h-16 w-16 items-center justify-center rounded-full bg-primary/10">
                                    <i data-lucide="file-text" class="h-8 w-8 text-primary"></i>
                                </div>
                                @if (empty($filters['q']) && empty($filters['status']) && empty($filters['site']) && empty($filters['inbox']))
                                    <h3 class="text-lg font-semibold text-textPrimary">No content yet</h3>
                                    <p class="mt-2 max-w-sm mx-auto text-textSecondary">Start creating content to power your publishing workflow. Create a brief, generate drafts, and publish to your sites.</p>
                                    <div class="mt-6 flex flex-wrap items-center justify-center gap-3">
                                        @can('create', \App\Models\Content::class)
                                            <a href="{{ route('app.content.create') }}" class="inline-flex items-center gap-2 rounded bg-primary px-4 py-2 text-sm font-medium text-white hover:bg-primary/90">
                                                <i data-lucide="plus" class="h-4 w-4"></i>
                                                Create your first content
                                            </a>
                                        @endcan
                                        <a href="{{ route('app.sites') }}" class="inline-flex items-center gap-2 rounded border border-border px-4 py-2 text-sm font-medium text-textPrimary hover:bg-surfaceSubtle">
                                            <i data-lucide="globe" class="h-4 w-4"></i>
                                            Connect a website
                                        </a>
                                    </div>
                                @else
                                    <h3 class="text-lg font-semibold text-textPrimary">No content matches your filters</h3>
                                    <p class="mt-2 text-textSecondary">Try adjusting your search or filter criteria.</p>
                                    <a href="{{ route('app.content.index') }}" class="mt-4 inline-flex items-center gap-2 rounded border border-border px-4 py-2 text-sm font-medium text-textPrimary hover:bg-surfaceSubtle">
                                        <i data-lucide="x" class="h-4 w-4"></i>
                                        Clear filters
                                    </a>
                                @endif
                            </div>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
    </div>

    <div class="mt-4">{{ $contents->links() }}</div>

    <dialog id="content-delete-dialog" class="rounded-lg border border-border bg-surface p-0 text-textPrimary shadow-xl backdrop:bg-black/40">
        <div class="w-full max-w-lg p-0">
            <div class="border-b border-border px-5 py-4">
                <h2 class="text-base font-semibold">Confirm delete</h2>
            </div>
            <div class="space-y-3 px-5 py-4">
                <p id="content-delete-message" class="text-sm text-textPrimary"></p>
                <p id="content-delete-warning-published" class="hidden rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800">
                    This selection includes published content. Verify carefully before deleting.
                </p>
                <p id="content-delete-warning-automation" class="hidden rounded-md border border-sky-200 bg-sky-50 px-3 py-2 text-xs text-sky-800">
                    This content comes from an automation and may be created again by future runs.
                </p>
            </div>
            <div class="flex items-center justify-end gap-2 border-t border-border px-5 py-4">
                <button type="button" class="rounded border border-border px-3 py-2 text-sm text-textPrimary" data-delete-cancel>Cancel</button>
                <form id="content-delete-form" method="POST" action="">
                    @csrf
                    <input type="hidden" name="scope" value="single" data-delete-scope>
                    <button class="rounded bg-rose-600 px-3 py-2 text-sm font-medium text-white hover:bg-rose-700">Delete</button>
                </form>
            </div>
        </div>
    </dialog>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const deleteDialog = document.getElementById('content-delete-dialog');
            const deleteForm = document.getElementById('content-delete-form');
            const deleteScopeInput = deleteForm?.querySelector('[data-delete-scope]');
            const deleteMessage = document.getElementById('content-delete-message');
            const publishedWarning = document.getElementById('content-delete-warning-published');
            const automationWarning = document.getElementById('content-delete-warning-automation');
            const deleteCancel = deleteDialog?.querySelector('[data-delete-cancel]');

            document.querySelectorAll('[data-delete-trigger]').forEach((button) => {
                button.addEventListener('click', () => {
                    if (!deleteDialog || !deleteForm || !deleteScopeInput || !deleteMessage) {
                        return;
                    }

                    const count = Number(button.dataset.count || '1');
                    const scope = button.dataset.scope || 'single';
                    const title = button.dataset.title || 'this content';
                    const isPublished = button.dataset.published === '1';
                    const isAutomation = button.dataset.automation === '1';

                    deleteForm.setAttribute('action', button.dataset.action || '');
                    deleteScopeInput.value = scope;
                    deleteMessage.textContent = `Deze actie verwijdert ${count} item${count === 1 ? '' : 's'}${scope === 'family' ? ' in deze family' : ''}. Weet je het zeker? (${title})`;
                    publishedWarning?.classList.toggle('hidden', !isPublished);
                    automationWarning?.classList.toggle('hidden', !isAutomation);
                    deleteDialog.showModal();
                });
            });

            deleteCancel?.addEventListener('click', () => deleteDialog?.close());

            const storageKey = 'argusly.content.index.expanded.v1';
            const interactiveSelector = 'a, button, input, select, textarea, label, summary, [data-no-row-toggle]';

            const readExpandedKeys = () => {
                try {
                    const value = window.localStorage.getItem(storageKey);
                    const parsed = value ? JSON.parse(value) : [];

                    return Array.isArray(parsed) ? new Set(parsed) : new Set();
                } catch (error) {
                    return new Set();
                }
            };

            const persistExpandedKeys = (expandedKeys) => {
                try {
                    window.localStorage.setItem(storageKey, JSON.stringify(Array.from(expandedKeys)));
                } catch (error) {
                    // Ignore storage failures and fall back to collapsed-on-reload behavior.
                }
            };

            const expandedKeys = readExpandedKeys();

            const setExpanded = (target, shouldExpand, options = {}) => {
                const row = document.querySelector(`[data-content-tree-row][data-target="${target}"]`);
                const button = document.querySelector(`[data-content-tree-toggle][data-target="${target}"]`);
                const childRow = document.querySelector(`[data-content-tree-children="${target}"]`);
                const panel = childRow?.querySelector('[data-content-tree-panel]');
                const icon = button?.querySelector('[data-lucide]');

                if (!row || !button || !childRow || !panel) {
                    return;
                }

                const immediate = options.immediate === true;
                const closeAfterTransition = () => {
                    childRow.classList.add('hidden');
                    panel.removeEventListener('transitionend', closeAfterTransition);
                };

                row.setAttribute('aria-expanded', shouldExpand ? 'true' : 'false');
                row.dataset.expanded = shouldExpand ? 'true' : 'false';
                button.setAttribute('aria-expanded', shouldExpand ? 'true' : 'false');
                panel.setAttribute('aria-hidden', shouldExpand ? 'false' : 'true');

                if (icon) {
                    icon.setAttribute('data-lucide', shouldExpand ? 'chevron-down' : 'chevron-right');
                }

                panel.removeEventListener('transitionend', closeAfterTransition);

                if (shouldExpand) {
                    expandedKeys.add(target);
                    childRow.classList.remove('hidden');

                    if (immediate) {
                        panel.style.maxHeight = 'none';
                        panel.style.opacity = '1';
                        panel.style.transform = 'translateY(0)';
                    } else {
                        panel.style.maxHeight = '0px';
                        panel.style.opacity = '0';
                        panel.style.transform = 'translateY(-4px)';

                        requestAnimationFrame(() => {
                            panel.style.maxHeight = `${panel.scrollHeight}px`;
                            panel.style.opacity = '1';
                            panel.style.transform = 'translateY(0)';
                        });
                    }
                } else {
                    expandedKeys.delete(target);

                    if (immediate) {
                        panel.style.maxHeight = '0px';
                        panel.style.opacity = '0';
                        panel.style.transform = 'translateY(-4px)';
                        childRow.classList.add('hidden');
                    } else {
                        panel.style.maxHeight = `${panel.scrollHeight}px`;
                        panel.style.opacity = '1';
                        panel.style.transform = 'translateY(0)';

                        requestAnimationFrame(() => {
                            panel.style.maxHeight = '0px';
                            panel.style.opacity = '0';
                            panel.style.transform = 'translateY(-4px)';
                        });

                        panel.addEventListener('transitionend', closeAfterTransition);
                    }
                }

                persistExpandedKeys(expandedKeys);

                if (window.lucide?.createIcons) {
                    window.lucide.createIcons();
                }
            };

            const toggleExpanded = (target) => {
                const row = document.querySelector(`[data-content-tree-row][data-target="${target}"]`);
                const isExpanded = row?.getAttribute('aria-expanded') === 'true';

                setExpanded(target, !isExpanded);
            };

            document.querySelectorAll('[data-content-tree-toggle]').forEach((button) => {
                const target = button.getAttribute('data-target');

                if (!target) {
                    return;
                }

                button.addEventListener('click', (event) => {
                    event.preventDefault();
                    event.stopPropagation();
                    toggleExpanded(target);
                });
            });

            document.querySelectorAll('[data-content-tree-row]').forEach((row) => {
                const target = row.getAttribute('data-target');

                if (!target) {
                    return;
                }

                row.addEventListener('click', (event) => {
                    if (event.target instanceof Element && event.target.closest(interactiveSelector)) {
                        return;
                    }

                    toggleExpanded(target);
                });

                row.addEventListener('keydown', (event) => {
                    if ((event.key !== 'Enter' && event.key !== ' ') || event.target instanceof Element && event.target.closest(interactiveSelector)) {
                        return;
                    }

                    event.preventDefault();
                    toggleExpanded(target);
                });

                setExpanded(target, expandedKeys.has(target), { immediate: true });
            });

            const bulkBar = document.querySelector('[data-bulk-action-bar]');
            const selectedCountNode = document.querySelector('[data-bulk-selected-count]');
            const bulkCheckboxes = Array.from(document.querySelectorAll('[data-bulk-checkbox]'));

            const updateBulkBar = () => {
                const selectedCount = bulkCheckboxes.filter((checkbox) => checkbox.checked).length;

                if (selectedCountNode) {
                    selectedCountNode.textContent = String(selectedCount);
                }

                if (bulkBar) {
                    bulkBar.classList.toggle('hidden', selectedCount === 0);
                }
            };

            bulkCheckboxes.forEach((checkbox) => {
                checkbox.addEventListener('change', updateBulkBar);
            });

            updateBulkBar();
        });
    </script>
@endsection
