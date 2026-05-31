<x-app.layout :title="__('dashboard.eyebrow').' | Argusly'">
    <div class="mx-auto max-w-7xl">
        <div class="flex flex-col justify-between gap-4 sm:flex-row sm:items-end">
            <div>
                <p class="eyebrow">{{ __('dashboard.eyebrow') }}</p>
                <h1 class="mt-2 text-3xl font-semibold tracking-tight text-ink sm:text-4xl">{{ __('dashboard.title') }}</h1>
                <p class="mt-2 max-w-2xl text-sm leading-6 text-muted">{{ __('dashboard.description') }}</p>
            </div>
            <x-ui.badge variant="{{ $account ? 'success' : 'default' }}">
                {{ $account ? __('dashboard.tenant_active') : __('dashboard.no_account_context') }}
            </x-ui.badge>
        </div>

        <div class="mt-8 grid gap-4 md:grid-cols-2 xl:grid-cols-4">
            <x-dashboard.info-card :label="__('dashboard.current_account')" :value="$account?->name" :empty="__('dashboard.no_account_selected')" />
            <x-dashboard.info-card :label="__('dashboard.current_brand')" :value="$brand?->name" :empty="__('dashboard.no_brand_selected')" />
            <x-dashboard.info-card :label="__('dashboard.account_role')" :value="$accountRole" :empty="__('dashboard.no_account_role')" />
            <x-dashboard.info-card :label="__('dashboard.brand_role')" :value="$brandRole" :empty="__('dashboard.no_brand_role')" />
        </div>

        <div class="mt-6 grid gap-6 xl:grid-cols-[1.2fr_0.8fr]">
            <x-dashboard.section :title="__('dashboard.active_modules')" :description="__('dashboard.active_modules_desc')">
                @if ($activeModules->isEmpty())
                    <x-dashboard.empty-state
                        title="No modules active"
                        message="This account does not have active subscription modules yet."
                    />
                @else
                    <div class="flex flex-wrap gap-2">
                        @foreach ($activeModules as $module)
                            <x-dashboard.module-pill :module="$module" />
                        @endforeach
                    </div>
                @endif
            </x-dashboard.section>

            <div class="grid gap-6 sm:grid-cols-2 xl:grid-cols-1">
                <x-dashboard.section :title="__('dashboard.connected_integrations')" :description="__('dashboard.connected_integrations_desc')">
                    @if ($connectedIntegrationsCount === 0)
                        <x-dashboard.empty-state
                            title="No integrations connected"
                            message="Connect an integration later to make it available here."
                        />
                    @else
                        <p class="text-4xl font-semibold tracking-tight text-ink">{{ $connectedIntegrationsCount }}</p>
                    @endif
                </x-dashboard.section>

                <x-dashboard.section :title="__('dashboard.available_credits')" :description="__('dashboard.available_credits_desc')">
                    <p class="text-4xl font-semibold tracking-tight text-ink">
                        @if ($availableCredits === null)
                            &mdash;
                        @else
                            {{ $availableCredits }}
                        @endif
                    </p>
                    <p class="mt-2 text-sm text-muted">{{ __('dashboard.current_account_balance') }}</p>
                </x-dashboard.section>
            </div>
        </div>

        <div class="mt-6 grid gap-6 xl:grid-cols-[0.8fr_1.2fr]">
            <x-dashboard.section :title="__('dashboard.current_brand')">
                @if (! $brand)
                    <x-dashboard.empty-state :title="__('dashboard.no_brand_selected')" message="Select or join a brand in this account before brand-specific intelligence appears." />
                @else
                    <div class="rounded-lg border border-line bg-panel p-5">
                        <p class="text-sm font-semibold text-ink">{{ $brand->name }}</p>
                        <p class="mt-1 text-sm text-muted">{{ $brand->domain ?: 'No brand domain configured' }}</p>
                    </div>
                @endif
            </x-dashboard.section>

            <x-dashboard.section :title="__('dashboard.recent_activity')" :description="__('dashboard.recent_activity_desc')">
                @if ($recentActivity->isEmpty())
                    <x-dashboard.empty-state
                        title="No activity yet"
                        message="There is no tenant activity to show for this account and brand context."
                    />
                @else
                    <div class="space-y-3">
                        @foreach ($recentActivity as $activity)
                            <div class="rounded-lg border border-line bg-panel p-4">
                                <div class="flex items-start justify-between gap-4">
                                    <div class="min-w-0">
                                        <p class="truncate text-sm font-semibold text-ink">{{ $activity->description }}</p>
                                        <p class="mt-1 text-xs text-muted">{{ $activity->user?->name ?? __('common.system') }} · {{ str($activity->event)->replace('.', ' ')->headline() }}</p>
                                    </div>
                                    <time class="shrink-0 text-xs text-muted" datetime="{{ $activity->created_at?->toIso8601String() }}">
                                        {{ $activity->created_at?->diffForHumans() }}
                                    </time>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </x-dashboard.section>
        </div>

        <div class="mt-6">
            <x-dashboard.section :title="__('dashboard.visibility_monitoring')" :description="__('dashboard.visibility_monitoring_desc')">
                <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <x-dashboard.info-card :label="__('dashboard.checks')" :value="$visibilityStats['checks']" />
                    <x-dashboard.info-card :label="__('dashboard.latest_score')" :value="$visibilityStats['latest_score']" empty="No data" />
                    <x-dashboard.info-card :label="__('dashboard.mentions_found')" :value="$visibilityStats['mentions_found']" />
                    <x-dashboard.info-card :label="__('dashboard.providers')" :value="$visibilityStats['providers']" />
                </div>
                <div class="mt-5">
                    <x-ui.button href="{{ route('app.visibility') }}" variant="secondary">{{ __('dashboard.open_visibility_timeline') }}</x-ui.button>
                </div>
            </x-dashboard.section>
        </div>

        <div class="mt-6">
            <x-dashboard.section :title="__('dashboard.top_topics')" description="First-class topic priorities for this account and current brand context.">
                @if ($topTopics->isEmpty())
                    <x-dashboard.empty-state
                        title="No topics yet"
                        message="Create topics to connect visibility, content, competitors, mentions, recommendations and agents."
                    />
                    <div class="mt-5">
                        <x-ui.button href="{{ route('app.topics.index') }}" variant="secondary">Open topics</x-ui.button>
                    </div>
                @else
                    <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                        @foreach ($topTopics as $topic)
                            <a href="{{ route('app.topics.show', $topic) }}" class="rounded-lg border border-line bg-panel p-4 transition hover:border-slate-300 hover:bg-white">
                                <div class="flex items-start justify-between gap-3">
                                    <div class="min-w-0">
                                        <p class="truncate text-sm font-semibold text-ink">{{ $topic->name }}</p>
                                        <p class="mt-1 line-clamp-2 text-xs leading-5 text-muted">{{ $topic->description ?: 'Ready for downstream intelligence links.' }}</p>
                                    </div>
                                    <x-ui.badge variant="{{ $topic->brand_id ? 'blue' : 'default' }}">{{ $topic->brand_id ? 'Brand' : 'Account' }}</x-ui.badge>
                                </div>
                            </a>
                        @endforeach
                    </div>
                    <div class="mt-5">
                        <x-ui.button href="{{ route('app.topics.index') }}" variant="secondary">Manage topics</x-ui.button>
                    </div>
                @endif
            </x-dashboard.section>
        </div>

        <div class="mt-6 grid gap-6 xl:grid-cols-[1.2fr_0.8fr]">
            <x-dashboard.section :title="__('dashboard.recent_mentions')" description="Latest captured mentions for the current account and brand context.">
                @if ($recentMentions->isEmpty())
                    <x-dashboard.empty-state
                        title="No mentions yet"
                        message="Mentions will appear after internal capture or future source ingestion creates records."
                    />
                @else
                    <div class="space-y-3">
                        @foreach ($recentMentions as $mention)
                            <a href="{{ route('app.mentions.show', $mention) }}" class="block rounded-lg border border-line bg-panel p-4 hover:bg-white">
                                <div class="flex items-start justify-between gap-4">
                                    <div class="min-w-0">
                                        <p class="truncate text-sm font-semibold text-ink">{{ $mention->title ?: str($mention->content)->limit(70) ?: 'Untitled mention' }}</p>
                                        <p class="mt-1 truncate text-xs text-muted">{{ $mention->source?->name ?? 'No source' }}{{ $mention->brand ? ' · '.$mention->brand->name : '' }}</p>
                                    </div>
                                    <x-ui.badge variant="{{ $mention->sentiment === 'positive' ? 'success' : ($mention->sentiment === 'mixed' ? 'blue' : 'default') }}">{{ $mention->sentiment ? str($mention->sentiment)->headline() : 'Unknown' }}</x-ui.badge>
                                </div>
                            </a>
                        @endforeach
                    </div>
                @endif
                <div class="mt-5">
                    <x-ui.button href="{{ route('app.mentions') }}" variant="secondary">Open mention feed</x-ui.button>
                </div>
            </x-dashboard.section>

            <x-dashboard.section :title="__('dashboard.sentiment_overview')" description="Mention sentiment distribution in this tenant context.">
                <div class="grid grid-cols-2 gap-3">
                    @foreach (['positive' => 'success', 'neutral' => 'default', 'negative' => 'default', 'mixed' => 'blue'] as $sentiment => $variant)
                        <div class="rounded-lg border border-line bg-panel p-4">
                            <div class="flex items-center justify-between gap-3">
                                <p class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">{{ str($sentiment)->headline() }}</p>
                                <x-ui.badge variant="{{ $variant }}">{{ $mentionSentimentOverview[$sentiment] }}</x-ui.badge>
                            </div>
                            <p class="mt-3 text-2xl font-semibold text-ink">{{ $mentionSentimentOverview['total'] ? round(($mentionSentimentOverview[$sentiment] / $mentionSentimentOverview['total']) * 100) : 0 }}%</p>
                        </div>
                    @endforeach
                </div>
            </x-dashboard.section>
        </div>

        <div class="mt-6">
            <x-dashboard.section :title="__('dashboard.intelligence_feed')" description="Central operating feed for platform, content, billing, publishing and integration events.">
                <div class="mb-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <x-dashboard.info-card label="Open signals" :value="$intelligenceStats['open']" />
                    <x-dashboard.info-card label="Critical" :value="$intelligenceStats['critical']" />
                    <x-dashboard.info-card label="High priority" :value="$intelligenceStats['high']" />
                    <x-dashboard.info-card label="Unreviewed" :value="$intelligenceStats['unreviewed']" />
                </div>
                @if ($intelligenceFeed->isEmpty())
                    <x-dashboard.empty-state
                        title="Intelligence feed placeholder"
                        message="Important content, lifecycle, billing, publishing and integration events will appear here."
                    />
                @else
                    <div class="grid gap-4 lg:grid-cols-2">
                        @foreach ($intelligenceFeed as $signal)
                            <x-intelligence.signal-card :signal="$signal" compact />
                        @endforeach
                    </div>
                    <div class="mt-5">
                        <x-ui.button href="{{ route('app.intelligence') }}" variant="secondary">View all signals</x-ui.button>
                    </div>
                @endif
            </x-dashboard.section>
        </div>

        <div class="mt-6">
            <x-dashboard.section :title="__('dashboard.recommendations')" description="Actionable next steps generated from intelligence signals.">
                <div class="mb-5 grid gap-3 sm:grid-cols-3">
                    <x-dashboard.info-card label="Open recommendations" :value="$recommendationStats['open']" />
                    <x-dashboard.info-card label="Accepted" :value="$recommendationStats['accepted']" />
                    <x-dashboard.info-card label="Completed" :value="$recommendationStats['completed']" />
                </div>
                @if ($recommendations->isEmpty())
                    <x-dashboard.empty-state
                        title="No recommendations"
                        message="Recommendations will appear as intelligence signals identify useful next actions."
                    />
                @else
                    <div class="grid gap-4 lg:grid-cols-2">
                        @foreach ($recommendations as $recommendation)
                            <x-recommendations.card :recommendation="$recommendation" compact />
                        @endforeach
                    </div>
                @endif
            </x-dashboard.section>
        </div>
    </div>
</x-app.layout>
