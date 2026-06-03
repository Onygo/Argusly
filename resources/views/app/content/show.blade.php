<x-app.layout :title="$asset->title.' | Argusly'">
    <div class="w-full">
        <div class="flex flex-col justify-between gap-4 lg:flex-row lg:items-start">
            <div>
                <p class="eyebrow">Argusly Content Engine</p>
                <h1 class="mt-2 max-w-3xl text-3xl font-semibold tracking-tight text-ink sm:text-4xl">{{ $asset->title }}</h1>
                <div class="mt-4 flex flex-wrap items-center gap-2">
                    <x-ui.badge variant="blue">{{ str($asset->type)->replace('_', ' ')->headline() }}</x-ui.badge>
                    <x-ui.badge :variant="$asset->status === 'published' ? 'success' : ($asset->status === 'failed' ? 'dark' : 'default')">
                        {{ str($asset->status)->headline() }}
                    </x-ui.badge>
                    <span class="text-sm text-muted">{{ strtoupper($asset->language) }} · {{ $asset->locale }} · {{ $asset->source }}</span>
                </div>
            </div>
            <div class="flex flex-wrap gap-2">
                <x-ui.button href="{{ route('app.content.index') }}" variant="secondary">Back</x-ui.button>
                @can('update', $asset)
                    <x-ui.button href="{{ route('app.content.edit', $asset) }}" variant="secondary">Edit</x-ui.button>
                    <form method="POST" action="{{ route('app.content.generate', $asset) }}">
                        @csrf
                        <input type="hidden" name="type" value="refresh">
                        <x-ui.button type="submit" variant="secondary">Generate · {{ config('credits.costs.content_generation') }}</x-ui.button>
                    </form>
                    <form method="POST" action="{{ route('app.content.audit', $asset) }}">
                        @csrf
                        <x-ui.button type="submit" variant="secondary">Run audit · {{ config('credits.costs.content_audit') }}</x-ui.button>
                    </form>
                    <form method="POST" action="{{ route('app.content.lifecycle', $asset) }}">
                        @csrf
                        <x-ui.button type="submit" variant="secondary">Lifecycle · {{ config('credits.costs.content_lifecycle') }}</x-ui.button>
                    </form>
                @endcan
                @can('approve', $asset)
                    @if ($asset->status !== 'approved' && $asset->status !== 'published')
                        <form method="POST" action="{{ route('app.content.approve', $asset) }}">
                            @csrf
                            <x-ui.button type="submit" variant="secondary">Approve</x-ui.button>
                        </form>
                    @endif
                @endcan
                @can('publish', $asset)
                    @if ($asset->status !== 'published')
                        <form method="POST" action="{{ route('app.content.publish', $asset) }}">
                            @csrf
                            <x-ui.button type="submit">Publish · {{ config('credits.costs.publishing_action') }}</x-ui.button>
                        </form>
                    @endif
                @endcan
                @can('create', \App\Models\SocialPost::class)
                    <x-ui.button href="{{ route('app.content.social-posts.repurpose', $asset) }}" variant="secondary">Create social post</x-ui.button>
                @endcan
            </div>
        </div>

        @if (session('status'))
            <div class="mt-6 rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-700">
                {{ session('status') }}
            </div>
        @endif

        @if ($errors->has('credits'))
            <div class="mt-6 rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm font-medium text-red-700">
                {{ $errors->first('credits') }}
            </div>
        @endif

        <div class="mt-8 grid gap-6 lg:grid-cols-[1.2fr_0.8fr]">
            <x-ui.card class="p-6">
                @if ($asset->excerpt)
                    <p class="text-base font-medium leading-7 text-ink">{{ $asset->excerpt }}</p>
                @endif

                <div class="mt-6 whitespace-pre-line text-sm leading-7 text-muted">
                    {{ $asset->body ?: 'No body content has been added yet.' }}
                </div>
            </x-ui.card>

            <div class="space-y-5">
                <x-ui.card class="p-5">
                    <h2 class="text-sm font-semibold text-ink">Asset details</h2>
                    <dl class="mt-4 space-y-3 text-sm">
                        <div class="flex justify-between gap-4">
                            <dt class="text-muted">Brand</dt>
                            <dd class="font-medium text-ink">{{ $asset->brand->name }}</dd>
                        </div>
                        <div class="flex justify-between gap-4">
                            <dt class="text-muted">Slug</dt>
                            <dd class="truncate font-medium text-ink">{{ $asset->slug }}</dd>
                        </div>
                        <div class="flex justify-between gap-4">
                            <dt class="text-muted">Property</dt>
                            <dd class="font-medium text-ink">{{ $asset->property?->name ?? 'Not assigned' }}</dd>
                        </div>
                        <div class="flex justify-between gap-4">
                            <dt class="text-muted">Channel</dt>
                            <dd class="font-medium text-ink">{{ $asset->publishingChannel?->name ?? 'Not assigned' }}</dd>
                        </div>
                        <div class="flex justify-between gap-4">
                            <dt class="text-muted">Content language</dt>
                            <dd class="font-medium text-ink">{{ strtoupper($asset->language) }} · {{ $asset->locale }}</dd>
                        </div>
                        <div class="flex justify-between gap-4">
                            <dt class="text-muted">Published</dt>
                            <dd class="font-medium text-ink">{{ $asset->published_at?->toFormattedDateString() ?? 'Not published' }}</dd>
                        </div>
                        <div class="flex justify-between gap-4">
                            <dt class="text-muted">Created by</dt>
                            <dd class="font-medium text-ink">{{ $asset->creator?->name ?? 'System' }}</dd>
                        </div>
                    </dl>
                </x-ui.card>

                <x-ui.card class="p-5">
                    <h2 class="text-sm font-semibold text-ink">URLs</h2>
                    <div class="mt-4 space-y-3 text-sm text-muted">
                        <p class="break-words">{{ $asset->source_url ?: 'No source URL' }}</p>
                        <p class="break-words">{{ $asset->canonical_url ?: 'No canonical URL' }}</p>
                    </div>
                </x-ui.card>
            </div>
        </div>

        @php
            $activeTranslations = $asset->sourceTranslations->whereNotIn('status', ['failed', 'archived']);
            $translatedLanguages = $activeTranslations->pluck('target_language')->all();
            $missingTranslationTargets = $translationTargets->reject(fn ($language) => in_array($language->code, $translatedLanguages, true))->values();
        @endphp

        <x-ui.card class="mt-6 p-5">
            <div class="flex flex-col justify-between gap-4 sm:flex-row sm:items-start">
                <div>
                    <h2 class="text-sm font-semibold text-ink">Translations</h2>
                    <p class="mt-1 text-sm text-muted">Linked draft assets for alternate content languages.</p>
                </div>
                @can('update', $asset)
                    @if ($missingTranslationTargets->isNotEmpty())
                        <form method="POST" action="{{ route('app.content.translations.store', $asset) }}" class="flex flex-wrap items-end gap-2">
                            @csrf
                            <label>
                                <span class="sr-only">Target languages</span>
                                <select name="target_languages[]" multiple size="{{ min(4, max(2, $missingTranslationTargets->count())) }}" class="rounded-md border border-line bg-white px-3 py-2 text-sm text-ink">
                                    @foreach ($missingTranslationTargets as $language)
                                        <option value="{{ $language->code }}">{{ $language->name }} · {{ $language->native_name }}</option>
                                    @endforeach
                                </select>
                            </label>
                            <x-ui.button type="submit" size="md">Create translations · {{ config('credits.costs.content_translation') }} each</x-ui.button>
                        </form>
                    @endif
                @endcan
            </div>

            @if ($errors->has('translations'))
                <div class="mt-4 rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm font-medium text-red-700">
                    {{ $errors->first('translations') }}
                </div>
            @endif

            <div class="mt-5 grid gap-4 lg:grid-cols-2">
                <div>
                    <h3 class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">Linked translated assets</h3>
                    <div class="mt-3 space-y-3">
                        @forelse ($activeTranslations as $translation)
                            <div class="rounded-md border border-line bg-panel p-4">
                                <div class="flex flex-col justify-between gap-3 sm:flex-row sm:items-start">
                                    <div>
                                        <div class="flex flex-wrap items-center gap-2">
                                            <x-ui.badge>{{ strtoupper($translation->target_language) }}</x-ui.badge>
                                            <x-ui.badge :variant="$translation->status === 'completed' ? 'success' : 'default'">{{ str($translation->status)->headline() }}</x-ui.badge>
                                        </div>
                                        @if ($translation->translatedContentAsset)
                                            <a href="{{ route('app.content.show', $translation->translatedContentAsset) }}" class="mt-2 block text-sm font-semibold text-ink hover:underline">{{ $translation->translatedContentAsset->title }}</a>
                                        @else
                                            <p class="mt-2 text-sm text-muted">Draft asset link missing.</p>
                                        @endif
                                    </div>
                                    <p class="text-xs text-muted">{{ $translation->created_at?->diffForHumans() }}</p>
                                </div>
                            </div>
                        @empty
                            <x-dashboard.empty-state title="No translations yet" message="Create translation drafts for alternate content languages." />
                        @endforelse
                    </div>
                </div>

                <div>
                    <h3 class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">Missing translations</h3>
                    <div class="mt-3 flex flex-wrap gap-2">
                        @forelse ($missingTranslationTargets as $language)
                            <x-ui.badge>{{ $language->name }}</x-ui.badge>
                        @empty
                            <x-ui.badge variant="success">All enabled targets covered</x-ui.badge>
                        @endforelse
                    </div>

                    @if ($asset->translatedFrom->isNotEmpty())
                        <div class="mt-5 rounded-md border border-line bg-panel p-4">
                            <h3 class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">Translated from</h3>
                            @foreach ($asset->translatedFrom as $translation)
                                <a href="{{ route('app.content.show', $translation->sourceContentAsset) }}" class="mt-2 block text-sm font-semibold text-ink hover:underline">{{ $translation->sourceContentAsset?->title }}</a>
                            @endforeach
                        </div>
                    @endif
                </div>
            </div>

            <div class="mt-5 space-y-3">
                <h3 class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">Translation history</h3>
                @forelse ($asset->sourceTranslations as $translation)
                    <div class="flex flex-col justify-between gap-3 rounded-md border border-line bg-panel p-4 sm:flex-row sm:items-center">
                        <div class="flex flex-wrap items-center gap-2">
                            <x-ui.badge>{{ strtoupper($translation->source_language) }} to {{ strtoupper($translation->target_language) }}</x-ui.badge>
                            <x-ui.badge :variant="$translation->status === 'completed' ? 'success' : ($translation->status === 'failed' ? 'dark' : 'default')">{{ str($translation->status)->headline() }}</x-ui.badge>
                            <span class="text-xs text-muted">{{ $translation->provider ?? 'No provider' }}</span>
                        </div>
                        <span class="text-xs text-muted">{{ $translation->created_at?->diffForHumans() }}</span>
                    </div>
                @empty
                    <p class="text-sm text-muted">No translation activity has been recorded for this asset.</p>
                @endforelse
            </div>
        </x-ui.card>

        @php
            $latestGa4Snapshot = $asset->ga4MetricSnapshots->first();
            $latestSearchSnapshot = $asset->searchConsoleQuerySnapshots->first();
        @endphp

        <x-ui.card class="mt-6 p-5">
            <div class="flex flex-col justify-between gap-4 sm:flex-row sm:items-start">
                <div>
                    <h2 class="text-sm font-semibold text-ink">GA4 analytics</h2>
                    <p class="mt-1 text-sm text-muted">Latest synced Google Analytics performance for matched content URLs.</p>
                </div>
                <x-ui.button href="{{ route('app.analytics') }}" size="md" variant="secondary">Analytics dashboard</x-ui.button>
            </div>

            @if ($latestGa4Snapshot)
                <div class="mt-5 grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
                    <x-settings.field label="Sessions" :value="number_format((int) $latestGa4Snapshot->sessions)" />
                    <x-settings.field label="Users" :value="number_format((int) $latestGa4Snapshot->users)" />
                    <x-settings.field label="Pageviews" :value="number_format((int) $latestGa4Snapshot->pageviews)" />
                    <x-settings.field label="Engagement" :value="$latestGa4Snapshot->engagement_rate !== null ? $latestGa4Snapshot->engagement_rate.'%' : null" empty="n/a" />
                    <x-settings.field label="Conversions" :value="number_format((int) $latestGa4Snapshot->conversions)" />
                </div>
                <p class="mt-4 text-sm text-muted">{{ $latestGa4Snapshot->ga4Property?->display_name ?? 'GA4 property' }} · {{ $latestGa4Snapshot->date?->toFormattedDateString() }} · {{ $latestGa4Snapshot->page_path ?? 'No page path' }}</p>
            @else
                <div class="mt-5">
                    <x-dashboard.empty-state title="No GA4 metrics yet" message="Connect Google Analytics and sync GA4 snapshots to show content performance here." />
                </div>
            @endif
        </x-ui.card>

        <x-ui.card class="mt-6 p-5">
            <div class="flex flex-col justify-between gap-4 sm:flex-row sm:items-start">
                <div>
                    <h2 class="text-sm font-semibold text-ink">SEO performance</h2>
                    <p class="mt-1 text-sm text-muted">Latest synced Search Console performance for matched content URLs.</p>
                </div>
                <x-ui.button href="{{ route('app.search-performance') }}" size="md" variant="secondary">Search performance</x-ui.button>
            </div>

            @if ($latestSearchSnapshot)
                <div class="mt-5 grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
                    <x-settings.field label="Clicks" :value="number_format((int) $latestSearchSnapshot->clicks)" />
                    <x-settings.field label="Impressions" :value="number_format((int) $latestSearchSnapshot->impressions)" />
                    <x-settings.field label="CTR" :value="$latestSearchSnapshot->ctr !== null ? number_format(((float) $latestSearchSnapshot->ctr) * 100, 2).'%' : null" empty="n/a" />
                    <x-settings.field label="Position" :value="$latestSearchSnapshot->position" empty="n/a" />
                    <x-settings.field label="Query" :value="$latestSearchSnapshot->query" empty="No query" />
                </div>
                <p class="mt-4 truncate text-sm text-muted">{{ $latestSearchSnapshot->searchConsoleSite?->site_url ?? 'Search Console site' }} · {{ $latestSearchSnapshot->date?->toFormattedDateString() }} · {{ $latestSearchSnapshot->page ?? 'No page dimension' }}</p>
            @else
                <div class="mt-5">
                    <x-dashboard.empty-state title="No SEO metrics yet" message="Connect Search Console and sync query snapshots to show organic search performance here." />
                </div>
            @endif
        </x-ui.card>

        <x-ui.card class="mt-6 p-5">
            <div class="flex flex-col justify-between gap-4 sm:flex-row sm:items-start">
                <div>
                    <h2 class="text-sm font-semibold text-ink">Publishing history</h2>
                    <p class="mt-1 text-sm text-muted">Fake provider actions for publish, update, unpublish and schedule workflows.</p>
                </div>
                @can('publish', $asset)
                    <form method="POST" action="{{ route('app.content.publishing-actions.store', $asset) }}" class="flex flex-wrap items-end gap-2">
                        @csrf
                        <label>
                            <span class="sr-only">Publishing action</span>
                            <select name="action" class="h-10 rounded-md border border-line bg-white px-3 text-sm text-ink">
                                @foreach (\App\Models\PublishingAction::ACTIONS as $action)
                                    <option value="{{ $action }}" @selected($action === 'publish')>{{ str($action)->headline() }}</option>
                                @endforeach
                            </select>
                        </label>
                        <x-ui.button type="submit" size="md">Queue action · {{ config('credits.costs.publishing_action') }} credits</x-ui.button>
                    </form>
                @endcan
            </div>

            <div class="mt-5 space-y-3">
                @forelse ($asset->publishingActions as $publishingAction)
                    <div class="rounded-md border border-line bg-panel p-4">
                        <div class="flex flex-col justify-between gap-3 sm:flex-row sm:items-start">
                            <div>
                                <div class="flex flex-wrap items-center gap-2">
                                    <h3 class="text-sm font-semibold text-ink">{{ str($publishingAction->action)->headline() }}</h3>
                                    <x-ui.badge :variant="$publishingAction->status === 'completed' ? 'success' : ($publishingAction->status === 'failed' ? 'dark' : 'default')">
                                        {{ str($publishingAction->status)->headline() }}
                                    </x-ui.badge>
                                    <span class="text-xs text-muted">{{ $publishingAction->publishingChannel?->name ?? 'No channel' }}</span>
                                </div>
                                @if ($publishingAction->external_url)
                                    <p class="mt-2 break-words text-sm text-muted">{{ $publishingAction->external_url }}</p>
                                @elseif ($publishingAction->error_message)
                                    <p class="mt-2 text-sm text-muted">{{ $publishingAction->error_message }}</p>
                                @else
                                    <p class="mt-2 text-sm text-muted">Queued for fake provider handling.</p>
                                @endif
                            </div>
                            <p class="text-xs text-muted">{{ $publishingAction->created_at?->diffForHumans() }}</p>
                        </div>
                    </div>
                @empty
                    <x-dashboard.empty-state title="No publishing actions yet" message="Queue a fake publishing action to exercise the publishing foundation." />
                @endforelse
            </div>
        </x-ui.card>

        @php
            $latestLifecycle = $asset->lifecycleScores->first();
        @endphp

        <x-ui.card class="mt-6 p-5">
            <div class="flex flex-col justify-between gap-4 sm:flex-row sm:items-start">
                <div>
                    <h2 class="text-sm font-semibold text-ink">Content lifecycle</h2>
                    <p class="mt-1 text-sm text-muted">Lifecycle health based on freshness, content length and the latest audit score.</p>
                </div>
                @can('update', $asset)
                    <form method="POST" action="{{ route('app.content.lifecycle', $asset) }}">
                        @csrf
                        <x-ui.button type="submit" size="md">Calculate lifecycle · {{ config('credits.costs.content_lifecycle') }} credits</x-ui.button>
                    </form>
                @endcan
            </div>

            @if ($latestLifecycle)
                <div class="mt-5 grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
                    <x-settings.field label="Health" :value="$latestLifecycle->health_score.'/100'" />
                    <x-settings.field label="Freshness" :value="$latestLifecycle->freshness_score.'/100'" />
                    <x-settings.field label="Performance" :value="$latestLifecycle->performance_score.'/100'" />
                    <x-settings.field label="Visibility" :value="$latestLifecycle->visibility_score.'/100'" />
                    <x-settings.field label="Priority" :value="$latestLifecycle->refresh_priority.'/100'" />
                </div>

                <div class="mt-5 rounded-md border border-line bg-panel p-4">
                    <div class="flex flex-wrap items-center gap-2">
                        <x-ui.badge :variant="in_array($latestLifecycle->status, ['healthy'], true) ? 'success' : (in_array($latestLifecycle->status, ['critical', 'needs_refresh'], true) ? 'dark' : 'default')">
                            {{ str($latestLifecycle->status)->replace('_', ' ')->headline() }}
                        </x-ui.badge>
                        <span class="text-xs text-muted">{{ $latestLifecycle->scored_at?->diffForHumans() }}</span>
                    </div>
                    <p class="mt-3 text-sm leading-6 text-muted">{{ $latestLifecycle->reason }}</p>
                </div>

                @if (! empty($latestLifecycle->signals['recommendations'] ?? []))
                    <div class="mt-5 rounded-md border border-line bg-panel p-4">
                        <h3 class="text-sm font-semibold text-ink">Lifecycle recommendations</h3>
                        <div class="mt-3 flex flex-wrap gap-2">
                            @foreach ($latestLifecycle->signals['recommendations'] as $recommendation)
                                <x-ui.badge variant="default">{{ str($recommendation)->headline() }}</x-ui.badge>
                            @endforeach
                        </div>
                    </div>
                @endif

                <div class="mt-5 space-y-3">
                    <h3 class="text-sm font-semibold text-ink">Lifecycle history</h3>
                    @foreach ($asset->lifecycleScores as $score)
                        <div class="flex flex-col justify-between gap-3 rounded-md border border-line bg-panel p-4 sm:flex-row sm:items-center">
                            <div class="flex flex-wrap items-center gap-2">
                                <x-ui.badge :variant="in_array($score->status, ['healthy'], true) ? 'success' : (in_array($score->status, ['critical', 'needs_refresh'], true) ? 'dark' : 'default')">
                                    {{ str($score->status)->replace('_', ' ')->headline() }}
                                </x-ui.badge>
                                <span class="text-sm font-semibold text-ink">{{ $score->health_score }}/100</span>
                                <span class="text-xs text-muted">Priority {{ $score->refresh_priority }}/100</span>
                            </div>
                            <span class="text-xs text-muted">{{ $score->scored_at?->diffForHumans() }}</span>
                        </div>
                    @endforeach
                </div>
            @else
                <div class="mt-5">
                    <x-dashboard.empty-state title="No lifecycle score yet" message="Calculate lifecycle to score freshness, performance, visibility and refresh priority." />
                </div>
            @endif
        </x-ui.card>

        <x-ui.card class="mt-6 p-5">
            <div class="flex flex-col justify-between gap-4 sm:flex-row sm:items-start">
                <div>
                    <h2 class="text-sm font-semibold text-ink">Related Answer Blocks</h2>
                    <p class="mt-1 text-sm text-muted">Manual answer-ready blocks attached to this content asset.</p>
                </div>
                @can('create', \App\Models\AnswerBlock::class)
                    <x-ui.button href="{{ route('app.content.answer-blocks.create-for-asset', $asset) }}" size="md">Create block</x-ui.button>
                @endcan
            </div>

            <div class="mt-5 space-y-3">
                @forelse ($asset->answerBlocks as $answerBlock)
                    <a href="{{ route('app.content.answer-blocks.show', $answerBlock) }}" class="block rounded-md border border-line bg-panel p-4 transition hover:bg-white">
                        <div class="flex flex-col justify-between gap-3 sm:flex-row sm:items-start">
                            <div>
                                <div class="flex flex-wrap items-center gap-2">
                                    <h3 class="text-sm font-semibold text-ink">{{ $answerBlock->question }}</h3>
                                    <x-ui.badge>{{ str($answerBlock->type)->replace('_', ' ')->headline() }}</x-ui.badge>
                                </div>
                                <p class="mt-2 text-sm leading-6 text-muted">{{ str($answerBlock->answer)->limit(220) }}</p>
                            </div>
                            <x-ui.badge :variant="$answerBlock->status === 'published' || $answerBlock->status === 'approved' ? 'success' : 'default'">{{ str($answerBlock->status)->headline() }}</x-ui.badge>
                        </div>
                    </a>
                @empty
                    <x-dashboard.empty-state title="No answer blocks yet" message="Create a manual Answer Block to prepare this asset for future answer surfaces." />
                @endforelse
            </div>
        </x-ui.card>

        @php
            $latestAudit = $asset->audits->first();
        @endphp

        <x-ui.card class="mt-6 p-5">
            <div class="flex flex-col justify-between gap-4 sm:flex-row sm:items-start">
                <div>
                    <h2 class="text-sm font-semibold text-ink">Content intelligence audit</h2>
                    <p class="mt-1 text-sm text-muted">Deterministic placeholder scoring for structure, SEO, AI visibility and answer readiness.</p>
                </div>
                @can('update', $asset)
                    <form method="POST" action="{{ route('app.content.audit', $asset) }}">
                        @csrf
                        <x-ui.button type="submit" size="md">Run audit · {{ config('credits.costs.content_audit') }} credits</x-ui.button>
                    </form>
                @endcan
            </div>

            @if ($latestAudit)
                <div class="mt-5 grid gap-3 sm:grid-cols-2 xl:grid-cols-6">
                    <x-settings.field label="Overall" :value="$latestAudit->score !== null ? $latestAudit->score.'/100' : str($latestAudit->status)->headline()" />
                    <x-settings.field label="SEO" :value="$latestAudit->seo_score !== null ? $latestAudit->seo_score.'/100' : null" empty="Pending" />
                    <x-settings.field label="AI visibility" :value="$latestAudit->ai_visibility_score !== null ? $latestAudit->ai_visibility_score.'/100' : null" empty="Pending" />
                    <x-settings.field label="Readability" :value="$latestAudit->readability_score !== null ? $latestAudit->readability_score.'/100' : null" empty="Pending" />
                    <x-settings.field label="Entities" :value="$latestAudit->entity_score !== null ? $latestAudit->entity_score.'/100' : null" empty="Pending" />
                    <x-settings.field label="Answers" :value="$latestAudit->answer_score !== null ? $latestAudit->answer_score.'/100' : null" empty="Pending" />
                </div>

                <div class="mt-5 grid gap-4 lg:grid-cols-2">
                    <div class="rounded-md border border-line bg-panel p-4">
                        <h3 class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">Issues</h3>
                        <ul class="mt-3 space-y-2 text-sm leading-6 text-muted">
                            @forelse ($latestAudit->issues ?? [] as $issue)
                                <li>{{ $issue }}</li>
                            @empty
                                <li>No issues found by the placeholder audit.</li>
                            @endforelse
                        </ul>
                    </div>
                    <div class="rounded-md border border-line bg-panel p-4">
                        <h3 class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">Recommendations</h3>
                        <ul class="mt-3 space-y-2 text-sm leading-6 text-muted">
                            @forelse ($latestAudit->recommendations ?? [] as $recommendation)
                                <li>{{ $recommendation }}</li>
                            @empty
                                <li>No recommendations yet.</li>
                            @endforelse
                        </ul>
                    </div>
                </div>

                <p class="mt-4 text-sm text-muted">{{ $latestAudit->summary }}</p>
                <x-evidence.list :items="$latestAudit->evidenceItems" class="mt-5" />
            @else
                <div class="mt-5">
                    <x-dashboard.empty-state title="No audit yet" message="Run an audit to create deterministic placeholder scores for this asset." />
                </div>
            @endif

            <div class="mt-5 space-y-3">
                <h3 class="text-sm font-semibold text-ink">Audit history</h3>
                @forelse ($asset->audits as $audit)
                    <div class="flex flex-col justify-between gap-3 rounded-md border border-line bg-panel p-4 sm:flex-row sm:items-center">
                        <div>
                            <div class="flex flex-wrap items-center gap-2">
                                <x-ui.badge :variant="$audit->status === 'completed' ? 'success' : ($audit->status === 'failed' ? 'dark' : 'default')">
                                    {{ str($audit->status)->headline() }}
                                </x-ui.badge>
                                <p class="text-sm font-semibold text-ink">{{ $audit->score !== null ? $audit->score.'/100' : 'Pending score' }}</p>
                            </div>
                            <p class="mt-1 text-xs text-muted">{{ $audit->summary ?: 'Audit has not completed yet.' }}</p>
                        </div>
                        <p class="text-xs text-muted">{{ $audit->audited_at?->diffForHumans() ?? $audit->created_at?->diffForHumans() }}</p>
                    </div>
                @empty
                    <p class="text-sm text-muted">No audit runs have been queued for this asset.</p>
                @endforelse
            </div>
        </x-ui.card>

        <x-ui.card class="mt-6 p-5">
            <div class="flex flex-col justify-between gap-4 sm:flex-row sm:items-start">
                <div>
                    <h2 class="text-sm font-semibold text-ink">Generation history</h2>
                    <p class="mt-1 text-sm text-muted">Static foundation runs for this content asset. Real AI providers are not connected yet.</p>
                </div>
                @can('update', $asset)
                    <form method="POST" action="{{ route('app.content.generate', $asset) }}" class="flex flex-wrap items-end gap-2">
                        @csrf
                        <label>
                            <span class="sr-only">Generation type</span>
                            <select name="type" class="h-10 rounded-md border border-line bg-white px-3 text-sm text-ink">
                                @foreach ($generationTypes as $type)
                                    <option value="{{ $type }}" @selected($type === 'refresh')>{{ str($type)->replace('_', ' ')->headline() }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label>
                            <span class="sr-only">Generation language</span>
                            <select name="language" class="h-10 rounded-md border border-line bg-white px-3 text-sm text-ink">
                                @foreach ($contentLanguages as $language)
                                    <option value="{{ $language->code }}" @selected($language->code === $asset->language)>{{ $language->name }}</option>
                                @endforeach
                            </select>
                        </label>
                        <x-ui.button type="submit" size="md">Queue run · {{ config('credits.costs.content_generation') }} credits</x-ui.button>
                    </form>
                @endcan
            </div>

            <div class="mt-5 space-y-3">
                @forelse ($asset->generatedAssets as $run)
                    <div class="rounded-md border border-line bg-panel p-4">
                        <div class="flex flex-col justify-between gap-3 sm:flex-row sm:items-start">
                            <div>
                                <div class="flex flex-wrap items-center gap-2">
                                    <h3 class="text-sm font-semibold text-ink">{{ $run->title ?: str($run->type)->replace('_', ' ')->headline().' run' }}</h3>
                                    <x-ui.badge :variant="$run->status === 'completed' || $run->status === 'approved' ? 'success' : ($run->status === 'failed' || $run->status === 'rejected' ? 'dark' : 'default')">
                                        {{ str($run->status)->headline() }}
                                    </x-ui.badge>
                                </div>
                                <p class="mt-1 text-xs text-muted">{{ str($run->type)->replace('_', ' ')->headline() }} · {{ strtoupper($run->language ?? $asset->language) }}{{ $run->locale ? ' · '.$run->locale : '' }} · {{ $run->provider ?? 'No provider' }} · {{ $run->model ?? 'No model' }}</p>
                            </div>
                            <p class="text-xs text-muted">{{ $run->created_at?->diffForHumans() }}</p>
                        </div>
                        @if ($run->body)
                            <p class="mt-3 text-sm leading-6 text-muted">{{ str($run->body)->limit(220) }}</p>
                        @endif
                    </div>
                @empty
                    <x-dashboard.empty-state title="No generation runs yet" message="Queue a static generation run to exercise the foundation workflow." />
                @endforelse
            </div>
        </x-ui.card>
    </div>
</x-app.layout>
