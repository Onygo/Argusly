<x-app.layout :title="__('content.create_content').' | Argusly'">
    <div class="w-full">
        <div>
            <p class="eyebrow">Argusly Content Engine</p>
            <h1 class="mt-2 text-3xl font-semibold tracking-tight text-ink sm:text-4xl">{{ __('content.create_content') }}</h1>
            <p class="mt-2 max-w-2xl text-sm leading-6 text-muted">Create a first draft from keywords and reusable brand knowledge, or switch to a manual asset when you need a blank workspace.</p>
        </div>

        @php
            $profile = $brandContext['profile'] ?? null;
            $contextSources = collect([
                filled($profile?->short_description) || filled($profile?->long_description) ? 'Company profile' : null,
                filled($profile?->tone_of_voice) ? 'Tone of voice' : null,
                ($brandContext['products'] ?? collect())->isNotEmpty() ? 'Products' : null,
                ($brandContext['services'] ?? collect())->isNotEmpty() ? 'Services' : null,
                ($brandContext['narratives'] ?? collect())->isNotEmpty() ? 'Narratives' : null,
            ])->filter();
        @endphp

        <div class="mt-8 grid gap-5 xl:grid-cols-[1.1fr_0.9fr]">
            <x-ui.card class="p-5">
                <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                    <div>
                        <h2 class="text-base font-semibold text-ink">Generate first draft</h2>
                        <p class="mt-1 text-sm leading-6 text-muted">Start from a keyword, content angle and brand context.</p>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        @forelse ($contextSources as $source)
                            <x-ui.badge variant="success">{{ $source }}</x-ui.badge>
                        @empty
                            <x-ui.badge>Brand context incomplete</x-ui.badge>
                        @endforelse
                    </div>
                </div>

                <form method="POST" action="{{ route('app.content.store') }}" class="mt-5 space-y-4">
                    @csrf
                    <input type="hidden" name="creation_mode" value="guided_first_draft">

                    <div class="grid gap-3 sm:grid-cols-2">
                        <label class="block">
                            <span class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">Mode</span>
                            <select name="draft_mode" class="mt-2 w-full rounded-md border border-line bg-white px-3 py-2 text-sm text-ink">
                                <option value="single" @selected(old('draft_mode', 'single') === 'single')>Single draft</option>
                                <option value="chain" @selected(old('draft_mode') === 'chain')>Chained content</option>
                            </select>
                            @error('draft_mode') <span class="mt-1 block text-xs text-red-600">{{ $message }}</span> @enderror
                        </label>

                        <label class="block">
                            <span class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">Number of drafts</span>
                            <input type="number" min="1" max="6" name="chain_count" value="{{ old('chain_count', old('draft_mode') === 'chain' ? 3 : 1) }}" class="mt-2 w-full rounded-md border border-line bg-white px-3 py-2 text-sm text-ink">
                            <span class="mt-1 block text-xs text-muted">Use 1 for a single draft. Chained content starts at 2.</span>
                            @error('chain_count') <span class="mt-1 block text-xs text-red-600">{{ $message }}</span> @enderror
                        </label>
                    </div>

                    <label class="block">
                        <span class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">Title</span>
                        <input name="title" value="{{ old('title') }}" class="mt-2 w-full rounded-md border border-line bg-white px-3 py-2 text-sm text-ink" placeholder="Optional when primary keyword is enough">
                        @error('title') <span class="mt-1 block text-xs text-red-600">{{ $message }}</span> @enderror
                    </label>

                    <div class="grid gap-3 sm:grid-cols-2">
                        <label class="block">
                            <span class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">Primary keyword</span>
                            <input name="primary_keyword" value="{{ old('primary_keyword') }}" class="mt-2 w-full rounded-md border border-line bg-white px-3 py-2 text-sm text-ink">
                            @error('primary_keyword') <span class="mt-1 block text-xs text-red-600">{{ $message }}</span> @enderror
                        </label>

                        <label class="block">
                            <span class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">Secondary keywords</span>
                            <input name="secondary_keywords" value="{{ old('secondary_keywords') }}" class="mt-2 w-full rounded-md border border-line bg-white px-3 py-2 text-sm text-ink" placeholder="Comma separated">
                            @error('secondary_keywords') <span class="mt-1 block text-xs text-red-600">{{ $message }}</span> @enderror
                        </label>
                    </div>

                    <div class="grid gap-3 sm:grid-cols-2">
                        <label class="block">
                            <span class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">Audience or persona</span>
                            <input name="audience" value="{{ old('audience', $profile?->primary_audience) }}" class="mt-2 w-full rounded-md border border-line bg-white px-3 py-2 text-sm text-ink">
                            @error('audience') <span class="mt-1 block text-xs text-red-600">{{ $message }}</span> @enderror
                        </label>

                        <label class="block">
                            <span class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">Type</span>
                            <select name="type" class="mt-2 w-full rounded-md border border-line bg-white px-3 py-2 text-sm text-ink">
                                @foreach (['article', 'page', 'landing_page', 'faq'] as $type)
                                    <option value="{{ $type }}" @selected(old('type', 'article') === $type)>{{ str($type)->replace('_', ' ')->headline() }}</option>
                                @endforeach
                            </select>
                            @error('type') <span class="mt-1 block text-xs text-red-600">{{ $message }}</span> @enderror
                        </label>
                    </div>

                    <label class="block">
                        <span class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">Angle</span>
                        <textarea name="angle" rows="3" class="mt-2 w-full rounded-md border border-line bg-white px-3 py-2 text-sm text-ink" placeholder="Point of view, offer, objections, intent or content brief">{{ old('angle') }}</textarea>
                        @error('angle') <span class="mt-1 block text-xs text-red-600">{{ $message }}</span> @enderror
                    </label>

                    <label class="block">
                        <span class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">Content language</span>
                        <select name="language" class="mt-2 w-full rounded-md border border-line bg-white px-3 py-2 text-sm text-ink">
                            @foreach ($contentLanguages as $language)
                                <option value="{{ $language->code }}" @selected(old('language', $asset->language) === $language->code)>{{ $language->name }} · {{ $language->native_name }}</option>
                            @endforeach
                        </select>
                        @error('language') <span class="mt-1 block text-xs text-red-600">{{ $message }}</span> @enderror
                    </label>

                    <div class="flex flex-wrap items-center justify-end gap-2">
                        <x-ui.button type="submit">Create first draft</x-ui.button>
                    </div>
                </form>
            </x-ui.card>

            <x-ui.card class="p-5">
                <h2 class="text-base font-semibold text-ink">Context used</h2>
                <div class="mt-4 space-y-4 text-sm leading-6 text-muted">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">Company profile</p>
                        <p class="mt-1 text-ink">{{ $profile?->short_description ?: $profile?->long_description ?: 'No approved company summary yet.' }}</p>
                    </div>
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">Tone of voice</p>
                        <p class="mt-1 text-ink">{{ $profile?->tone_of_voice ?: 'No tone of voice set yet.' }}</p>
                    </div>
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">Narratives</p>
                        <div class="mt-2 flex flex-wrap gap-2">
                            @forelse (($brandContext['narratives'] ?? collect())->take(4) as $narrative)
                                <x-ui.badge>{{ $narrative->title }}</x-ui.badge>
                            @empty
                                <span>No narratives yet.</span>
                            @endforelse
                        </div>
                    </div>
                </div>
            </x-ui.card>
        </div>

        <details class="mt-6 rounded-md border border-line bg-panel p-5">
            <summary class="cursor-pointer text-sm font-semibold text-ink">Create manual asset</summary>
            <form method="POST" action="{{ route('app.content.store') }}" class="mt-5">
                @include('app.content._form')
            </form>
        </details>
    </div>
</x-app.layout>
