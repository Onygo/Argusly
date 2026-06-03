@props(['newsletter'])

<div class="overflow-hidden rounded-md border border-line bg-white">
    <div class="border-b border-line bg-panel px-5 py-4">
        <p class="text-xs font-semibold uppercase tracking-[0.12em] text-muted">Preview · {{ strtoupper($newsletter->language) }}</p>
        <h2 class="mt-2 text-xl font-semibold text-ink">{{ $newsletter->subject ?: $newsletter->title }}</h2>
        <p class="mt-2 text-sm leading-6 text-muted">{{ $newsletter->preheader ?: 'No preheader set.' }}</p>
    </div>
    <div class="space-y-4 p-5">
        @forelse ($newsletter->sections as $section)
            <section class="border-b border-line pb-4 last:border-b-0 last:pb-0">
                <p class="text-xs font-semibold uppercase tracking-[0.12em] text-muted">{{ str($section->type)->headline() }}</p>
                <h3 class="mt-2 text-base font-semibold text-ink">{{ $section->title ?: $section->contentAsset?->title ?: 'Untitled section' }}</h3>
                <p class="mt-2 whitespace-pre-line text-sm leading-6 text-muted">{{ $section->body ?: $section->contentAsset?->excerpt ?: $section->contentAsset?->body ?: 'No preview content yet.' }}</p>
            </section>
        @empty
            <p class="text-sm text-muted">No sections yet.</p>
        @endforelse
    </div>
</div>
