<x-app.layout title="Narratives | Argusly">
    <div class="w-full">
        <div class="flex flex-col justify-between gap-4 lg:flex-row lg:items-end">
            <div>
                <p class="eyebrow">Narrative intelligence</p>
                <h1 class="mt-2 text-3xl font-semibold tracking-tight text-ink sm:text-4xl">Narratives</h1>
                <p class="mt-2 max-w-2xl text-sm leading-6 text-muted">Understand how the brand is being described, where gaps appear and which actions can shift perception.</p>
            </div>
        </div>

        @if (session('status'))
            <div class="mt-6 rounded-md border border-line bg-white p-4 text-sm font-medium text-ink">{{ session('status') }}</div>
        @endif

        <div class="mt-8 grid gap-4 md:grid-cols-2 xl:grid-cols-5">
            <x-dashboard.info-card label="Narratives" :value="$stats['narratives']" />
            <x-dashboard.info-card label="Active" :value="$stats['active']" />
            <x-dashboard.info-card label="Observations" :value="$stats['observations']" />
            <x-dashboard.info-card label="Open gaps" :value="$stats['open_gaps']" />
            <x-dashboard.info-card label="Avg gap score" :value="$stats['average_gap_score']" empty="No gaps" />
        </div>

        <div class="mt-6 grid gap-6 xl:grid-cols-[1.2fr_0.8fr]">
            <x-dashboard.section title="Narrative dashboard" description="Active narrative positions and their linked intelligence context.">
                <form method="GET" action="{{ route('app.narratives.index') }}" class="mb-5 grid gap-3 md:grid-cols-[1fr_150px_150px_auto]">
                    <select name="type" class="rounded-md border border-line bg-white px-3 py-2 text-sm text-ink">
                        <option value="">All types</option>
                        @foreach ($types as $type)
                            <option value="{{ $type }}" @selected(($filters['type'] ?? '') === $type)>{{ str($type)->headline() }}</option>
                        @endforeach
                    </select>
                    <select name="status" class="rounded-md border border-line bg-white px-3 py-2 text-sm text-ink">
                        <option value="">All statuses</option>
                        @foreach ($statuses as $status)
                            <option value="{{ $status }}" @selected(($filters['status'] ?? '') === $status)>{{ str($status)->headline() }}</option>
                        @endforeach
                    </select>
                    <select name="importance" class="rounded-md border border-line bg-white px-3 py-2 text-sm text-ink">
                        <option value="">All importance</option>
                        @foreach ($importanceLevels as $importance)
                            <option value="{{ $importance }}" @selected(($filters['importance'] ?? '') === $importance)>{{ str($importance)->headline() }}</option>
                        @endforeach
                    </select>
                    <x-ui.button type="submit" variant="secondary">Filter</x-ui.button>
                </form>

                @if ($narratives->isEmpty())
                    <x-dashboard.empty-state title="No narratives" message="Create desired narratives like AI Visibility Platform or Modern AI Software Company to begin measuring perception gaps." />
                @else
                    <div class="space-y-3">
                        @foreach ($narratives as $narrative)
                            <div class="rounded-md border border-line bg-panel p-4">
                                <div class="flex flex-col justify-between gap-3 sm:flex-row sm:items-start">
                                    <div class="min-w-0">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <p class="text-sm font-semibold text-ink">{{ $narrative->title }}</p>
                                            <x-ui.badge variant="blue">{{ str($narrative->narrative_type)->headline() }}</x-ui.badge>
                                            <x-ui.badge>{{ str($narrative->status)->headline() }}</x-ui.badge>
                                            <x-ui.badge variant="{{ in_array($narrative->importance, ['critical', 'high'], true) ? 'blue' : 'default' }}">{{ str($narrative->importance)->headline() }}</x-ui.badge>
                                        </div>
                                        <p class="mt-2 text-sm leading-6 text-muted">{{ $narrative->description }}</p>
                                    </div>
                                    <div class="grid shrink-0 grid-cols-3 gap-2 text-center">
                                        <div class="rounded-md border border-line bg-white px-3 py-2">
                                            <p class="text-sm font-semibold text-ink">{{ $narrative->observations_count }}</p>
                                            <p class="text-[10px] font-semibold uppercase tracking-[0.1em] text-muted">Obs</p>
                                        </div>
                                        <div class="rounded-md border border-line bg-white px-3 py-2">
                                            <p class="text-sm font-semibold text-ink">{{ $narrative->gaps_count }}</p>
                                            <p class="text-[10px] font-semibold uppercase tracking-[0.1em] text-muted">Gaps</p>
                                        </div>
                                        <div class="rounded-md border border-line bg-white px-3 py-2">
                                            <p class="text-sm font-semibold text-ink">{{ $narrative->topics_count + $narrative->entities_count + $narrative->mentions_count + $narrative->competitors_count + $narrative->visibility_provider_runs_count }}</p>
                                            <p class="text-[10px] font-semibold uppercase tracking-[0.1em] text-muted">Links</p>
                                        </div>
                                    </div>
                                </div>

                                <div class="mt-4 grid gap-4 lg:grid-cols-2">
                                    <form method="POST" action="{{ route('app.narratives.observations.store', $narrative) }}" class="space-y-3">
                                        @csrf
                                        <textarea name="observation" rows="2" required placeholder="Detected description or observation" class="w-full rounded-md border border-line bg-white px-3 py-2 text-sm text-ink"></textarea>
                                        <div class="grid gap-3 sm:grid-cols-2">
                                            <select name="sentiment" class="rounded-md border border-line bg-white px-3 py-2 text-sm text-ink">
                                                <option value="">Sentiment</option>
                                                @foreach ($sentiments as $sentiment)
                                                    <option value="{{ $sentiment }}">{{ str($sentiment)->headline() }}</option>
                                                @endforeach
                                            </select>
                                            <input name="confidence_score" type="number" min="0" max="100" placeholder="Confidence" class="rounded-md border border-line bg-white px-3 py-2 text-sm text-ink">
                                        </div>
                                        <x-ui.button type="submit" size="sm" variant="secondary">Record observation</x-ui.button>
                                    </form>

                                    <form method="POST" action="{{ route('app.narratives.gaps.store', $narrative) }}" class="space-y-3">
                                        @csrf
                                        <input name="desired_state" required placeholder="Desired narrative, e.g. AI Visibility Platform" class="w-full rounded-md border border-line bg-white px-3 py-2 text-sm text-ink">
                                        <input name="detected_state" required placeholder="Detected narrative, e.g. SEO Tool" class="w-full rounded-md border border-line bg-white px-3 py-2 text-sm text-ink">
                                        <input name="gap_score" type="number" min="0" max="100" placeholder="Gap score" class="w-full rounded-md border border-line bg-white px-3 py-2 text-sm text-ink">
                                        <x-ui.button type="submit" size="sm">Detect gap</x-ui.button>
                                    </form>
                                </div>
                            </div>
                        @endforeach
                    </div>
                    <div class="mt-5">{{ $narratives->links() }}</div>
                @endif
            </x-dashboard.section>

            <div class="space-y-6">
                <x-dashboard.section title="Create narrative" description="Define the desired story Argusly should measure against observed descriptions.">
                    <form method="POST" action="{{ route('app.narratives.store') }}" class="space-y-4">
                        @csrf
                        <input name="title" required placeholder="AI Visibility Platform" class="w-full rounded-md border border-line bg-white px-3 py-2 text-sm text-ink">
                        <textarea name="description" rows="3" required placeholder="Desired narrative description" class="w-full rounded-md border border-line bg-white px-3 py-2 text-sm text-ink"></textarea>
                        <div class="grid gap-3 sm:grid-cols-3">
                            <select name="narrative_type" class="rounded-md border border-line bg-white px-3 py-2 text-sm text-ink">
                                @foreach ($types as $type)
                                    <option value="{{ $type }}">{{ str($type)->headline() }}</option>
                                @endforeach
                            </select>
                            <select name="status" class="rounded-md border border-line bg-white px-3 py-2 text-sm text-ink">
                                @foreach ($statuses as $status)
                                    <option value="{{ $status }}" @selected($status === 'active')>{{ str($status)->headline() }}</option>
                                @endforeach
                            </select>
                            <select name="importance" class="rounded-md border border-line bg-white px-3 py-2 text-sm text-ink">
                                @foreach ($importanceLevels as $importance)
                                    <option value="{{ $importance }}" @selected($importance === 'medium')>{{ str($importance)->headline() }}</option>
                                @endforeach
                            </select>
                        </div>
                        @foreach ([
                            'topic_ids' => ['Topics', $topics],
                            'entity_ids' => ['Entities', $entities],
                            'mention_ids' => ['Mentions', $mentions],
                            'competitor_ids' => ['Competitors', $competitors],
                            'visibility_provider_run_ids' => ['AI visibility runs', $visibilityRuns],
                        ] as $field => [$label, $items])
                            <label class="block">
                                <span class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">{{ $label }}</span>
                                <select name="{{ $field }}[]" multiple class="mt-2 min-h-24 w-full rounded-md border border-line bg-white px-3 py-2 text-sm text-ink">
                                    @foreach ($items as $item)
                                        <option value="{{ $item->id }}">{{ $item->name ?? $item->title ?? $item->query ?? ('#'.$item->id) }}</option>
                                    @endforeach
                                </select>
                            </label>
                        @endforeach
                        <x-ui.button type="submit">Create narrative</x-ui.button>
                    </form>
                </x-dashboard.section>
            </div>
        </div>

        <div class="mt-6 grid gap-6 xl:grid-cols-[0.9fr_1.1fr]">
            <x-dashboard.section title="Narrative gap overview" description="Where detected descriptions differ from desired positioning.">
                @if ($openGaps->isEmpty())
                    <x-dashboard.empty-state title="No open gaps" message="Gaps such as AI Visibility Platform versus SEO Tool will appear here when detected." />
                @else
                    <div class="space-y-3">
                        @foreach ($openGaps as $gap)
                            <div class="rounded-md border border-line bg-panel p-4">
                                <div class="flex items-start justify-between gap-3">
                                    <div>
                                        <p class="text-sm font-semibold text-ink">{{ $gap->narrative->title }}</p>
                                        <p class="mt-2 text-xs text-muted">Desired: {{ $gap->desired_state }}</p>
                                        <p class="mt-1 text-xs text-muted">Detected: {{ $gap->detected_state }}</p>
                                    </div>
                                    <x-ui.badge variant="{{ ($gap->gap_score ?? 0) >= 65 ? 'blue' : 'default' }}">{{ $gap->gap_score !== null ? $gap->gap_score : 'n/a' }}</x-ui.badge>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </x-dashboard.section>

            <x-dashboard.section title="Narrative recommendations" description="Automatically generated actions from narrative gap signals.">
                @if ($recommendations->isEmpty())
                    <x-dashboard.empty-state title="No narrative recommendations" message="Create or detect a narrative gap to generate content, positioning, campaign and citation recommendations." />
                @else
                    <div class="grid gap-3 md:grid-cols-2">
                        @foreach ($recommendations as $recommendation)
                            <div class="rounded-md border border-line bg-panel p-4">
                                <p class="text-sm font-semibold text-ink">{{ $recommendation->title }}</p>
                                <p class="mt-2 text-xs leading-5 text-muted">{{ $recommendation->recommended_action }}</p>
                                <div class="mt-3 flex flex-wrap gap-2">
                                    <x-ui.badge>{{ str($recommendation->action_type)->replace('_', ' ')->headline() }}</x-ui.badge>
                                    <x-ui.badge variant="blue">{{ $recommendation->impact_score }}</x-ui.badge>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </x-dashboard.section>
        </div>
    </div>
</x-app.layout>
