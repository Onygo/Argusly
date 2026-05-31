<x-app.layout title="Create social post | Argusly">
    <div class="mx-auto max-w-4xl">
        <div class="flex flex-col justify-between gap-4 sm:flex-row sm:items-end">
            <div>
                <p class="eyebrow">Social publishing</p>
                <h1 class="mt-2 text-3xl font-semibold tracking-tight text-ink">Create social post</h1>
                <p class="mt-2 text-sm text-muted">{{ $asset ? 'Prepared from '.$asset->title : 'Draft a post for an accessible social profile.' }}</p>
            </div>
            <x-ui.button href="{{ route('app.social-posts.index') }}" variant="secondary">Back</x-ui.button>
        </div>

        <x-ui.card class="mt-8 p-6">
            <form method="POST" action="{{ route('app.social-posts.store') }}" class="space-y-5">
                @csrf
                @if ($asset)
                    <input type="hidden" name="content_asset_id" value="{{ $asset->id }}">
                @endif

                <label class="block">
                    <span class="text-sm font-semibold text-ink">Social profile</span>
                    <select name="social_profile_id" class="mt-2 w-full rounded-lg border border-line bg-white px-3 py-2 text-sm text-ink" required>
                        <option value="">Select a profile</option>
                        @foreach ($profiles as $profile)
                            <option value="{{ $profile->id }}" @selected((string) old('social_profile_id') === (string) $profile->id)>{{ $profile->display_name }} · {{ str($profile->provider)->headline() }}</option>
                        @endforeach
                    </select>
                    @error('social_profile_id')
                        <span class="mt-2 block text-sm font-medium text-red-600">{{ $message }}</span>
                    @enderror
                </label>

                <label class="block">
                    <span class="text-sm font-semibold text-ink">Post text</span>
                    <textarea name="post_text" rows="8" class="mt-2 w-full rounded-lg border border-line bg-white px-3 py-2 text-sm leading-6 text-ink" required>{{ old('post_text', $asset?->excerpt ?: $asset?->title) }}</textarea>
                    @error('post_text')
                        <span class="mt-2 block text-sm font-medium text-red-600">{{ $message }}</span>
                    @enderror
                </label>

                <div class="grid gap-4 sm:grid-cols-3">
                    <label>
                        <span class="text-sm font-semibold text-ink">Language</span>
                        <select name="language" class="mt-2 w-full rounded-lg border border-line bg-white px-3 py-2 text-sm text-ink">
                            @foreach ($contentLanguages as $language)
                                <option value="{{ $language->code }}" @selected(old('language', $asset?->language ?? $brand->default_content_language) === $language->code)>{{ $language->name }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label>
                        <span class="text-sm font-semibold text-ink">Status</span>
                        <select name="status" class="mt-2 w-full rounded-lg border border-line bg-white px-3 py-2 text-sm text-ink">
                            <option value="draft" @selected(old('status') === 'draft')>Draft</option>
                            <option value="review" @selected(old('status') === 'review')>Review</option>
                        </select>
                    </label>
                    <label>
                        <span class="text-sm font-semibold text-ink">Market</span>
                        <input name="market" value="{{ old('market', $brand->market) }}" class="mt-2 w-full rounded-lg border border-line bg-white px-3 py-2 text-sm text-ink">
                    </label>
                </div>

                <div class="flex flex-wrap justify-end gap-2">
                    <x-ui.button type="submit">Prepare post</x-ui.button>
                </div>
            </form>
        </x-ui.card>
    </div>
</x-app.layout>
