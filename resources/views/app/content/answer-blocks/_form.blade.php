@csrf

@if ($answerBlock->exists)
    @method('PUT')
@endif

@if ($contentAsset)
    <input type="hidden" name="content_asset_id" value="{{ $contentAsset->id }}">
@endif

<div class="grid gap-5 lg:grid-cols-[1.2fr_0.8fr]">
    <x-ui.card class="p-5">
        <div class="space-y-4">
            <label class="block">
                <span class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">Question</span>
                <textarea name="question" rows="3" class="mt-2 w-full rounded-md border border-line bg-white px-3 py-2 text-sm text-ink" required>{{ old('question', $answerBlock->question) }}</textarea>
                @error('question') <span class="mt-1 block text-xs text-red-600">{{ $message }}</span> @enderror
            </label>

            <label class="block">
                <span class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">Answer</span>
                <textarea name="answer" rows="10" class="mt-2 w-full rounded-md border border-line bg-white px-3 py-2 text-sm leading-6 text-ink" required>{{ old('answer', $answerBlock->answer) }}</textarea>
                @error('answer') <span class="mt-1 block text-xs text-red-600">{{ $message }}</span> @enderror
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
                            <option value="{{ $type }}" @selected(old('type', $answerBlock->type) === $type)>{{ str($type)->replace('_', ' ')->headline() }}</option>
                        @endforeach
                    </select>
                    @error('type') <span class="mt-1 block text-xs text-red-600">{{ $message }}</span> @enderror
                </label>

                <label class="block">
                    <span class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">Status</span>
                    <select name="status" class="mt-2 w-full rounded-md border border-line bg-white px-3 py-2 text-sm text-ink">
                        @foreach (\App\Models\AnswerBlock::STATUSES as $status)
                            <option value="{{ $status }}" @selected(old('status', $answerBlock->status ?: 'draft') === $status)>{{ str($status)->headline() }}</option>
                        @endforeach
                    </select>
                    @error('status') <span class="mt-1 block text-xs text-red-600">{{ $message }}</span> @enderror
                </label>

                <div class="grid grid-cols-2 gap-3">
                    <label class="block">
                        <span class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">Content language</span>
                        <select name="language" class="mt-2 w-full rounded-md border border-line bg-white px-3 py-2 text-sm text-ink" required>
                            @foreach ($contentLanguages as $language)
                                <option value="{{ $language->code }}" @selected(old('language', $answerBlock->language) === $language->code)>{{ $language->name }} · {{ $language->native_name }}</option>
                            @endforeach
                        </select>
                        @error('language') <span class="mt-1 block text-xs text-red-600">{{ $message }}</span> @enderror
                    </label>

                    <label class="block">
                        <span class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">Position</span>
                        <input name="position" type="number" min="0" value="{{ old('position', $answerBlock->position) }}" class="mt-2 w-full rounded-md border border-line bg-white px-3 py-2 text-sm text-ink">
                        @error('position') <span class="mt-1 block text-xs text-red-600">{{ $message }}</span> @enderror
                    </label>
                </div>
            </div>
        </x-ui.card>

        <x-ui.card class="p-5">
            <h2 class="text-sm font-semibold text-ink">Content asset</h2>
            <p class="mt-2 text-sm leading-6 text-muted">{{ $contentAsset?->title ?? $answerBlock->contentAsset?->title ?? 'Standalone answer block' }}</p>
        </x-ui.card>
    </div>
</div>

<div class="mt-5 flex flex-wrap items-center justify-end gap-2">
    <x-ui.button href="{{ $answerBlock->exists ? route('app.content.answer-blocks.show', $answerBlock) : route('app.content.answer-blocks.index') }}" variant="secondary">Cancel</x-ui.button>
    <x-ui.button type="submit">{{ $answerBlock->exists ? 'Save changes' : 'Create answer block' }}</x-ui.button>
</div>
