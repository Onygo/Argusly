@props(['section'])

<div class="rounded-md border border-line bg-white p-4">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <div class="flex flex-wrap items-center gap-2">
                <x-ui.badge>{{ str($section->type)->headline() }}</x-ui.badge>
                @if ($section->contentAsset)
                    <x-ui.badge>Content asset</x-ui.badge>
                @endif
            </div>
            <p class="mt-2 text-sm font-semibold text-ink">{{ $section->title ?: $section->contentAsset?->title ?: 'Untitled section' }}</p>
        </div>
        <label class="w-24">
            <span class="text-xs font-semibold uppercase tracking-[0.12em] text-muted">Position</span>
            <input name="positions[{{ $section->id }}]" type="number" min="0" value="{{ $section->position }}" class="mt-1 w-full rounded-md border border-line bg-panel px-3 py-2 text-sm text-ink">
        </label>
    </div>
    <p class="mt-3 whitespace-pre-line text-sm leading-6 text-muted">{{ $section->body ?: $section->contentAsset?->excerpt ?: $section->contentAsset?->title ?: 'No body yet.' }}</p>
</div>
