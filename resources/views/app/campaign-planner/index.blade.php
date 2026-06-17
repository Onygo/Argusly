@extends('layouts.app', ['title' => 'Campaign Planner', 'pageWidth' => 'wide'])

@section('content')
    <div class="mb-6 flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight text-textPrimary">Campaign Planner</h1>
            <p class="mt-1 text-sm text-textSecondary">Generate structured, approval-gated campaign plans from opportunities, goals, funnel stages, and distribution needs.</p>
        </div>
        @if ($selectedCampaign)
            <div class="flex flex-wrap items-center gap-2">
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
            </div>
        @endif
    </div>

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
                        <div class="grid grid-cols-2 gap-2 text-xs text-textSecondary">
                            <div class="rounded-md border border-border bg-background px-3 py-2">Start <span class="block font-medium text-textPrimary">{{ optional($selectedCampaign->planned_start_date)->toFormattedDateString() ?? 'Draft' }}</span></div>
                            <div class="rounded-md border border-border bg-background px-3 py-2">End <span class="block font-medium text-textPrimary">{{ optional($selectedCampaign->planned_end_date)->toFormattedDateString() ?? 'Draft' }}</span></div>
                        </div>
                    </div>
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
                                        @endphp
                                        <article draggable="true" class="cursor-grab rounded-md border border-border bg-surface p-3 shadow-sm" data-map-card="{{ $assetKey }}">
                                            <div class="flex items-start justify-between gap-2">
                                                <p class="text-sm font-medium text-textPrimary">{{ $node['label'] ?? $assetKey }}</p>
                                                <i data-lucide="grip-vertical" class="h-4 w-4 text-textFaint"></i>
                                            </div>
                                            <div class="mt-2 flex flex-wrap gap-1.5">
                                                <span class="rounded-full bg-surfaceSubtle px-2 py-0.5 text-[11px] text-textSecondary">{{ str_replace('_', ' ', (string) ($node['type'] ?? 'asset')) }}</span>
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
                        </div>
                        <div class="divide-y divide-border">
                            @foreach ($selectedCampaign->contents->sortBy('sequence_order') as $asset)
                                @php
                                    $brief = (array) $asset->brief;
                                    $meta = (array) $asset->metadata;
                                    $assetKey = (string) ($meta['planner_key'] ?? $asset->id);
                                @endphp
                                <article class="p-4">
                                    <div class="flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
                                        <div>
                                            <div class="flex flex-wrap gap-2">
                                                <span class="rounded-full border border-border px-2.5 py-1 text-xs text-textSecondary">#{{ $asset->sequence_order }}</span>
                                                <span class="rounded-full border border-border px-2.5 py-1 text-xs text-textSecondary">{{ str_replace('_', ' ', $asset->asset_type?->value ?? $asset->asset_type) }}</span>
                                                <span class="rounded-full border border-border px-2.5 py-1 text-xs text-textSecondary">{{ data_get($brief, 'funnel_stage', 'stage') }}</span>
                                                @if (data_get($meta, 'generated_answer_block_ids'))
                                                    <span class="rounded-full bg-emerald-100 px-2.5 py-1 text-xs text-emerald-800">answer blocks added</span>
                                                @elseif ($asset->content_id || data_get($meta, 'generated_social_variant'))
                                                    <span class="rounded-full bg-emerald-100 px-2.5 py-1 text-xs text-emerald-800">draft queued</span>
                                                @endif
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
                                            <p class="text-xs font-medium uppercase tracking-wide text-textFaint">Audience</p>
                                            <p class="mt-1 text-sm text-textPrimary">{{ data_get($brief, 'audience_segment') }}</p>
                                        </div>
                                        <div class="rounded-md border border-border bg-background p-3">
                                            <p class="text-xs font-medium uppercase tracking-wide text-textFaint">Tone</p>
                                            <p class="mt-1 text-sm text-textPrimary">{{ data_get($asset->ai_generation_context, 'tone_variation') }}</p>
                                        </div>
                                        <div class="rounded-md border border-border bg-background p-3">
                                            <p class="text-xs font-medium uppercase tracking-wide text-textFaint">Distribution</p>
                                            <p class="mt-1 text-sm text-textPrimary">{{ $asset->distributionPlans->pluck('distributionChannel.name')->filter()->implode(', ') ?: 'Draft' }}</p>
                                        </div>
                                    </div>
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
                            @endforeach
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
