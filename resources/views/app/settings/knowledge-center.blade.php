@php
    $isContentBrandVoice = request()->routeIs('app.content.brand-voice*');
    $routeBase = $routeBase ?? ($isContentBrandVoice ? 'app.content.brand-voice' : 'settings.knowledge-center');
@endphp

<x-app.settings.layout
    :title="$isContentBrandVoice ? 'Brand Voice' : 'Knowledge Center'"
    :eyebrow="$isContentBrandVoice ? 'Content Engine' : 'Administration'"
    description="The canonical brand source of truth for AI visibility, content generation, recommendations, campaigns, creators, relationships and agents."
>
    @if (! $brand)
        <x-dashboard.empty-state title="No brand selected" message="Select a brand before managing its knowledge center." />
    @else
        @if (session('status'))
            <div class="mb-6 rounded-md border border-line bg-panel px-4 py-3 text-sm font-semibold text-ink">
                {{ session('status') }}
            </div>
        @endif

        <x-dashboard.section title="Generate with AI" description="Turn website text, a URL or guided notes into Argusly brand context and personas.">
            <form method="POST" action="{{ route($routeBase.'.setup.generate') }}" class="space-y-5">
                @csrf
                <div class="grid gap-3 lg:grid-cols-3">
                    <label class="block rounded-md border border-line bg-panel p-4">
                        <span class="flex items-center gap-2 text-sm font-semibold text-ink">
                            <input type="radio" name="input_method" value="paste_text" @checked(old('input_method', 'paste_text') === 'paste_text') class="rounded border-line text-blue">
                            Paste text
                        </span>
                        <span class="mt-2 block text-xs leading-5 text-muted">Website copy, an about page, a positioning doc or raw notes.</span>
                    </label>
                    <label class="block rounded-md border border-line bg-panel p-4">
                        <span class="flex items-center gap-2 text-sm font-semibold text-ink">
                            <input type="radio" name="input_method" value="website_url" @checked(old('input_method') === 'website_url') class="rounded border-line text-blue">
                            Website URL
                        </span>
                        <span class="mt-2 block text-xs leading-5 text-muted">Fetch a public page and use its readable text as source material.</span>
                    </label>
                    <label class="block rounded-md border border-line bg-panel p-4">
                        <span class="flex items-center gap-2 text-sm font-semibold text-ink">
                            <input type="radio" name="input_method" value="guided_input" @checked(old('input_method') === 'guided_input') class="rounded border-line text-blue">
                            Guided input
                        </span>
                        <span class="mt-2 block text-xs leading-5 text-muted">Answer a few prompts when no source document is ready.</span>
                    </label>
                </div>

                <div class="grid gap-4 lg:grid-cols-[1fr_0.8fr]">
                    <div class="space-y-4">
                        <label class="block">
                            <span class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">Paste text</span>
                            <textarea name="source_text" rows="7" class="mt-2 w-full rounded-md border border-line bg-white px-3 py-2 text-sm text-ink" placeholder="Paste website text, about page content, brand positioning or company notes...">{{ old('source_text') }}</textarea>
                        </label>
                        <label class="block">
                            <span class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">Website URL</span>
                            <input name="website_url" value="{{ old('website_url', $center['profile']->website) }}" placeholder="https://example.com" class="mt-2 w-full rounded-md border border-line bg-white px-3 py-2 text-sm text-ink">
                        </label>
                    </div>
                    <div class="space-y-3">
                        <label class="block">
                            <span class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">Company</span>
                            <input name="guided_company" value="{{ old('guided_company', $brand->name) }}" class="mt-2 w-full rounded-md border border-line bg-white px-3 py-2 text-sm text-ink">
                        </label>
                        <label class="block">
                            <span class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">Offer</span>
                            <textarea name="guided_offer" rows="2" class="mt-2 w-full rounded-md border border-line bg-white px-3 py-2 text-sm text-ink">{{ old('guided_offer') }}</textarea>
                        </label>
                        <label class="block">
                            <span class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">Audience</span>
                            <textarea name="guided_audience" rows="2" class="mt-2 w-full rounded-md border border-line bg-white px-3 py-2 text-sm text-ink">{{ old('guided_audience') }}</textarea>
                        </label>
                        <label class="block">
                            <span class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">Positioning</span>
                            <textarea name="guided_positioning" rows="2" class="mt-2 w-full rounded-md border border-line bg-white px-3 py-2 text-sm text-ink">{{ old('guided_positioning') }}</textarea>
                        </label>
                        <label class="block">
                            <span class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">Voice</span>
                            <input name="guided_voice" value="{{ old('guided_voice') }}" class="mt-2 w-full rounded-md border border-line bg-white px-3 py-2 text-sm text-ink" placeholder="Clear, expert, pragmatic">
                        </label>
                    </div>
                </div>

                <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                    @foreach ($setupSections as $sectionKey => $sectionLabel)
                        <label class="flex items-start gap-3 rounded-md border border-line bg-white p-4">
                            <input type="checkbox" name="sections[]" value="{{ $sectionKey }}" @checked(in_array($sectionKey, old('sections', array_keys($setupSections)), true)) class="mt-0.5 rounded border-line text-blue">
                            <span>
                                <span class="block text-sm font-semibold text-ink">{{ $sectionLabel }}</span>
                                <span class="mt-1 block text-xs leading-5 text-muted">
                                    @switch($sectionKey)
                                        @case('company_profile') Core brand fields @break
                                        @case('brand_voices') Voice rules and narratives @break
                                        @case('buyer_personas') Marketing audiences @break
                                        @case('team_personas') Internal spokesperson audiences @break
                                    @endswitch
                                </span>
                            </span>
                        </label>
                    @endforeach
                </div>

                <x-ui.button type="submit">Generate setup preview</x-ui.button>
            </form>

            @if ($setupPreview)
                @php
                    $previewSections = $setupPreview['sections'] ?? array_keys($setupSections);
                    $previewPayload = collect($setupPreview)->except(['llm', 'sections'])->all();
                    $profilePreview = $setupPreview['company_profile'] ?? [];
                @endphp
                <div class="mt-6 rounded-md border border-line bg-white p-5">
                    <div class="flex flex-col justify-between gap-3 md:flex-row md:items-start">
                        <div>
                            <p class="text-sm font-semibold text-ink">Preview ready</p>
                            <p class="mt-1 text-xs text-muted">{{ data_get($setupPreview, 'llm.provider') }} · {{ data_get($setupPreview, 'llm.model') }}</p>
                        </div>
                        <form method="POST" action="{{ route($routeBase.'.setup.apply') }}">
                            @csrf
                            <input type="hidden" name="payload" value="{{ json_encode($previewPayload) }}">
                            @foreach ($previewSections as $sectionKey)
                                <input type="hidden" name="sections[]" value="{{ $sectionKey }}">
                            @endforeach
                            <x-ui.button type="submit" size="sm">Apply to Argusly</x-ui.button>
                        </form>
                    </div>

                    <div class="mt-5 grid gap-4 lg:grid-cols-2">
                        <div class="rounded-md border border-line bg-panel p-4">
                            <p class="text-sm font-semibold text-ink">{{ $profilePreview['official_name'] ?? $brand->name }}</p>
                            <p class="mt-2 text-sm leading-6 text-muted">{{ $profilePreview['short_description'] ?? 'No profile generated.' }}</p>
                            @if (! empty($profilePreview['tone_of_voice']))
                                <p class="mt-3 text-xs font-semibold uppercase tracking-[0.1em] text-muted">Tone</p>
                                <p class="mt-1 text-sm leading-6 text-muted">{{ $profilePreview['tone_of_voice'] }}</p>
                            @endif
                        </div>
                        <div class="grid gap-3 sm:grid-cols-3">
                            <div class="rounded-md border border-line bg-panel p-4">
                                <p class="text-2xl font-semibold text-ink">{{ count($setupPreview['brand_voices'] ?? []) }}</p>
                                <p class="mt-1 text-xs text-muted">Brand voices</p>
                            </div>
                            <div class="rounded-md border border-line bg-panel p-4">
                                <p class="text-2xl font-semibold text-ink">{{ count($setupPreview['buyer_personas'] ?? []) }}</p>
                                <p class="mt-1 text-xs text-muted">Buyer personas</p>
                            </div>
                            <div class="rounded-md border border-line bg-panel p-4">
                                <p class="text-2xl font-semibold text-ink">{{ count($setupPreview['team_personas'] ?? []) }}</p>
                                <p class="mt-1 text-xs text-muted">Team personas</p>
                            </div>
                        </div>
                    </div>
                </div>
            @endif
        </x-dashboard.section>

        <div class="grid gap-6 xl:grid-cols-[1.15fr_0.85fr]">
            <x-dashboard.section title="Brand profile" description="Define how this brand should be understood and represented across channels and systems.">
                <form method="POST" action="{{ route($routeBase.'.profile.update') }}" class="grid gap-4 md:grid-cols-2">
                    @csrf
                    @method('PATCH')
                    @php $profile = $center['profile']; @endphp

                    <label class="block">
                        <span class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">Official name</span>
                        <input name="official_name" value="{{ old('official_name', $profile->official_name) }}" required class="mt-2 w-full rounded-md border border-line bg-white px-3 py-2 text-sm text-ink">
                    </label>
                    <label class="block">
                        <span class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">Tagline</span>
                        <input name="tagline" value="{{ old('tagline', $profile->tagline) }}" class="mt-2 w-full rounded-md border border-line bg-white px-3 py-2 text-sm text-ink">
                    </label>
                    <label class="block md:col-span-2">
                        <span class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">Website</span>
                        <input name="website" value="{{ old('website', $profile->website) }}" placeholder="https://example.com" class="mt-2 w-full rounded-md border border-line bg-white px-3 py-2 text-sm text-ink">
                    </label>
                    @foreach ([
                        'short_description' => ['Short description', 3],
                        'long_description' => ['Long description', 5],
                        'mission' => ['Mission', 3],
                        'vision' => ['Vision', 3],
                        'positioning' => ['Positioning', 3],
                        'value_proposition' => ['Value proposition', 3],
                        'tone_of_voice' => ['Tone of voice', 3],
                        'primary_audience' => ['Primary audience', 3],
                        'secondary_audience' => ['Secondary audience', 3],
                    ] as $field => [$label, $rows])
                        <label class="block md:col-span-2">
                            <span class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">{{ $label }}</span>
                            <textarea name="{{ $field }}" rows="{{ $rows }}" class="mt-2 w-full rounded-md border border-line bg-white px-3 py-2 text-sm text-ink">{{ old($field, $profile->{$field}) }}</textarea>
                        </label>
                    @endforeach
                    <div class="md:col-span-2">
                        <x-ui.button type="submit">Save brand profile</x-ui.button>
                    </div>
                </form>
            </x-dashboard.section>

            <div class="space-y-6">
                <x-dashboard.section title="Brand Profile Completeness" description="Readiness of the current brand profile for downstream intelligence and generation.">
                    <div class="rounded-md border border-line bg-panel p-5">
                        <div class="flex items-end justify-between gap-4">
                            <div>
                                <p class="text-4xl font-semibold tracking-tight text-ink">{{ $center['completeness']['percentage'] }}%</p>
                                <p class="mt-1 text-sm text-muted">{{ $center['completeness']['completed'] }} of {{ $center['completeness']['total'] }} fields complete</p>
                            </div>
                            <x-ui.badge variant="{{ $center['completeness']['percentage'] >= 80 ? 'success' : 'blue' }}">{{ $center['completeness']['percentage'] >= 80 ? 'Ready' : 'Needs input' }}</x-ui.badge>
                        </div>
                        <div class="mt-5 h-2 overflow-hidden rounded-full bg-white">
                            <div class="h-full bg-blue" style="width: {{ $center['completeness']['percentage'] }}%"></div>
                        </div>
                    </div>

                    @if ($center['completeness']['recommendations'])
                        <div class="mt-5 space-y-3">
                            @foreach ($center['completeness']['recommendations'] as $recommendation)
                                <div class="rounded-md border border-line bg-white p-4 text-sm leading-6 text-muted">{{ $recommendation }}</div>
                            @endforeach
                        </div>
                    @else
                        <x-dashboard.empty-state title="Profile complete" message="This profile is ready to support AI visibility, content generation and recommendation workflows." />
                    @endif
                </x-dashboard.section>

                <x-dashboard.section title="Prepared for">
                    <div class="grid gap-3 sm:grid-cols-2">
                        @foreach ($center['futureUseCases'] as $useCase)
                            <div class="rounded-md border border-line bg-panel p-4">
                                <p class="text-sm font-semibold text-ink">{{ $useCase['label'] }}</p>
                                <p class="mt-1 text-xs text-muted">{{ str($useCase['status'])->headline() }}</p>
                            </div>
                        @endforeach
                    </div>
                </x-dashboard.section>
            </div>
        </div>

        <div class="mt-6 grid gap-6 xl:grid-cols-3">
            <x-dashboard.section title="Products" description="Products the brand offers.">
                <form method="POST" action="{{ route($routeBase.'.products.store') }}" class="space-y-4">
                    @csrf
                    <input name="name" value="{{ old('name') }}" placeholder="Product name" required class="w-full rounded-md border border-line bg-white px-3 py-2 text-sm text-ink">
                    <textarea name="description" rows="3" placeholder="Description" class="w-full rounded-md border border-line bg-white px-3 py-2 text-sm text-ink">{{ old('description') }}</textarea>
                    <input name="category" value="{{ old('category') }}" placeholder="Category" class="w-full rounded-md border border-line bg-white px-3 py-2 text-sm text-ink">
                    <input name="website" value="{{ old('website') }}" placeholder="https://example.com/product" class="w-full rounded-md border border-line bg-white px-3 py-2 text-sm text-ink">
                    <select name="status" class="w-full rounded-md border border-line bg-white px-3 py-2 text-sm text-ink">
                        @foreach ($statuses as $status)
                            <option value="{{ $status }}">{{ str($status)->headline() }}</option>
                        @endforeach
                    </select>
                    <x-ui.button type="submit" size="sm">Add product</x-ui.button>
                </form>
                <div class="mt-5 space-y-3">
                    @forelse ($center['products'] as $product)
                        <div class="rounded-md border border-line bg-panel p-4">
                            <div class="flex items-start justify-between gap-3">
                                <p class="text-sm font-semibold text-ink">{{ $product->name }}</p>
                                <x-ui.badge>{{ str($product->status)->headline() }}</x-ui.badge>
                            </div>
                            <p class="mt-2 text-xs leading-5 text-muted">{{ $product->description ?: 'No description yet.' }}</p>
                        </div>
                    @empty
                        <x-dashboard.empty-state title="No products" message="Add products to help generation and recommendation systems understand the offer." />
                    @endforelse
                </div>
            </x-dashboard.section>

            <x-dashboard.section title="Services" description="Services the brand provides.">
                <form method="POST" action="{{ route($routeBase.'.services.store') }}" class="space-y-4">
                    @csrf
                    <input name="name" value="{{ old('name') }}" placeholder="Service name" required class="w-full rounded-md border border-line bg-white px-3 py-2 text-sm text-ink">
                    <textarea name="description" rows="3" placeholder="Description" class="w-full rounded-md border border-line bg-white px-3 py-2 text-sm text-ink">{{ old('description') }}</textarea>
                    <input name="category" value="{{ old('category') }}" placeholder="Category" class="w-full rounded-md border border-line bg-white px-3 py-2 text-sm text-ink">
                    <select name="status" class="w-full rounded-md border border-line bg-white px-3 py-2 text-sm text-ink">
                        @foreach ($statuses as $status)
                            <option value="{{ $status }}">{{ str($status)->headline() }}</option>
                        @endforeach
                    </select>
                    <x-ui.button type="submit" size="sm">Add service</x-ui.button>
                </form>
                <div class="mt-5 space-y-3">
                    @forelse ($center['services'] as $service)
                        <div class="rounded-md border border-line bg-panel p-4">
                            <div class="flex items-start justify-between gap-3">
                                <p class="text-sm font-semibold text-ink">{{ $service->name }}</p>
                                <x-ui.badge>{{ str($service->status)->headline() }}</x-ui.badge>
                            </div>
                            <p class="mt-2 text-xs leading-5 text-muted">{{ $service->description ?: 'No description yet.' }}</p>
                        </div>
                    @empty
                        <x-dashboard.empty-state title="No services" message="Add services to support creator matching, content and relationship intelligence." />
                    @endforelse
                </div>
            </x-dashboard.section>

            <x-dashboard.section title="Narratives" description="Core stories and claims that should guide representation.">
                <form method="POST" action="{{ route($routeBase.'.narratives.store') }}" class="space-y-4">
                    @csrf
                    <input name="title" value="{{ old('title') }}" placeholder="Narrative title" required class="w-full rounded-md border border-line bg-white px-3 py-2 text-sm text-ink">
                    <textarea name="description" rows="3" placeholder="Narrative description" required class="w-full rounded-md border border-line bg-white px-3 py-2 text-sm text-ink">{{ old('description') }}</textarea>
                    <select name="importance" class="w-full rounded-md border border-line bg-white px-3 py-2 text-sm text-ink">
                        @foreach ($importanceLevels as $importance)
                            <option value="{{ $importance }}">{{ str($importance)->headline() }}</option>
                        @endforeach
                    </select>
                    <select name="status" class="w-full rounded-md border border-line bg-white px-3 py-2 text-sm text-ink">
                        @foreach ($statuses as $status)
                            <option value="{{ $status }}">{{ str($status)->headline() }}</option>
                        @endforeach
                    </select>
                    <x-ui.button type="submit" size="sm">Add narrative</x-ui.button>
                </form>
                <div class="mt-5 space-y-3">
                    @forelse ($center['narratives'] as $narrative)
                        <div class="rounded-md border border-line bg-panel p-4">
                            <div class="flex items-start justify-between gap-3">
                                <p class="text-sm font-semibold text-ink">{{ $narrative->title }}</p>
                                <x-ui.badge variant="{{ $narrative->importance === 'critical' || $narrative->importance === 'high' ? 'blue' : 'default' }}">{{ str($narrative->importance)->headline() }}</x-ui.badge>
                            </div>
                            <p class="mt-2 text-xs leading-5 text-muted">{{ $narrative->description }}</p>
                        </div>
                    @empty
                        <x-dashboard.empty-state title="No narratives" message="Add narratives to prepare for narrative intelligence and campaign planning." />
                    @endforelse
                </div>
            </x-dashboard.section>
        </div>
    @endif
</x-app.settings.layout>
