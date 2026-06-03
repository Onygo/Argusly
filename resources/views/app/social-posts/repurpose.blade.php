<x-app.layout title="Create social post | Argusly">
    <div class="mx-auto max-w-4xl">
        <div class="flex flex-col justify-between gap-4 sm:flex-row sm:items-end">
            <div>
                <p class="eyebrow">Social repurposing</p>
                <h1 class="mt-2 text-3xl font-semibold tracking-tight text-ink">Create social post</h1>
                <p class="mt-2 text-sm text-muted">Generate three social variants from {{ $asset->title }}.</p>
            </div>
            <x-ui.button href="{{ route('app.content.show', $asset) }}" variant="secondary">Back</x-ui.button>
        </div>

        <x-ui.card class="mt-8 p-6">
            <form method="POST" action="{{ route('app.content.social-posts.repurpose.store', $asset) }}" class="space-y-5">
                @csrf

                <label class="block">
                    <span class="text-sm font-semibold text-ink">Social profile</span>
                    <select name="social_profile_id" class="mt-2 w-full rounded-md border border-line bg-white px-3 py-2 text-sm text-ink" required>
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
                    <span class="text-sm font-semibold text-ink">Language</span>
                    <select name="language" class="mt-2 w-full rounded-md border border-line bg-white px-3 py-2 text-sm text-ink">
                        @foreach ($contentLanguages as $language)
                            <option value="{{ $language->code }}" @selected(old('language', $asset->language) === $language->code)>{{ $language->name }}</option>
                        @endforeach
                    </select>
                    @error('language')
                        <span class="mt-2 block text-sm font-medium text-red-600">{{ $message }}</span>
                    @enderror
                </label>

                <div class="rounded-md border border-line bg-panel p-4">
                    <p class="text-sm font-semibold text-ink">{{ $asset->title }}</p>
                    <p class="mt-2 text-sm leading-6 text-muted">{{ $asset->excerpt ?: str($asset->body)->limit(220) }}</p>
                </div>

                <div class="flex justify-end">
                    <x-ui.button type="submit">Generate variants · {{ config('credits.costs.social_repurpose') }}</x-ui.button>
                </div>
            </form>
        </x-ui.card>
    </div>
</x-app.layout>
