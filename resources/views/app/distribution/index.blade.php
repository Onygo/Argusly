<x-app.layout title="Content Distribution | Argusly">
    <div class="mx-auto max-w-7xl">
        <div class="flex flex-col justify-between gap-4 lg:flex-row lg:items-end">
            <div>
                <p class="eyebrow">Content Distribution Hub</p>
                <h1 class="mt-2 text-3xl font-semibold tracking-tight text-ink sm:text-4xl">Distribution</h1>
                <p class="mt-2 max-w-2xl text-sm leading-6 text-muted">See where each {{ $brand->name }} content asset is published, scheduled, assigned and waiting for action.</p>
            </div>
            <div class="flex flex-wrap gap-2">
                <x-ui.badge variant="blue">{{ $assets->total() }} assets</x-ui.badge>
                <x-ui.button href="{{ route('app.content.index') }}" variant="secondary">Content library</x-ui.button>
                <x-ui.button href="{{ route('app.social-posts.index') }}" variant="secondary">Social posts</x-ui.button>
            </div>
        </div>

        @if (session('status'))
            <div class="mt-6 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-700">
                {{ session('status') }}
            </div>
        @endif

        @if ($errors->any())
            <div class="mt-6 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
                <p class="font-semibold">Distribution action could not be completed</p>
                <ul class="mt-2 list-disc space-y-1 pl-5">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <x-ui.card class="mt-8 p-4">
            <form method="GET" action="{{ route('app.distribution') }}" class="grid gap-3 lg:grid-cols-[1fr_1fr_1fr_auto]">
                <label>
                    <span class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">Publishing status</span>
                    <select name="status" class="mt-2 w-full rounded-lg border border-line bg-white px-3 py-2 text-sm text-ink">
                        <option value="">All statuses</option>
                        @foreach ($statuses as $status)
                            <option value="{{ $status }}" @selected(($filters['status'] ?? '') === $status)>{{ str($status)->headline() }}</option>
                        @endforeach
                    </select>
                </label>
                <label>
                    <span class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">Language</span>
                    <select name="language" class="mt-2 w-full rounded-lg border border-line bg-white px-3 py-2 text-sm text-ink">
                        <option value="">All languages</option>
                        @foreach ($contentLanguages as $language)
                            <option value="{{ $language->code }}" @selected(($filters['language'] ?? '') === $language->code)>{{ $language->name }}</option>
                        @endforeach
                    </select>
                </label>
                <label>
                    <span class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">Distribution</span>
                    <select name="distribution" class="mt-2 w-full rounded-lg border border-line bg-white px-3 py-2 text-sm text-ink">
                        <option value="">All assets</option>
                        <option value="needs_website" @selected(($filters['distribution'] ?? '') === 'needs_website')>Needs website publishing</option>
                        <option value="needs_social" @selected(($filters['distribution'] ?? '') === 'needs_social')>Needs social distribution</option>
                        <option value="needs_review" @selected(($filters['distribution'] ?? '') === 'needs_review')>Needs review</option>
                    </select>
                </label>
                <div class="flex items-end gap-2">
                    <x-ui.button type="submit">Filter</x-ui.button>
                    <x-ui.button href="{{ route('app.distribution') }}" variant="light">Reset</x-ui.button>
                </div>
            </form>
        </x-ui.card>

        <div class="mt-6 space-y-4">
            @forelse ($assets as $asset)
                @php
                    $latestPublishing = $asset->publishingActions->first();
                    $latestSocial = $asset->socialPosts->first();
                    $connector = $asset->publishingChannel?->connectorInstallation;
                    $recommendations = $recommendationsByAsset->get($asset->id, collect());
                    $campaigns = $asset->campaigns->merge($asset->socialPosts->pluck('campaign')->filter())->unique('id')->values();
                    $reviewedAt = ($asset->metadata ?? [])['distribution_reviewed_at'] ?? null;
                    $lastActivity = collect([
                        $asset->updated_at,
                        $latestPublishing?->updated_at,
                        $latestSocial?->updated_at,
                        $recommendations->first()?->created_at,
                    ])->filter()->sortDesc()->first();
                    $channelReady = $connector && $connector->status === 'active' && in_array('publish_content', $connector->enabled_capabilities ?? [], true);
                    $rowChannels = $publishingChannels->filter(fn ($channel) => $channel->provider === $asset->publishingChannel?->provider || ! $asset->publishingChannel)->values();
                    $schedulableSocial = $asset->socialPosts
                        ->first(fn ($post) => in_array($post->status, ['draft', 'review', 'approved'], true));
                @endphp

                <x-ui.card class="p-5">
                    <div class="flex flex-col justify-between gap-4 lg:flex-row lg:items-start">
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <a href="{{ route('app.content.show', $asset) }}" class="text-base font-semibold text-ink hover:underline">{{ $asset->title }}</a>
                                <x-ui.badge :variant="$asset->status === 'published' ? 'success' : ($asset->status === 'failed' ? 'dark' : 'default')">{{ str($asset->status)->headline() }}</x-ui.badge>
                                <x-ui.badge>{{ strtoupper($asset->language) }} · {{ $asset->locale }}</x-ui.badge>
                                @if ($reviewedAt)
                                    <x-ui.badge variant="success">Reviewed</x-ui.badge>
                                @endif
                            </div>
                            <p class="mt-2 line-clamp-2 text-sm text-muted">{{ $asset->excerpt ?: str($asset->body)->limit(180) }}</p>
                        </div>
                        <p class="shrink-0 text-xs font-semibold uppercase tracking-[0.1em] text-muted">Last activity {{ $lastActivity?->diffForHumans() ?? 'never' }}</p>
                    </div>

                    <div class="mt-5 grid gap-4 lg:grid-cols-5">
                        <div class="rounded-lg border border-line bg-panel p-4">
                            <p class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">Website</p>
                            <p class="mt-2 text-sm font-semibold text-ink">{{ $asset->publishingChannel?->name ?? 'No channel' }}</p>
                            <p class="mt-1 text-xs text-muted">{{ $latestPublishing ? str($latestPublishing->status)->headline().' · '.str($latestPublishing->action)->headline() : 'No publishing action' }}</p>
                            <div class="mt-3">
                                <x-ui.badge :variant="$channelReady ? 'success' : 'dark'">{{ $channelReady ? 'Connector ready' : 'Needs active connector' }}</x-ui.badge>
                            </div>
                        </div>

                        <div class="rounded-lg border border-line bg-panel p-4">
                            <p class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">LinkedIn</p>
                            <p class="mt-2 text-sm font-semibold text-ink">{{ $asset->socialPosts->where('provider', 'linkedin')->first()?->socialProfile?->display_name ?? 'No LinkedIn post' }}</p>
                            <p class="mt-1 text-xs text-muted">{{ $asset->socialPosts->where('provider', 'linkedin')->first() ? str($asset->socialPosts->where('provider', 'linkedin')->first()->status)->headline() : 'Not created' }}</p>
                        </div>

                        <div class="rounded-lg border border-line bg-panel p-4">
                            <p class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">Campaign</p>
                            <p class="mt-2 text-sm font-semibold text-ink">{{ $campaigns->first()?->name ?? 'Unassigned' }}</p>
                            <p class="mt-1 text-xs text-muted">{{ $campaigns->count() > 1 ? ($campaigns->count() - 1).' more campaign(s)' : ($campaigns->first() ? str($campaigns->first()->status)->headline() : 'No campaign assignment') }}</p>
                        </div>

                        <div class="rounded-lg border border-line bg-panel p-4">
                            <p class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">Recommendations</p>
                            @forelse ($recommendations->take(3) as $recommendation)
                                <p class="{{ $loop->first ? 'mt-2' : 'mt-3' }} text-sm font-semibold text-ink">{{ $recommendation->title }}</p>
                                <p class="mt-1 text-xs text-muted">{{ $recommendation->recommended_action }}</p>
                            @empty
                                <p class="mt-2 text-sm font-semibold text-ink">No open recommendation</p>
                                <p class="mt-1 text-xs text-muted">Distribution path looks clear.</p>
                            @endforelse
                        </div>

                        <div class="rounded-lg border border-line bg-panel p-4">
                            <p class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">Audit</p>
                            <p class="mt-2 text-sm font-semibold text-ink">{{ $asset->latestAudit->first()?->status ? str($asset->latestAudit->first()->status)->headline() : 'No audit' }}</p>
                            <p class="mt-1 text-xs text-muted">{{ $asset->latestAudit->first()?->audited_at?->diffForHumans() ?? 'Run an audit before refreshing.' }}</p>
                        </div>
                    </div>

                    <div class="mt-5 flex flex-wrap gap-2">
                        @can('publish', $asset)
                            <form method="POST" action="{{ route('app.distribution.publish-website', $asset) }}" class="flex flex-wrap gap-2">
                                @csrf
                                <select name="publishing_channel_id" class="h-8 rounded-full border border-line bg-white px-3 text-xs font-semibold text-ink">
                                    <option value="">Default channel</option>
                                    @foreach ($rowChannels as $channel)
                                        <option value="{{ $channel->id }}" @selected($asset->channel_id === $channel->id)>{{ $channel->name }}</option>
                                    @endforeach
                                </select>
                                <x-ui.button size="sm">Publish to website</x-ui.button>
                            </form>
                        @endcan

                        @can('create', \App\Models\SocialPost::class)
                            <x-ui.button href="{{ route('app.content.social-posts.repurpose', $asset) }}" size="sm" variant="secondary">Create LinkedIn post</x-ui.button>
                        @endcan

                        @if ($schedulableSocial)
                            @can('schedule', $schedulableSocial)
                                <form method="POST" action="{{ route('app.distribution.social.schedule', $schedulableSocial) }}" class="flex flex-wrap gap-2">
                                    @csrf
                                    <input name="scheduled_at" type="datetime-local" value="{{ now()->addDay()->format('Y-m-d\TH:i') }}" class="h-8 rounded-full border border-line bg-white px-3 text-xs font-semibold text-ink">
                                    <x-ui.button size="sm" variant="secondary">Schedule social post</x-ui.button>
                                </form>
                            @endcan
                        @else
                            <x-ui.button href="{{ route('app.social-posts.create', ['content_asset_id' => $asset->id]) }}" size="sm" variant="secondary">Schedule social post</x-ui.button>
                        @endif

                        @can('update', $asset)
                            <form method="POST" action="{{ route('app.distribution.audit', $asset) }}">
                                @csrf
                                <x-ui.button size="sm" variant="secondary">Run audit</x-ui.button>
                            </form>

                            @if ($defaultTranslationTarget)
                                <form method="POST" action="{{ route('app.distribution.translate', $asset) }}" class="flex flex-wrap gap-2">
                                    @csrf
                                    <select name="target_language" class="h-8 rounded-full border border-line bg-white px-3 text-xs font-semibold text-ink">
                                        @foreach ($contentLanguages->where('code', '!=', $asset->language) as $language)
                                            <option value="{{ $language->code }}" @selected($defaultTranslationTarget === $language->code)>{{ $language->name }}</option>
                                        @endforeach
                                    </select>
                                    <x-ui.button size="sm" variant="secondary">Translate content</x-ui.button>
                                </form>
                            @endif

                            <form method="POST" action="{{ route('app.distribution.reviewed', $asset) }}">
                                @csrf
                                <x-ui.button size="sm" variant="secondary">Mark reviewed</x-ui.button>
                            </form>
                        @endcan
                    </div>
                </x-ui.card>
            @empty
                <x-dashboard.empty-state title="No content assets ready for distribution" message="Create or import content assets before coordinating website, social and campaign distribution." />
            @endforelse
        </div>

        <div class="mt-6">
            {{ $assets->links() }}
        </div>
    </div>
</x-app.layout>
