@csrf

@if ($asset->exists)
    @method('PUT')
@endif

<div class="grid gap-5 lg:grid-cols-[1.2fr_0.8fr]">
    <x-ui.card class="p-5">
        <div class="space-y-4">
            <label class="block">
                <span class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">Title</span>
                <input name="title" value="{{ old('title', $asset->title) }}" class="mt-2 w-full rounded-md border border-line bg-white px-3 py-2 text-sm text-ink" required>
                @error('title') <span class="mt-1 block text-xs text-red-600">{{ $message }}</span> @enderror
            </label>

            <label class="block">
                <span class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">Slug</span>
                <input name="slug" value="{{ old('slug', $asset->slug) }}" class="mt-2 w-full rounded-md border border-line bg-white px-3 py-2 text-sm text-ink">
                @error('slug') <span class="mt-1 block text-xs text-red-600">{{ $message }}</span> @enderror
            </label>

            <label class="block">
                <span class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">Excerpt</span>
                <textarea name="excerpt" rows="3" class="mt-2 w-full rounded-md border border-line bg-white px-3 py-2 text-sm text-ink">{{ old('excerpt', $asset->excerpt) }}</textarea>
                @error('excerpt') <span class="mt-1 block text-xs text-red-600">{{ $message }}</span> @enderror
            </label>

            <label class="block">
                <span class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">Body</span>
                <textarea name="body" rows="12" class="mt-2 w-full rounded-md border border-line bg-white px-3 py-2 text-sm leading-6 text-ink">{{ old('body', $asset->body) }}</textarea>
                @error('body') <span class="mt-1 block text-xs text-red-600">{{ $message }}</span> @enderror
            </label>
        </div>
    </x-ui.card>

    <div class="space-y-5">
        <x-ui.card class="p-5">
            <div class="space-y-4">
                <label class="block">
                    <span class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">Type</span>
                    <select name="type" class="mt-2 w-full rounded-md border border-line bg-white px-3 py-2 text-sm text-ink" required>
                        @foreach ($types as $type)
                            <option value="{{ $type }}" @selected(old('type', $asset->type) === $type)>{{ str($type)->replace('_', ' ')->headline() }}</option>
                        @endforeach
                    </select>
                    @error('type') <span class="mt-1 block text-xs text-red-600">{{ $message }}</span> @enderror
                </label>

                <input type="hidden" name="status" value="{{ old('status', $asset->status ?: 'draft') }}">

                <div class="grid grid-cols-2 gap-3">
                    <label class="block">
                        <span class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">Content language</span>
                        <select name="language" class="mt-2 w-full rounded-md border border-line bg-white px-3 py-2 text-sm text-ink" required>
                            @foreach ($contentLanguages as $language)
                                <option value="{{ $language->code }}" @selected(old('language', $asset->language) === $language->code)>{{ $language->name }} · {{ $language->native_name }}</option>
                            @endforeach
                        </select>
                        @error('language') <span class="mt-1 block text-xs text-red-600">{{ $message }}</span> @enderror
                    </label>

                    <label class="block">
                        <span class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">Locale</span>
                        <input name="locale" value="{{ old('locale', $asset->locale ?: 'en_US') }}" class="mt-2 w-full rounded-md border border-line bg-white px-3 py-2 text-sm text-ink" required>
                        @error('locale') <span class="mt-1 block text-xs text-red-600">{{ $message }}</span> @enderror
                    </label>
                </div>

                <label class="block">
                    <span class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">Source</span>
                    <input name="source" value="{{ old('source', $asset->source ?: 'manual') }}" class="mt-2 w-full rounded-md border border-line bg-white px-3 py-2 text-sm text-ink" required>
                    @error('source') <span class="mt-1 block text-xs text-red-600">{{ $message }}</span> @enderror
                </label>
            </div>
        </x-ui.card>

        <x-ui.card class="p-5">
            <div class="space-y-4">
                <label class="block">
                    <span class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">Source URL</span>
                    <input name="source_url" value="{{ old('source_url', $asset->source_url) }}" class="mt-2 w-full rounded-md border border-line bg-white px-3 py-2 text-sm text-ink">
                    @error('source_url') <span class="mt-1 block text-xs text-red-600">{{ $message }}</span> @enderror
                </label>

                <label class="block">
                    <span class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">Canonical URL</span>
                    <input name="canonical_url" value="{{ old('canonical_url', $asset->canonical_url) }}" class="mt-2 w-full rounded-md border border-line bg-white px-3 py-2 text-sm text-ink">
                    @error('canonical_url') <span class="mt-1 block text-xs text-red-600">{{ $message }}</span> @enderror
                </label>
            </div>
        </x-ui.card>
    </div>
</div>

<div class="mt-5 flex flex-wrap items-center justify-end gap-2">
    <x-ui.button href="{{ $asset->exists ? route('app.content.show', $asset) : route('app.content.index') }}" variant="secondary">Cancel</x-ui.button>
    <x-ui.button type="submit">{{ $asset->exists ? 'Save changes' : 'Create asset' }}</x-ui.button>
</div>
