<x-app.layout title="{{ $campaign->name }} | Argusly">
    <div class="w-full">
        <div class="flex flex-col justify-between gap-4 lg:flex-row lg:items-end">
            <div>
                <p class="eyebrow">Campaign detail</p>
                <h1 class="mt-2 text-3xl font-semibold tracking-tight text-ink sm:text-4xl">{{ $campaign->name }}</h1>
                <p class="mt-2 max-w-2xl text-sm leading-6 text-muted">{{ $campaign->objective ?: $campaign->description ?: 'No objective yet.' }}</p>
            </div>
            <div class="flex flex-wrap gap-2">
                <x-ui.button href="{{ route('app.campaigns.board', $campaign) }}">Planning board</x-ui.button>
                <x-ui.button href="{{ route('app.campaigns') }}" variant="secondary">Back to campaigns</x-ui.button>
            </div>
        </div>

        @if (session('status'))
            <div class="mt-6 rounded-md border border-line bg-white p-4 text-sm font-medium text-ink">{{ session('status') }}</div>
        @endif

        <div class="mt-8 grid gap-4 md:grid-cols-2 xl:grid-cols-4">
            <x-dashboard.info-card label="Status" value="{{ str($campaign->status)->headline() }}" />
            <x-dashboard.info-card label="Type" value="{{ str($campaign->metadata['campaign_type'] ?? 'content')->headline() }}" />
            <x-dashboard.info-card label="Start" :value="$campaign->start_date?->format('M j, Y')" empty="No start" />
            <x-dashboard.info-card label="End" :value="$campaign->end_date?->format('M j, Y')" empty="No end" />
        </div>

        <div class="mt-6 grid gap-6 xl:grid-cols-[0.8fr_1.2fr]">
            <x-dashboard.section title="Edit campaign" description="Update the campaign and its linked content, topics and signals.">
                <form method="POST" action="{{ route('app.campaigns.update', $campaign) }}" class="space-y-5">
                    @csrf
                    @method('PUT')
                    @include('app.campaigns._form', ['campaign' => $campaign, 'statuses' => $statuses, 'types' => $types, 'assets' => $assets, 'topics' => $topics, 'signals' => $signals])
                    <x-ui.button type="submit">Save campaign</x-ui.button>
                </form>

                <form method="POST" action="{{ route('app.campaigns.destroy', $campaign) }}" class="mt-4" onsubmit="return confirm('Delete this campaign?')">
                    @csrf
                    @method('DELETE')
                    <x-ui.button type="submit" variant="secondary">Delete campaign</x-ui.button>
                </form>
            </x-dashboard.section>

            <div class="space-y-6">
                <x-dashboard.section title="Campaign Timeline" description="Manual timeline assembled from campaign dates, linked content and intelligence signals.">
                    @if ($timeline->isEmpty())
                        <x-dashboard.empty-state title="No timeline events" message="Add dates, content assets or intelligence signals to build the campaign timeline." />
                    @else
                        <div class="space-y-3">
                            @foreach ($timeline as $event)
                                <div class="rounded-md border border-line bg-panel p-4">
                                    <div class="flex items-start justify-between gap-4">
                                        <div class="min-w-0">
                                            <p class="text-sm font-semibold text-ink">{{ $event['label'] }}</p>
                                            <p class="mt-1 truncate text-xs text-muted">{{ $event['description'] }}</p>
                                        </div>
                                        <div class="shrink-0 text-right">
                                            <x-ui.badge variant="{{ $event['type'] === 'campaign' ? 'blue' : 'default' }}">{{ str($event['type'])->headline() }}</x-ui.badge>
                                            <p class="mt-2 text-xs text-muted">{{ \Illuminate\Support\Carbon::parse($event['date'])->format('M j, Y') }}</p>
                                        </div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </x-dashboard.section>

                <div class="grid gap-6 lg:grid-cols-2">
                    <x-dashboard.section title="Content Assets">
                        @if ($campaign->contentAssets->isEmpty())
                            <x-dashboard.empty-state title="No assets" message="Link campaign content assets from the editor." />
                        @else
                            <div class="space-y-3">
                                @foreach ($campaign->contentAssets as $asset)
                                    <a href="{{ route('app.content.show', $asset) }}" class="block rounded-md border border-line bg-panel p-4 hover:bg-white">
                                        <p class="truncate text-sm font-semibold text-ink">{{ $asset->title }}</p>
                                        <p class="mt-1 text-xs text-muted">{{ str($asset->status)->headline() }} · {{ str($asset->type)->headline() }}</p>
                                    </a>
                                @endforeach
                            </div>
                        @endif
                    </x-dashboard.section>

                    <x-dashboard.section title="Topics">
                        @if ($campaign->topics->isEmpty())
                            <x-dashboard.empty-state title="No topics" message="Link topics to give the campaign strategic context." />
                        @else
                            <div class="flex flex-wrap gap-2">
                                @foreach ($campaign->topics as $topic)
                                    <a href="{{ route('app.topics.show', $topic) }}" class="inline-flex rounded-full border border-line bg-panel px-3 py-1.5 text-xs font-semibold text-ink hover:bg-white">{{ $topic->name }}</a>
                                @endforeach
                            </div>
                        @endif
                    </x-dashboard.section>
                </div>

                <x-dashboard.section title="Intelligence Signals">
                    @if ($campaign->signals->isEmpty())
                        <x-dashboard.empty-state title="No signals" message="Attach signals that explain why the campaign exists or how it should adapt." />
                    @else
                        <div class="grid gap-3 lg:grid-cols-2">
                            @foreach ($campaign->signals as $signal)
                                <div class="rounded-md border border-line bg-panel p-4">
                                    <div class="flex items-start justify-between gap-3">
                                        <div class="min-w-0">
                                            <p class="truncate text-sm font-semibold text-ink">{{ $signal->title }}</p>
                                            <p class="mt-1 line-clamp-2 text-xs leading-5 text-muted">{{ $signal->summary }}</p>
                                        </div>
                                        <x-ui.badge>{{ str($signal->priority)->headline() }}</x-ui.badge>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </x-dashboard.section>
            </div>
        </div>
    </div>
</x-app.layout>
