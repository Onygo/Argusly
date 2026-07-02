@extends('layouts.app', ['title' => 'Campaign Planner', 'pageWidth' => 'wide'])

@section('pageHeader')
    <x-page-header title="Campaign Planner" />
@endsection

@section('pageDescription')
    <x-page-description>Generate structured, approval-gated campaign plans from opportunities, goals, funnel stages, and distribution needs.</x-page-description>
@endsection

@section('primaryActions')
    @if ($selectedCampaign)
        <form method="POST" action="{{ route('app.agentic-marketing.campaign-planner.generate', ['campaign' => $selectedCampaign->id, 'workspace_id' => $workspace->id]) }}">
            @csrf
            <button class="pl-btn-primary" type="submit">
                <i data-lucide="file-plus-2" class="h-4 w-4"></i>
                <span>Approve & generate drafts</span>
            </button>
        </form>
        <a href="{{ route('app.agentic-marketing.distribution.index') }}" class="pl-btn-ghost">
            <i data-lucide="send" class="h-4 w-4"></i>
            <span>Distribution</span>
        </a>
    @endif
@endsection

@section('content')

    @if (session('status'))
        <x-alert class="mb-4">{{ session('status') }}</x-alert>
    @endif

    <div class="grid gap-6 xl:grid-cols-[360px_1fr]">
        <aside class="space-y-4">
            <section class="rounded-lg border border-border bg-surface p-4">
                <h2 class="text-sm font-semibold text-textPrimary">Generate Plan</h2>
                <form method="POST" action="{{ route('app.agentic-marketing.campaign-planner.store') }}" class="mt-4 space-y-4">
                    @csrf
                    <div>
                        <label for="base_campaign_id" class="mb-1 block text-xs font-medium text-textSecondary">Base campaign</label>
                        <select id="base_campaign_id" name="base_campaign_id" class="pl-input w-full">
                            <option value="">New planner campaign</option>
                            @foreach ($baseCampaigns as $baseCampaign)
                                <option value="{{ $baseCampaign->id }}" @selected(old('base_campaign_id') === (string) $baseCampaign->id)>
                                    {{ $baseCampaign->name }}{{ $baseCampaign->contents_count ? ' · '.$baseCampaign->contents_count.' assets' : '' }}
                                </option>
                            @endforeach
                        </select>
                        @error('base_campaign_id') <p class="mt-1 text-xs text-rose-700">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="topic" class="mb-1 block text-xs font-medium text-textSecondary">Topic or override</label>
                        <input id="topic" name="topic" value="{{ old('topic') }}" class="pl-input w-full" maxlength="180" placeholder="Agentic Marketing">
                        @error('topic') <p class="mt-1 text-xs text-rose-700">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="goals" class="mb-1 block text-xs font-medium text-textSecondary">Strategic goals</label>
                        <textarea id="goals" name="goals" class="pl-input w-full" rows="4" placeholder="Build category authority&#10;Support LinkedIn distribution&#10;Improve AI visibility">{{ old('goals') }}</textarea>
                        @error('goals') <p class="mt-1 text-xs text-rose-700">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="audience" class="mb-1 block text-xs font-medium text-textSecondary">Audience</label>
                        <input id="audience" name="audience" value="{{ old('audience') }}" class="pl-input w-full" placeholder="Marketing leaders, founders, operators">
                    </div>
                    <div>
                        <p class="mb-1 block text-xs font-medium text-textSecondary">Languages</p>
                        <div class="grid gap-2 rounded-md border border-border bg-background p-3">
                            @foreach (($enabledCampaignLanguages ?? []) as $language)
                                @php
                                    $languageValue = (string) $language['value'];
                                    $oldLanguages = old('languages');
                                    $checked = is_array($oldLanguages)
                                        ? in_array($languageValue, $oldLanguages, true)
                                        : (bool) ($language['is_default'] ?? false);
                                @endphp
                                <label class="flex items-center gap-2 text-sm text-textSecondary">
                                    <input type="checkbox" name="languages[]" value="{{ $languageValue }}" class="rounded border-border text-primary" @checked($checked) @disabled((bool) ($language['is_default'] ?? false))>
                                    @if ((bool) ($language['is_default'] ?? false))
                                        <input type="hidden" name="languages[]" value="{{ $languageValue }}">
                                    @endif
                                    <span>{{ $language['label'] }}{{ (bool) ($language['is_default'] ?? false) ? ' · default' : '' }}</span>
                                </label>
                            @endforeach
                        </div>
                        @error('languages') <p class="mt-1 text-xs text-rose-700">{{ $message }}</p> @enderror
                        @error('languages.*') <p class="mt-1 text-xs text-rose-700">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="start_date" class="mb-1 block text-xs font-medium text-textSecondary">Start date</label>
                        <input id="start_date" type="date" name="start_date" value="{{ old('start_date') }}" class="pl-input w-full">
                    </div>
                    <details class="rounded-md border border-border bg-background p-3">
                        <summary class="cursor-pointer text-xs font-semibold text-textSecondary">Tracking parameters</summary>
                        <div class="mt-3 grid gap-3">
                            <div class="grid gap-3 sm:grid-cols-2">
                                <label>
                                    <span class="text-xs font-medium text-textSecondary">UTM source</span>
                                    <input name="utm_source" value="{{ old('utm_source') }}" class="pl-input mt-1" maxlength="120" placeholder="linkedin">
                                </label>
                                <label>
                                    <span class="text-xs font-medium text-textSecondary">UTM medium</span>
                                    <input name="utm_medium" value="{{ old('utm_medium') }}" class="pl-input mt-1" maxlength="120" placeholder="social">
                                </label>
                            </div>
                            <label>
                                <span class="text-xs font-medium text-textSecondary">UTM campaign</span>
                                <input name="utm_campaign" value="{{ old('utm_campaign') }}" class="pl-input mt-1" maxlength="180" placeholder="q3-ai-authority">
                            </label>
                            <div class="grid gap-3 sm:grid-cols-2">
                                <label>
                                    <span class="text-xs font-medium text-textSecondary">UTM content</span>
                                    <input name="utm_content" value="{{ old('utm_content') }}" class="pl-input mt-1" maxlength="180" placeholder="thought-leadership">
                                </label>
                                <label>
                                    <span class="text-xs font-medium text-textSecondary">UTM term</span>
                                    <input name="utm_term" value="{{ old('utm_term') }}" class="pl-input mt-1" maxlength="180" placeholder="agentic-marketing">
                                </label>
                            </div>
                        </div>
                    </details>
                    <button class="pl-btn-primary w-full justify-center" type="submit">
                        <i data-lucide="sparkles" class="h-4 w-4"></i>
                        <span>Generate campaign plan</span>
                    </button>
                </form>
            </section>

            <section class="rounded-lg border border-border bg-surface p-4">
                <h2 class="text-sm font-semibold text-textPrimary">Recent Plans</h2>
                <div class="mt-3 space-y-2">
                    @forelse ($campaigns as $campaign)
                        <a href="{{ route('app.agentic-marketing.campaign-planner.index', ['campaign' => $campaign->id]) }}" class="block rounded-md border border-border bg-background p-3 transition hover:border-primary/40">
                            <div class="flex items-center justify-between gap-3">
                                <p class="truncate text-sm font-medium text-textPrimary">{{ $campaign->name }}</p>
                                <span class="rounded-full bg-surfaceSubtle px-2 py-0.5 text-[11px] text-textSecondary">{{ $campaign->contents_count }}</span>
                            </div>
                            <p class="mt-1 text-xs text-textSecondary">{{ str_replace('_', ' ', $campaign->status?->value ?? $campaign->status) }} · {{ str_replace('_', ' ', $campaign->approval_status?->value ?? $campaign->approval_status) }}</p>
                        </a>
                    @empty
                        <p class="text-sm text-textSecondary">No campaign plans yet.</p>
                    @endforelse
                </div>
            </section>

            <section class="rounded-lg border border-border bg-surface p-4">
                <h2 class="text-sm font-semibold text-textPrimary">Opportunity Context</h2>
                <div class="mt-3 space-y-2">
                    @forelse ($opportunities as $opportunity)
                        <div class="rounded-md border border-border bg-background p-3">
                            <p class="text-sm font-medium text-textPrimary">{{ $opportunity->title }}</p>
                            <p class="mt-1 text-xs text-textSecondary">{{ str_replace('_', ' ', $opportunity->category?->value ?? $opportunity->category) }} · Priority {{ number_format((float) $opportunity->priority_score, 0) }}</p>
                        </div>
                    @empty
                        <p class="text-sm text-textSecondary">No open opportunity signals are available yet.</p>
                    @endforelse
                </div>
            </section>
        </aside>

        <main class="space-y-6">
            @if ($selectedCampaign)
                @php
                    $planningContext = (array) $selectedCampaign->ai_planning_context;
                    $visualMap = (array) data_get($planningContext, 'visual_map', []);
                    $lanes = collect(data_get($visualMap, 'lanes', []));
                    $nodes = collect(data_get($visualMap, 'nodes', []))->keyBy('id');
                    $schedule = collect(data_get($visualMap, 'schedule', []))->keyBy('asset_key');
                    $toneVariations = (array) data_get($selectedCampaign->optimization_signals, 'tone_variations', []);
                    $repurposing = (array) data_get($selectedCampaign->optimization_signals, 'repurposing_recommendations', []);
                    $checkpoints = (array) data_get($planningContext, 'approval_checkpoints', []);
                    $creditEstimate = (array) ($generationEstimate ?? []);
                    $estimatedCredits = (int) ($creditEstimate['estimated_credits'] ?? 0);
                    $pendingCredits = (int) ($creditEstimate['pending_credits'] ?? $estimatedCredits);
                    $pendingDraftAssets = (int) ($creditEstimate['pending_draft_assets'] ?? 0);
                    $draftAssets = (int) ($creditEstimate['draft_assets'] ?? 0);
                    $noCreditAssets = (int) ($creditEstimate['no_credit_assets'] ?? 0);
                    $languageCount = (int) ($creditEstimate['language_count'] ?? 1);
                    $campaignLanguages = (array) ($creditEstimate['languages'] ?? data_get($selectedCampaign->metadata, 'campaign_languages', []));
                    $availableCredits = $generationAvailableCredits;
                    $remainingAfterFullPlan = is_numeric($availableCredits) ? max(0, (int) $availableCredits - $estimatedCredits) : null;
                    $activeFilters = (array) ($activeAssetFilters ?? []);
                @endphp

                <section class="rounded-lg border border-border bg-surface p-5">
                    <div class="flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
                        <div>
                            <div class="flex flex-wrap gap-2">
                                <span class="rounded-full border border-border px-2.5 py-1 text-xs text-textSecondary">{{ str_replace('_', ' ', $selectedCampaign->status?->value ?? $selectedCampaign->status) }}</span>
                                <span class="rounded-full border border-border px-2.5 py-1 text-xs text-textSecondary">{{ str_replace('_', ' ', $selectedCampaign->approval_status?->value ?? $selectedCampaign->approval_status) }}</span>
                                <span class="rounded-full border border-border px-2.5 py-1 text-xs text-textSecondary">{{ $selectedCampaign->contents->count() }} assets</span>
                            </div>
                            <h2 class="mt-3 text-xl font-semibold text-textPrimary">{{ $selectedCampaign->name }}</h2>
                            <p class="mt-1 max-w-3xl text-sm text-textSecondary">{{ $selectedCampaign->objective }}</p>
                        </div>
                        <div class="grid gap-2 text-xs text-textSecondary sm:grid-cols-2 lg:grid-cols-[minmax(12rem,1.25fr)_auto_auto]">
                            <div class="rounded-md border {{ is_numeric($availableCredits) && $estimatedCredits > (int) $availableCredits ? 'border-rose-200 bg-rose-50' : 'border-border bg-background' }} px-3 py-2">
                                <div class="flex items-center gap-1.5">
                                    <i data-lucide="coins" class="h-3.5 w-3.5"></i>
                                    <span>Estimated credits</span>
                                </div>
                                <span class="mt-1 block text-base font-semibold text-textPrimary">{{ number_format($estimatedCredits) }}</span>
                                <span class="block">
                                    {{ number_format($draftAssets) }} draft {{ Str::plural('asset', $draftAssets) }}
                                    @if ($languageCount > 1)
                                        × {{ number_format($languageCount) }} languages
                                    @endif
                                    @if ($noCreditAssets > 0)
                                        · {{ number_format($noCreditAssets) }} no-credit {{ Str::plural('asset', $noCreditAssets) }}
                                    @endif
                                </span>
                                @if ($campaignLanguages !== [])
                                    <span class="block">Languages {{ collect($campaignLanguages)->map(fn ($language) => strtoupper((string) $language))->implode(', ') }}</span>
                                @endif
                                @if ($pendingCredits !== $estimatedCredits)
                                    <span class="block">Remaining to queue: {{ number_format($pendingCredits) }}</span>
                                @endif
                                @if (is_numeric($availableCredits))
                                    <span class="block {{ $estimatedCredits > (int) $availableCredits ? 'font-medium text-rose-700' : '' }}">
                                        Available {{ number_format((int) $availableCredits) }}
                                        @if ($remainingAfterFullPlan !== null)
                                            · after plan {{ number_format($remainingAfterFullPlan) }}
                                        @endif
                                    </span>
                                @endif
                            </div>
                            <div class="rounded-md border border-border bg-background px-3 py-2">Start <span class="block font-medium text-textPrimary">{{ optional($selectedCampaign->planned_start_date)->toFormattedDateString() ?? 'Draft' }}</span></div>
                            <div class="rounded-md border border-border bg-background px-3 py-2">End <span class="block font-medium text-textPrimary">{{ optional($selectedCampaign->planned_end_date)->toFormattedDateString() ?? 'Draft' }}</span></div>
                        </div>
                    </div>
                </section>

                @php
                    $campaignImageAssetCollection = collect($campaignImageAssets ?? []);
                    $campaignImageUsageTargets = [
                        'display_on_website' => 'Website image',
                        'display_as_featured_image' => 'Featured image',
                        'use_as_meta_image' => 'Meta image',
                        'use_as_social_image' => 'Social image',
                        'use_for_linkedin' => 'LinkedIn image',
                    ];
                @endphp
                <section class="rounded-lg border border-border bg-surface p-5">
                    <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                        <div>
                            <h2 class="text-sm font-semibold text-textPrimary">Campaign image assets</h2>
                            <p class="mt-1 text-xs text-textSecondary">Upload campaign images and choose whether they are website, meta, or LinkedIn/social assets.</p>
                        </div>
                        <span class="rounded-full border border-border bg-background px-2.5 py-1 text-xs text-textSecondary">{{ $campaignImageAssetCollection->count() }} assets</span>
                    </div>

                    <form method="POST" action="{{ route('app.campaigns.images.upload', $selectedCampaign) }}" enctype="multipart/form-data" class="mt-4 space-y-3">
                        @csrf
                        <div class="grid gap-3 md:grid-cols-[1fr_1fr]">
                            <input
                                type="file"
                                name="image"
                                accept="image/jpeg,image/png,image/webp"
                                class="pl-input w-full text-sm"
                                required
                            >
                            <input
                                name="alt_text"
                                value="{{ old('alt_text') }}"
                                class="pl-input w-full text-sm"
                                placeholder="Alt text"
                            >
                        </div>
                        <div class="grid gap-2 text-sm text-textSecondary md:grid-cols-2 lg:grid-cols-5">
                            @foreach ($campaignImageUsageTargets as $field => $label)
                                <label class="flex items-center gap-2 rounded border border-border bg-background px-3 py-2">
                                    <input type="checkbox" name="{{ $field }}" value="1">
                                    <span>{{ $label }}</span>
                                </label>
                            @endforeach
                        </div>
                        <button class="inline-flex h-9 items-center gap-1.5 rounded-md border border-border bg-background px-3 text-sm font-medium text-textPrimary hover:bg-surfaceMuted">
                            <i data-lucide="upload" class="h-4 w-4"></i>
                            Upload image
                        </button>
                    </form>

                    @if ($campaignImageAssetCollection->isNotEmpty())
                        <div class="mt-4 grid gap-3 md:grid-cols-3">
                            @foreach ($campaignImageAssetCollection as $campaignImageAsset)
                                @php
                                    $campaignAssetPreviewUrl = $campaignImageAsset->medium_ui_url ?: $campaignImageAsset->original_ui_url;
                                    $campaignAssetLabels = collect($campaignImageUsageTargets)
                                        ->filter(fn ($label, $field) => (bool) $campaignImageAsset->{$field})
                                        ->values();
                                @endphp
                                <div class="rounded-md border border-border bg-background p-3">
                                    @if ($campaignAssetPreviewUrl)
                                        <img src="{{ $campaignAssetPreviewUrl }}" alt="{{ $campaignImageAsset->alt_text ?: 'Campaign image asset preview' }}" class="h-28 w-full rounded border border-border object-cover">
                                    @else
                                        <div class="flex h-28 items-center justify-center rounded border border-dashed border-border text-xs text-textSecondary">No preview</div>
                                    @endif
                                    <div class="mt-2 flex flex-wrap gap-1.5 text-[11px] text-textSecondary">
                                        <span class="rounded bg-white px-2 py-0.5">{{ $campaignImageAsset->source ?: $campaignImageAsset->provider ?: 'asset' }}</span>
                                        @foreach ($campaignAssetLabels as $label)
                                            <span class="rounded bg-green-100 px-2 py-0.5 text-green-700">{{ $label }}</span>
                                        @endforeach
                                    </div>
                                    <form method="POST" action="{{ route('app.campaigns.images.usage.update', ['campaign' => $selectedCampaign, 'imageVersion' => $campaignImageAsset]) }}" class="mt-3 space-y-2">
                                        @csrf
                                        <div class="grid gap-1.5 text-xs text-textSecondary">
                                            @foreach ($campaignImageUsageTargets as $field => $label)
                                                <label class="flex items-center gap-2 rounded border border-border bg-white px-2 py-1.5">
                                                    <input type="checkbox" name="{{ $field }}" value="1" @checked((bool) $campaignImageAsset->{$field})>
                                                    <span>{{ $label }}</span>
                                                </label>
                                            @endforeach
                                        </div>
                                        <button class="w-full rounded border border-border bg-white px-3 py-2 text-sm text-textPrimary hover:bg-surfaceMuted">Save usage targets</button>
                                    </form>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </section>

                <section class="rounded-lg border border-border bg-surface p-5">
                    <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                        <div>
                            <h2 class="text-sm font-semibold text-textPrimary">Campaign Asset Summary</h2>
                            <p class="mt-1 text-xs text-textSecondary">Assets are classified by type, purpose, workflow, publication, distribution, and required action.</p>
                        </div>
                        <a href="{{ route('app.agentic-marketing.campaign-planner.index', ['campaign' => $selectedCampaign->id, 'workspace_id' => $workspace->id]) }}" class="inline-flex h-8 items-center gap-1.5 rounded-md border border-border bg-background px-2.5 text-xs font-medium text-textPrimary hover:bg-surfaceMuted">
                            <i data-lucide="rotate-ccw" class="h-3.5 w-3.5"></i>
                            Reset filters
                        </a>
                    </div>

                    <div class="mt-4 grid gap-2 sm:grid-cols-2 lg:grid-cols-4 xl:grid-cols-6">
                        @foreach ($campaignAssetSummary as $summaryItem)
                            <div class="rounded-md border border-border bg-background p-3">
                                <div class="flex items-center justify-between gap-2">
                                    <span class="inline-flex items-center gap-1.5 rounded-full border px-2 py-0.5 text-[11px] font-semibold {{ $summaryItem['classes'] }}">
                                        <x-app.icon :name="$summaryItem['icon']" class="h-3 w-3" />
                                        {{ $summaryItem['badge'] }}
                                    </span>
                                    <span class="text-sm font-semibold text-textPrimary">{{ $summaryItem['count'] }}</span>
                                </div>
                                <p class="mt-2 truncate text-xs font-medium text-textSecondary">{{ $summaryItem['label'] }}</p>
                            </div>
                        @endforeach
                    </div>

                    <form method="GET" action="{{ route('app.agentic-marketing.campaign-planner.index') }}" class="mt-4 grid gap-3 md:grid-cols-5">
                        <input type="hidden" name="campaign" value="{{ $selectedCampaign->id }}">
                        <input type="hidden" name="workspace_id" value="{{ $workspace->id }}">
                        <label>
                            <span class="mb-1 block text-xs font-medium text-textSecondary">Type</span>
                            <select name="asset_type" class="pl-input w-full text-sm" onchange="this.form.submit()">
                                <option value="">All types</option>
                                @foreach (($assetFilterOptions['types'] ?? []) as $option)
                                    <option value="{{ $option['value'] }}" @selected(($activeFilters['asset_type'] ?? '') === $option['value'])>{{ $option['label'] }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label>
                            <span class="mb-1 block text-xs font-medium text-textSecondary">Purpose</span>
                            <select name="purpose" class="pl-input w-full text-sm" onchange="this.form.submit()">
                                <option value="">All purposes</option>
                                @foreach (($assetFilterOptions['purposes'] ?? []) as $value => $label)
                                    <option value="{{ $value }}" @selected(($activeFilters['purpose'] ?? '') === $value)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label>
                            <span class="mb-1 block text-xs font-medium text-textSecondary">Workflow</span>
                            <select name="workflow_state" class="pl-input w-full text-sm" onchange="this.form.submit()">
                                <option value="">All workflow</option>
                                @foreach (($assetFilterOptions['workflow_states'] ?? []) as $value => $label)
                                    <option value="{{ $value }}" @selected(($activeFilters['workflow_state'] ?? '') === $value)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label>
                            <span class="mb-1 block text-xs font-medium text-textSecondary">Publication</span>
                            <select name="publication_state" class="pl-input w-full text-sm" onchange="this.form.submit()">
                                <option value="">All publication</option>
                                @foreach (($assetFilterOptions['publication_states'] ?? []) as $value => $label)
                                    <option value="{{ $value }}" @selected(($activeFilters['publication_state'] ?? '') === $value)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label>
                            <span class="mb-1 block text-xs font-medium text-textSecondary">Distribution</span>
                            <select name="distribution_state" class="pl-input w-full text-sm" onchange="this.form.submit()">
                                <option value="">All distribution</option>
                                @foreach (($assetFilterOptions['distribution_states'] ?? []) as $value => $label)
                                    <option value="{{ $value }}" @selected(($activeFilters['distribution_state'] ?? '') === $value)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </label>
                    </form>
                </section>

                <section class="rounded-lg border border-border bg-surface">
                    <div class="flex items-center justify-between border-b border-border px-5 py-4">
                        <div>
                            <h2 class="text-sm font-semibold text-textPrimary">Visual Campaign Map</h2>
                            <p class="text-xs text-textSecondary">Drag cards between lanes while reviewing the plan. Persisted ordering remains approval-gated.</p>
                        </div>
                        <span class="rounded-full bg-surfaceSubtle px-2.5 py-1 text-xs text-textSecondary">Draft map</span>
                    </div>
                    <div class="grid gap-4 p-4 lg:grid-cols-4" data-campaign-map>
                        @forelse ($lanes as $lane)
                            <div class="min-h-80 rounded-lg border border-border bg-background p-3" data-map-lane="{{ $lane['id'] ?? '' }}">
                                <div class="mb-3 flex items-center justify-between">
                                    <h3 class="text-sm font-semibold text-textPrimary">{{ $lane['label'] ?? Str::headline((string) ($lane['id'] ?? 'Lane')) }}</h3>
                                    <span class="text-xs text-textSecondary">{{ count((array) ($lane['asset_keys'] ?? [])) }}</span>
                                </div>
                                <div class="space-y-3" data-dropzone>
                                    @foreach ((array) ($lane['asset_keys'] ?? []) as $assetKey)
                                        @php
                                            $node = (array) $nodes->get($assetKey, []);
                                            $scheduled = (array) $schedule->get($assetKey, []);
                                            $checkpoint = (array) data_get($checkpoints, $assetKey, []);
                                            $nodeDefinition = \App\Support\ContentAssets\ContentAssetTaxonomy::definition((string) ($node['type'] ?? 'article'));
                                            $nodeBadgeClasses = \App\Support\ContentAssets\ContentAssetTaxonomy::typeBadgeClasses((string) ($nodeDefinition['color'] ?? 'slate'));
                                        @endphp
                                        <article draggable="true" class="cursor-grab rounded-md border border-border bg-surface p-3 shadow-sm" data-map-card="{{ $assetKey }}">
                                            <div class="flex items-start justify-between gap-2">
                                                <p class="text-sm font-medium text-textPrimary">{{ $node['label'] ?? $assetKey }}</p>
                                                <i data-lucide="grip-vertical" class="h-4 w-4 text-textFaint"></i>
                                            </div>
                                            <div class="mt-2 flex flex-wrap gap-1.5">
                                                <span class="inline-flex items-center gap-1 rounded-full border px-2 py-0.5 text-[11px] font-semibold {{ $nodeBadgeClasses }}">
                                                    <x-app.icon :name="$nodeDefinition['icon'] ?? 'box'" class="h-3 w-3" />
                                                    {{ $nodeDefinition['badge'] ?? Str::upper((string) ($node['type'] ?? 'asset')) }}
                                                </span>
                                                <span class="rounded-full bg-surfaceSubtle px-2 py-0.5 text-[11px] text-textSecondary">{{ $node['funnel_stage'] ?? 'stage' }}</span>
                                            </div>
                                            <p class="mt-2 text-xs text-textSecondary">{{ $scheduled['date'] ?? 'Unscheduled' }} · {{ str_replace('_', ' ', (string) ($checkpoint['status'] ?? 'requested')) }}</p>
                                        </article>
                                    @endforeach
                                </div>
                            </div>
                        @empty
                            <div class="col-span-full rounded-md border border-dashed border-border p-8 text-center text-sm text-textSecondary">No visual map is stored for this campaign yet.</div>
                        @endforelse
                    </div>
                </section>

                <div class="grid gap-6 xl:grid-cols-[1.2fr_1fr]">
                    <section class="rounded-lg border border-border bg-surface">
                        <div class="border-b border-border px-5 py-4">
                            <h2 class="text-sm font-semibold text-textPrimary">Planned Assets</h2>
                            <p class="mt-1 text-xs text-textSecondary">{{ $campaignAssetCards->count() }} of {{ $selectedCampaign->contents->count() }} assets shown.</p>
                        </div>
                        <div class="divide-y divide-border">
                            @forelse ($campaignAssetCards as $asset)
                                @php
                                    $brief = (array) $asset->brief;
                                    $meta = (array) $asset->metadata;
                                    $assetKey = (string) ($meta['planner_key'] ?? $asset->id);
                                    $assetUx = (array) $assetPresenters->get((string) $asset->id, []);
                                @endphp
                                <article class="p-4">
                                    <div class="flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
                                        <div>
                                            <div class="flex flex-wrap gap-2">
                                                <span class="rounded-full border border-border px-2.5 py-1 text-xs text-textSecondary">#{{ $asset->sequence_order }}</span>
                                                <span class="inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-xs font-semibold {{ $assetUx['type_badge_classes'] ?? 'border-border bg-surfaceSubtle text-textSecondary' }}">
                                                    <x-app.icon :name="$assetUx['type_icon'] ?? 'box'" class="h-3.5 w-3.5" />
                                                    {{ $assetUx['type_badge'] ?? str_replace('_', ' ', $asset->asset_type?->value ?? $asset->asset_type) }}
                                                </span>
                                                <span class="rounded-full border border-border px-2.5 py-1 text-xs text-textSecondary">{{ $assetUx['purpose_label'] ?? 'Primary Content' }}</span>
                                                <span class="rounded-full border border-border px-2.5 py-1 text-xs text-textSecondary">{{ data_get($brief, 'funnel_stage', 'stage') }}</span>
                                                <span class="rounded-full border px-2.5 py-1 text-xs font-medium {{ $assetUx['workflow_state_classes'] ?? 'border-border bg-surfaceSubtle text-textSecondary' }}">{{ $assetUx['workflow_state_label'] ?? 'Draft' }}</span>
                                            </div>
                                            <h3 class="mt-2 font-semibold text-textPrimary">{{ $asset->working_title }}</h3>
                                            <p class="mt-1 text-sm text-textSecondary">{{ data_get($brief, 'angle') }}</p>
                                        </div>
                                        <div class="text-right text-xs text-textSecondary">
                                            <div>{{ optional($asset->scheduled_for)->toDayDateTimeString() ?? 'Unscheduled' }}</div>
                                            <div>{{ str_replace('_', ' ', $asset->approval_status?->value ?? $asset->approval_status) }}</div>
                                        </div>
                                    </div>
                                    <div class="mt-3 grid gap-3 md:grid-cols-3">
                                        <div class="rounded-md border border-border bg-background p-3">
                                            <p class="text-xs font-medium uppercase tracking-wide text-textFaint">Publication</p>
                                            <p class="mt-1 inline-flex rounded-full border px-2 py-0.5 text-xs font-medium {{ $assetUx['publication_state_classes'] ?? 'border-border bg-surfaceSubtle text-textSecondary' }}">{{ $assetUx['publication_state_label'] ?? 'Unpublished' }}</p>
                                        </div>
                                        <div class="rounded-md border border-border bg-background p-3">
                                            <p class="text-xs font-medium uppercase tracking-wide text-textFaint">Distribution</p>
                                            <p class="mt-1 inline-flex rounded-full border px-2 py-0.5 text-xs font-medium {{ $assetUx['distribution_state_classes'] ?? 'border-border bg-surfaceSubtle text-textSecondary' }}">{{ $assetUx['distribution_state_label'] ?? 'Not Distributed' }}</p>
                                        </div>
                                        <div class="rounded-md border border-border bg-background p-3">
                                            <p class="text-xs font-medium uppercase tracking-wide text-textFaint">Required Action</p>
                                            <p class="mt-1 text-sm font-medium text-textPrimary">{{ $assetUx['required_action'] ?? 'No action required' }}</p>
                                        </div>
                                    </div>
                                    <div class="mt-3 grid gap-3 md:grid-cols-3">
                                        <div class="rounded-md border border-border bg-background p-3">
                                            <p class="text-xs font-medium uppercase tracking-wide text-textFaint">Audience</p>
                                            <p class="mt-1 text-sm text-textPrimary">{{ data_get($brief, 'audience_segment') }}</p>
                                        </div>
                                        <div class="rounded-md border border-border bg-background p-3">
                                            <p class="text-xs font-medium uppercase tracking-wide text-textFaint">Tone</p>
                                            <p class="mt-1 text-sm text-textPrimary">{{ data_get($asset->ai_generation_context, 'tone_variation') }}</p>
                                        </div>
                                        <div class="rounded-md border border-border bg-background p-3">
                                            <p class="text-xs font-medium uppercase tracking-wide text-textFaint">Channels</p>
                                            <p class="mt-1 text-sm text-textPrimary">{{ $asset->distributionPlans->pluck('distributionChannel.name')->filter()->implode(', ') ?: 'Draft' }}</p>
                                        </div>
                                    </div>
                                    @if (($asset->asset_type?->value ?? $asset->asset_type) === 'newsletter_snippet')
                                        @php($latestEmailExport = $asset->emailCampaignExports->sortByDesc('created_at')->first())
                                        <div class="mt-3 rounded-md border border-border bg-background p-3">
                                            <div class="flex flex-col gap-2 md:flex-row md:items-center md:justify-between">
                                                <div>
                                                    <p class="text-xs font-medium uppercase tracking-wide text-textFaint">Email marketing</p>
                                                    <p class="mt-1 text-sm text-textPrimary">
                                                        {{ $latestEmailExport ? 'Last export: '.str_replace('_', ' ', $latestEmailExport->status?->value ?? $latestEmailExport->status) : 'Ready for email placement' }}
                                                    </p>
                                                    @if ($latestEmailExport?->remote_url)
                                                        <a href="{{ $latestEmailExport->remote_url }}" target="_blank" rel="noopener" class="mt-1 inline-flex text-xs font-medium text-primary">Open remote draft</a>
                                                    @endif
                                                </div>
                                                @if ($emailMarketingConnections->isNotEmpty())
                                                    <form method="POST" action="{{ route('app.agentic-marketing.campaign-planner.assets.email-export', ['campaignContent' => $asset->id, 'workspace_id' => $workspace->id]) }}" class="flex flex-col gap-2 sm:flex-row sm:items-center">
                                                        @csrf
                                                        <select name="connection_id" class="pl-input h-9 min-w-44 text-sm">
                                                            @foreach ($emailMarketingConnections as $connection)
                                                                <option value="{{ $connection->id }}">{{ $connection->name }}</option>
                                                            @endforeach
                                                        </select>
                                                        <input type="hidden" name="subject" value="{{ $asset->working_title }}">
                                                        <button class="inline-flex h-9 items-center justify-center gap-1.5 rounded-md bg-primary px-3 text-sm font-medium text-textInverse" type="submit">
                                                            <i data-lucide="mail-plus" class="h-4 w-4"></i>
                                                            <span>Push to email tool</span>
                                                        </button>
                                                    </form>
                                                @else
                                                    <a href="{{ route('app.developer.index', ['tab' => 'destinations', 'workspace_id' => $workspace->id]) }}" class="inline-flex h-9 items-center justify-center rounded-md border border-border px-3 text-sm font-medium text-textPrimary hover:bg-surfaceSubtle">Connect email tool</a>
                                                @endif
                                            </div>
                                        </div>
                                    @endif
                                    @if (! empty($repurposing[$assetKey]))
                                        <div class="mt-3 rounded-md border border-border bg-background p-3">
                                            <p class="text-xs font-medium uppercase tracking-wide text-textFaint">Repurposing</p>
                                            <div class="mt-2 flex flex-wrap gap-2">
                                                @foreach ((array) $repurposing[$assetKey] as $recommendation)
                                                    <span class="rounded-full bg-surfaceSubtle px-2.5 py-1 text-xs text-textSecondary">{{ str_replace('_', ' ', $recommendation['target'] ?? 'reuse') }}</span>
                                                @endforeach
                                            </div>
                                        </div>
                                    @endif
                                </article>
                            @empty
                                <div class="p-6 text-sm text-textSecondary">No assets match the current filters.</div>
                            @endforelse
                        </div>
                    </section>

                    <section class="space-y-6">
                        <div class="rounded-lg border border-border bg-surface p-4">
                            <h2 class="text-sm font-semibold text-textPrimary">Approval Checkpoints</h2>
                            <div class="mt-4 space-y-2">
                                @foreach ($checkpoints as $assetKey => $checkpoint)
                                    <div class="rounded-md border border-border bg-background p-3">
                                        <div class="flex items-center justify-between gap-2">
                                            <p class="text-sm font-medium text-textPrimary">{{ str_replace('_', ' ', $assetKey) }}</p>
                                            <span class="rounded-full bg-amber-100 px-2 py-0.5 text-[11px] text-amber-800">{{ str_replace('_', ' ', $checkpoint['status'] ?? 'requested') }}</span>
                                        </div>
                                        <p class="mt-1 text-xs text-textSecondary">{{ $checkpoint['review_focus'] ?? 'Review required before execution.' }}</p>
                                    </div>
                                @endforeach
                            </div>
                        </div>

                        <div class="rounded-lg border border-border bg-surface p-4">
                            <h2 class="text-sm font-semibold text-textPrimary">Tone Variations</h2>
                            <div class="mt-4 space-y-2">
                                @foreach ($toneVariations as $tone => $config)
                                    @continue($tone === 'audience_context')
                                    <div class="rounded-md border border-border bg-background p-3">
                                        <p class="text-sm font-medium text-textPrimary">{{ str_replace('_', ' ', $tone) }}</p>
                                        <p class="mt-1 text-xs text-textSecondary">{{ $config['note'] ?? '' }}</p>
                                    </div>
                                @endforeach
                            </div>
                        </div>

                        <div class="rounded-lg border border-border bg-surface p-4">
                            <h2 class="text-sm font-semibold text-textPrimary">Internal Linking Plan</h2>
                            <p class="mt-2 text-sm text-textSecondary">{{ data_get($selectedCampaign->internal_linking_strategy, 'strategy', 'No internal linking strategy stored yet.') }}</p>
                        </div>
                    </section>
                </div>
            @else
                <section class="rounded-lg border border-dashed border-border bg-surface p-10 text-center">
                    <h2 class="text-lg font-semibold text-textPrimary">No campaign selected</h2>
                    <p class="mx-auto mt-2 max-w-xl text-sm text-textSecondary">Generate a deterministic campaign plan from a topic and goals to review content sequencing, dependencies, tone variants, approval checkpoints, and distribution drafts.</p>
                </section>
            @endif
        </main>
    </div>

    <script>
        document.querySelectorAll('[data-map-card]').forEach((card) => {
            card.addEventListener('dragstart', (event) => {
                event.dataTransfer.setData('text/plain', card.dataset.mapCard);
                card.classList.add('opacity-60');
            });
            card.addEventListener('dragend', () => card.classList.remove('opacity-60'));
        });

        document.querySelectorAll('[data-dropzone]').forEach((zone) => {
            zone.addEventListener('dragover', (event) => event.preventDefault());
            zone.addEventListener('drop', (event) => {
                event.preventDefault();
                const key = event.dataTransfer.getData('text/plain');
                const card = document.querySelector(`[data-map-card="${key}"]`);
                if (card) zone.appendChild(card);
            });
        });
    </script>
@endsection
