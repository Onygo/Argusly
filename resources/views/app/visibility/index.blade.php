<x-app.layout :title="__('visibility.title').' | Argusly'">
    <div class="w-full">
        <div class="flex flex-col justify-between gap-4 lg:flex-row lg:items-end">
            <div>
                <p class="eyebrow">{{ __('visibility.eyebrow') }}</p>
                <h1 class="mt-2 text-3xl font-semibold tracking-tight text-ink sm:text-4xl">{{ __('visibility.title') }}</h1>
                <p class="mt-2 max-w-2xl text-sm leading-6 text-muted">{{ __('visibility.description', ['brand' => $brand->name]) }}</p>
            </div>
            <x-ui.badge variant="blue">{{ $checks->count() }} checks</x-ui.badge>
        </div>

        <div class="mt-8 grid gap-4 md:grid-cols-2 xl:grid-cols-4">
            <x-dashboard.info-card label="Active checks" :value="$stats['checks']" />
            <x-dashboard.info-card label="Latest score" :value="$stats['latest_score']" empty="No data" />
            <x-dashboard.info-card label="Mentions found" :value="$stats['mentions_found']" />
            <x-dashboard.info-card label="Providers" :value="$stats['providers']" />
        </div>

        <form method="GET" action="{{ route('app.visibility') }}" class="mt-6 flex flex-col gap-3 rounded-md border border-line bg-white p-4 sm:flex-row sm:items-end">
            <label class="block sm:w-48">
                <span class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">{{ __('common.language') }}</span>
                <select name="language" class="mt-2 w-full rounded-md border border-line bg-white px-3 py-2 text-sm text-ink">
                    <option value="">{{ __('common.all_languages') }}</option>
                    @foreach ($contentLanguages as $language)
                        <option value="{{ $language->code }}" @selected(($filters['language'] ?? null) === $language->code)>{{ $language->name }}</option>
                    @endforeach
                </select>
            </label>
            <label class="block sm:w-48">
                <span class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">{{ __('common.market') }}</span>
                <input name="market" value="{{ $filters['market'] ?? '' }}" class="mt-2 w-full rounded-md border border-line bg-white px-3 py-2 text-sm uppercase text-ink" placeholder="US">
            </label>
            <x-ui.button type="submit">{{ __('common.filter') }}</x-ui.button>
            @if (($filters['language'] ?? null) || ($filters['market'] ?? null))
                <x-ui.button href="{{ route('app.visibility') }}" variant="secondary">{{ __('common.clear') }}</x-ui.button>
            @endif
        </form>

        @if ($latestRunsByLanguage->isNotEmpty())
            <div class="mt-4 grid gap-3 md:grid-cols-2 xl:grid-cols-5">
                @foreach ($latestRunsByLanguage as $language => $run)
                    <div class="rounded-md border border-line bg-panel p-4">
                        <p class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">{{ __('visibility.latest', ['language' => strtoupper($language)]) }}</p>
                        <p class="mt-2 text-sm font-semibold text-ink">{{ $run->provider }} · {{ $run->metadata['visibility_score'] ?? '-' }} score</p>
                        <p class="mt-1 text-xs text-muted">{{ $run->market ?? __('visibility.no_market') }} · {{ $run->captured_at?->diffForHumans() }}</p>
                    </div>
                @endforeach
            </div>
        @endif

        <div class="mt-6 grid gap-6 xl:grid-cols-[0.8fr_1.2fr]">
            <x-dashboard.section :title="__('visibility.create_check')" description="Define a query and provider lane. The placeholder job will create a deterministic result without calling external APIs.">
                <form method="POST" action="{{ route('app.visibility.checks.store') }}" class="space-y-4">
                    @csrf
                    <label class="block">
                        <span class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">{{ __('common.provider') }}</span>
                        <select name="provider" class="mt-2 w-full rounded-md border border-line bg-white px-3 py-2 text-sm text-ink">
                            @foreach ($providers as $provider)
                                <option value="{{ $provider }}" @selected(old('provider') === $provider)>{{ $provider }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="block">
                        <span class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">{{ __('visibility.brand_text') }}</span>
                        <input name="brand" value="{{ old('brand', $brand->name) }}" class="mt-2 w-full rounded-md border border-line bg-white px-3 py-2 text-sm text-ink">
                    </label>
                    <label class="block">
                        <span class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">{{ __('visibility.query') }}</span>
                        <textarea name="query" rows="4" required class="mt-2 w-full rounded-md border border-line bg-white px-3 py-2 text-sm text-ink">{{ old('query') }}</textarea>
                    </label>
                    <x-ui.button type="submit">{{ __('visibility.create_check') }}</x-ui.button>
                </form>
            </x-dashboard.section>

            <x-dashboard.section :title="__('visibility.provider_architecture')" description="Each provider lane is ready for a future worker, credential layer and parser.">
                <div class="grid gap-3 sm:grid-cols-2">
                    @foreach ($providers as $provider)
                        <div class="rounded-md border border-line bg-panel p-4">
                            <p class="text-sm font-semibold text-ink">{{ $provider }}</p>
                            <p class="mt-1 text-xs text-muted">Prompt, run, citation and entity schema ready</p>
                        </div>
                    @endforeach
                </div>
            </x-dashboard.section>
        </div>

        <div class="mt-6 grid gap-6 xl:grid-cols-[1.2fr_0.8fr]">
            <x-dashboard.section :title="__('visibility.visibility_checks')">
                @if ($checks->isEmpty())
                    <x-dashboard.empty-state title="No visibility checks" message="Create a check to start building visibility monitoring history for this brand." />
                @else
                    <div class="space-y-4">
                        @foreach ($checks as $check)
                            <x-visibility.check-card :check="$check" />
                        @endforeach
                    </div>
                @endif
            </x-dashboard.section>

            <x-dashboard.section :title="__('visibility.timeline')">
                @if ($timeline->isEmpty())
                    <x-dashboard.empty-state title="No timeline yet" message="Snapshots will appear after placeholder monitoring jobs run." />
                @else
                    <div class="space-y-3">
                        @foreach ($timeline as $snapshot)
                            <div class="rounded-md border border-line bg-white p-4">
                                <div class="flex items-start justify-between gap-4">
                                    <div>
                                        <p class="text-sm font-semibold text-ink">{{ $snapshot->provider ?? 'All providers' }}</p>
                                        <p class="mt-1 text-xs text-muted">{{ $snapshot->results_count }} results captured</p>
                                    </div>
                                    <time class="shrink-0 text-xs text-muted" datetime="{{ $snapshot->captured_at?->toIso8601String() }}">
                                        {{ $snapshot->captured_at?->format('M j, H:i') }}
                                    </time>
                                </div>
                                <div class="mt-4 grid grid-cols-3 gap-2 text-right">
                                    <div class="rounded-md bg-panel p-3">
                                        <p class="text-[10px] font-semibold uppercase tracking-[0.1em] text-muted">Score</p>
                                        <p class="mt-1 text-lg font-semibold text-ink">{{ $snapshot->score ?? '-' }}</p>
                                    </div>
                                    <div class="rounded-md bg-panel p-3">
                                        <p class="text-[10px] font-semibold uppercase tracking-[0.1em] text-muted">Pos.</p>
                                        <p class="mt-1 text-lg font-semibold text-ink">{{ $snapshot->position ?? '-' }}</p>
                                    </div>
                                    <div class="rounded-md bg-panel p-3">
                                        <p class="text-[10px] font-semibold uppercase tracking-[0.1em] text-muted">Mention</p>
                                        <p class="mt-1 text-lg font-semibold text-ink">{{ $snapshot->mention_found ? 'Yes' : 'No' }}</p>
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </x-dashboard.section>
        </div>

        <div class="mt-6 grid gap-6 xl:grid-cols-[0.9fr_1.1fr]">
            <x-dashboard.section :title="__('visibility.prompt_library')" description="Create reusable AI visibility prompts, assign them to brand context, and run fake-provider tests before real adapters are enabled.">
                <form method="POST" action="{{ route('app.visibility.prompts.store') }}" class="space-y-4 rounded-md border border-line bg-panel p-4">
                    @csrf
                    <div class="grid gap-3 md:grid-cols-2">
                        <label class="block">
                            <span class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">{{ __('common.name') }}</span>
                            <input name="name" value="{{ old('name') }}" required class="mt-2 w-full rounded-md border border-line bg-white px-3 py-2 text-sm text-ink" placeholder="Best AI visibility tools">
                        </label>
                        <label class="block">
                            <span class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">{{ __('common.brand') }}</span>
                            <select name="brand_id" class="mt-2 w-full rounded-md border border-line bg-white px-3 py-2 text-sm text-ink">
                                @foreach ($brands as $brandOption)
                                    <option value="{{ $brandOption->id }}" @selected($brandOption->id === $brand->id)>{{ $brandOption->name }}</option>
                                @endforeach
                            </select>
                        </label>
                    </div>
                    <label class="block">
                        <span class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">{{ __('visibility.prompt') }}</span>
                        <textarea name="prompt" rows="3" required class="mt-2 w-full rounded-md border border-line bg-white px-3 py-2 text-sm text-ink" placeholder="Who are the leading competitors of {brand}?">{{ old('prompt') }}</textarea>
                    </label>
                    <div class="grid gap-3 md:grid-cols-5">
                        <label class="block">
                            <span class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">{{ __('common.language') }}</span>
                            <select name="language" class="mt-2 w-full rounded-md border border-line bg-white px-3 py-2 text-sm text-ink">
                                @foreach ($contentLanguages as $language)
                                    <option value="{{ $language->code }}" @selected(old('language', $brand->default_content_language ?? 'en') === $language->code)>{{ $language->name }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label class="block">
                            <span class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">{{ __('common.intent') }}</span>
                            <input name="intent" value="{{ old('intent') }}" class="mt-2 w-full rounded-md border border-line bg-white px-3 py-2 text-sm text-ink" placeholder="commercial">
                        </label>
                        <label class="block">
                            <span class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">{{ __('common.locale') }}</span>
                            <input name="locale" value="{{ old('locale', 'en_US') }}" class="mt-2 w-full rounded-md border border-line bg-white px-3 py-2 text-sm text-ink" placeholder="en_US">
                        </label>
                        <label class="block">
                            <span class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">{{ __('common.market') }}</span>
                            <input name="market" value="{{ old('market') }}" class="mt-2 w-full rounded-md border border-line bg-white px-3 py-2 text-sm text-ink" placeholder="US">
                        </label>
                        <label class="block">
                            <span class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">{{ __('common.status') }}</span>
                            <select name="status" class="mt-2 w-full rounded-md border border-line bg-white px-3 py-2 text-sm text-ink">
                                @foreach (\App\Models\VisibilityPromptTemplate::STATUSES as $status)
                                    <option value="{{ $status }}" @selected($status === 'active')>{{ str($status)->headline() }}</option>
                                @endforeach
                            </select>
                        </label>
                    </div>
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">{{ __('visibility.examples') }}</p>
                        <div class="mt-2 flex flex-wrap gap-2">
                            @foreach ($examplePrompts as $examplePrompt)
                                <span class="rounded-full border border-line bg-white px-3 py-1 text-xs font-semibold text-muted">{{ $examplePrompt }}</span>
                            @endforeach
                        </div>
                    </div>
                    <x-ui.button type="submit">{{ __('visibility.create_prompt') }}</x-ui.button>
                </form>

                @if ($promptTemplates->isEmpty())
                    <div class="mt-4">
                        <x-dashboard.empty-state title="No prompt templates" message="Create a prompt to start testing AI visibility provider runs." />
                    </div>
                @else
                    <div class="mt-5 space-y-4">
                        @foreach ($promptTemplates as $template)
                            <div class="rounded-md border border-line bg-white p-4">
                                <form method="POST" action="{{ route('app.visibility.prompts.update', $template) }}" class="space-y-3">
                                    @csrf
                                    @method('PUT')
                                    <div class="flex flex-col justify-between gap-3 md:flex-row md:items-start">
                                        <div class="grid flex-1 gap-3 md:grid-cols-2">
                                            <label class="block">
                                                <span class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">{{ __('common.name') }}</span>
                                                <input name="name" value="{{ $template->name }}" required class="mt-2 w-full rounded-md border border-line bg-white px-3 py-2 text-sm text-ink">
                                            </label>
                                            <label class="block">
                                                <span class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">{{ __('common.brand') }}</span>
                                                <select name="brand_id" class="mt-2 w-full rounded-md border border-line bg-white px-3 py-2 text-sm text-ink">
                                                    @foreach ($brands as $brandOption)
                                                        <option value="{{ $brandOption->id }}" @selected($brandOption->id === $template->brand_id)>{{ $brandOption->name }}</option>
                                                    @endforeach
                                                </select>
                                            </label>
                                        </div>
                                        <x-ui.badge>{{ str($template->status)->headline() }}</x-ui.badge>
                                    </div>
                                    <label class="block">
                                        <span class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">{{ __('visibility.prompt') }}</span>
                                        <textarea name="prompt" rows="3" required class="mt-2 w-full rounded-md border border-line bg-white px-3 py-2 text-sm text-ink">{{ $template->prompt }}</textarea>
                                    </label>
                                    <div class="grid gap-3 md:grid-cols-5">
                                        <select name="language" class="rounded-md border border-line bg-white px-3 py-2 text-sm text-ink">
                                            @foreach ($contentLanguages as $language)
                                                <option value="{{ $language->code }}" @selected($language->code === $template->language)>{{ $language->name }}</option>
                                            @endforeach
                                        </select>
                                        <input name="intent" value="{{ $template->intent }}" class="rounded-md border border-line bg-white px-3 py-2 text-sm text-ink" placeholder="Intent">
                                        <input name="locale" value="{{ $template->locale }}" class="rounded-md border border-line bg-white px-3 py-2 text-sm text-ink" placeholder="Locale">
                                        <input name="market" value="{{ $template->market }}" class="rounded-md border border-line bg-white px-3 py-2 text-sm text-ink" placeholder="Market">
                                        <select name="status" class="rounded-md border border-line bg-white px-3 py-2 text-sm text-ink">
                                            @foreach (\App\Models\VisibilityPromptTemplate::STATUSES as $status)
                                                <option value="{{ $status }}" @selected($status === $template->status)>{{ str($status)->headline() }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <x-ui.button size="sm" variant="secondary">Save</x-ui.button>
                                </form>
                                <div class="mt-3 flex flex-wrap gap-2">
                                    <form method="POST" action="{{ route('app.visibility.prompts.duplicate', $template) }}">
                                        @csrf
                                        <x-ui.button size="sm" variant="secondary">{{ __('common.duplicate') }}</x-ui.button>
                                    </form>
                                    @if ($template->status !== 'archived')
                                        <form method="POST" action="{{ route('app.visibility.prompts.archive', $template) }}">
                                            @csrf
                                            <x-ui.button size="sm" variant="secondary">{{ __('common.archive') }}</x-ui.button>
                                        </form>
                                    @endif
                                    <form method="POST" action="{{ route('app.visibility.prompts.run', $template) }}" class="flex gap-2">
                                        @csrf
                                        <select name="provider" class="h-8 rounded-md border border-line bg-white px-2 text-xs font-semibold text-ink">
                                            @foreach ($adapterProviders as $provider)
                                                <option value="{{ $provider->key() }}">{{ $provider->name() }}</option>
                                            @endforeach
                                        </select>
                                        <x-ui.button size="sm">{{ __('visibility.run_test') }}</x-ui.button>
                                    </form>
                                </div>
                                @php($latestRuns = $template->providerRuns->sortByDesc('captured_at')->take(3))
                                @if ($latestRuns->isNotEmpty())
                                    <div class="mt-4 space-y-2 border-t border-line pt-3">
                                        @foreach ($latestRuns as $run)
                                            <div class="flex flex-col justify-between gap-1 text-xs text-muted sm:flex-row">
                                                <span><strong class="text-ink">{{ $run->provider }}</strong> · {{ strtoupper($run->language) }} · {{ $run->market ?? __('visibility.no_market') }} · {{ str($run->status)->headline() }} · {{ $run->metadata['visibility_score'] ?? '-' }} score</span>
                                                <span>{{ $run->captured_at?->diffForHumans() }}</span>
                                            </div>
                                        @endforeach
                                    </div>
                                @endif
                            </div>
                        @endforeach
                    </div>
                @endif
            </x-dashboard.section>

            <x-dashboard.section :title="__('visibility.provider_runs')">
                @if ($providerRuns->isEmpty())
                    <x-dashboard.empty-state title="No provider runs" message="Placeholder and future external runs will appear here with citations, entities, latency and cost metadata." />
                @else
                    <div class="space-y-3">
                        @foreach ($providerRuns as $run)
                            <div class="rounded-md border border-line bg-white p-4">
                                <div class="flex flex-col justify-between gap-3 sm:flex-row sm:items-start">
                                    <div>
                                        <div class="flex flex-wrap items-center gap-2">
                                            <x-ui.badge variant="blue">{{ $run->provider }}</x-ui.badge>
                                            <x-ui.badge>{{ strtoupper($run->language) }}</x-ui.badge>
                                            @if ($run->market)
                                                <x-ui.badge>{{ strtoupper($run->market) }}</x-ui.badge>
                                            @endif
                                            <span class="text-xs font-semibold text-muted">{{ $run->model ?? 'Model pending' }}</span>
                                        </div>
                                        <p class="mt-3 text-sm font-semibold text-ink">{{ $run->query }}</p>
                                    </div>
                                    <time class="shrink-0 text-xs text-muted" datetime="{{ $run->captured_at?->toIso8601String() }}">
                                        {{ $run->captured_at?->format('M j, H:i') }}
                                    </time>
                                </div>
                                <p class="mt-3 text-sm leading-6 text-muted">{{ $run->normalized_answer ?? 'No normalized answer captured yet.' }}</p>
                                <div class="mt-4 grid gap-2 text-xs text-muted sm:grid-cols-4">
                                    <span>{{ str($run->status)->headline() }}</span>
                                    <span>{{ $run->citations->count() }} citations</span>
                                    <span>{{ $run->answerEntities->count() }} entities</span>
                                    <span>{{ $run->latency_ms ?? 0 }} ms / {{ $run->cost_credits }} credits</span>
                                </div>
                                <p class="mt-2 text-xs text-muted">{{ __('visibility.input_answer', ['input' => strtoupper($run->input_language), 'answer' => strtoupper($run->normalized_answer_language)]) }}{{ $run->detected_language ? ' · '.__('visibility.detected', ['language' => strtoupper($run->detected_language)]) : '' }}</p>
                            </div>
                        @endforeach
                    </div>
                @endif
            </x-dashboard.section>
        </div>
    </div>
</x-app.layout>
