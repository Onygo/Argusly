<x-app.layout title="{{ $newsletter->title }} | Argusly">
    <div class="mx-auto max-w-6xl">
        <div class="flex flex-col justify-between gap-4 lg:flex-row lg:items-end">
            <div>
                <p class="eyebrow">Newsletter</p>
                <h1 class="mt-2 text-3xl font-semibold tracking-tight text-ink sm:text-4xl">{{ $newsletter->title }}</h1>
                <div class="mt-3 flex flex-wrap gap-2">
                    <x-ui.badge variant="{{ in_array($newsletter->status, ['approved', 'sent'], true) ? 'success' : (in_array($newsletter->status, ['review', 'scheduled', 'sending'], true) ? 'blue' : 'default') }}">{{ str($newsletter->status)->headline() }}</x-ui.badge>
                    <x-ui.badge>{{ strtoupper($newsletter->language) }}</x-ui.badge>
                    <x-ui.badge>{{ $newsletter->campaign?->name ?? 'No campaign' }}</x-ui.badge>
                </div>
            </div>
            <x-ui.button href="{{ route('app.newsletters') }}" variant="secondary">Newsletters</x-ui.button>
        </div>

        @if (session('status'))
            <div class="mt-6 rounded-lg border border-line bg-white p-4 text-sm font-medium text-ink">{{ session('status') }}</div>
        @endif

        <div class="mt-8 grid gap-6 lg:grid-cols-[0.9fr_1.1fr]">
            <x-dashboard.section title="Builder settings" description="Edit the language-aware envelope and keep this edition in draft or review.">
                <form method="POST" action="{{ route('app.newsletters.update', $newsletter) }}" class="space-y-4">
                    @csrf
                    @method('PUT')
                    <label class="block">
                        <span class="text-xs font-semibold uppercase tracking-[0.12em] text-muted">Title</span>
                        <input name="title" value="{{ old('title', $newsletter->title) }}" required class="mt-1 w-full rounded-lg border border-line bg-white px-3 py-2 text-sm text-ink">
                    </label>
                    <label class="block">
                        <span class="text-xs font-semibold uppercase tracking-[0.12em] text-muted">Subject</span>
                        <input name="subject" value="{{ old('subject', $newsletter->subject) }}" class="mt-1 w-full rounded-lg border border-line bg-white px-3 py-2 text-sm text-ink" placeholder="Subject line">
                    </label>
                    <label class="block">
                        <span class="text-xs font-semibold uppercase tracking-[0.12em] text-muted">Preheader</span>
                        <input name="preheader" value="{{ old('preheader', $newsletter->preheader) }}" class="mt-1 w-full rounded-lg border border-line bg-white px-3 py-2 text-sm text-ink" placeholder="Inbox preview text">
                    </label>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <label class="block">
                            <span class="text-xs font-semibold uppercase tracking-[0.12em] text-muted">Language</span>
                            <select name="language" class="mt-1 w-full rounded-lg border border-line bg-white px-3 py-2 text-sm text-ink">
                                @foreach ($languages as $language)
                                    <option value="{{ $language->code }}" @selected($newsletter->language === $language->code)>{{ $language->name }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label class="block">
                            <span class="text-xs font-semibold uppercase tracking-[0.12em] text-muted">Status</span>
                            <select name="status" class="mt-1 w-full rounded-lg border border-line bg-white px-3 py-2 text-sm text-ink">
                                @foreach (\App\Models\Newsletter::STATUSES as $status)
                                    <option value="{{ $status }}" @selected($newsletter->status === $status)>{{ str($status)->headline() }}</option>
                                @endforeach
                            </select>
                        </label>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <x-ui.button type="submit">Save changes</x-ui.button>
                        <x-ui.button type="submit" form="save-newsletter-draft" variant="secondary">Save as draft</x-ui.button>
                        <x-ui.button type="submit" form="submit-newsletter-approval" variant="light">Submit for approval</x-ui.button>
                    </div>
                </form>
                <form id="save-newsletter-draft" method="POST" action="{{ route('app.newsletters.draft', $newsletter) }}" class="hidden">@csrf</form>
                <form id="submit-newsletter-approval" method="POST" action="{{ route('app.newsletters.approval.request', $newsletter) }}" class="hidden">@csrf</form>
            </x-dashboard.section>

            <x-dashboard.section title="Add section" description="Build the edition from editorial blocks or content assets.">
                <form method="POST" action="{{ route('app.newsletters.sections.store', $newsletter) }}" class="space-y-4">
                    @csrf
                    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-1">
                        <label class="block">
                            <span class="text-xs font-semibold uppercase tracking-[0.12em] text-muted">Type</span>
                            <select name="type" class="mt-1 w-full rounded-lg border border-line bg-white px-3 py-2 text-sm text-ink">
                                @foreach ($sectionTypes as $type)
                                    <option value="{{ $type }}">{{ str($type)->headline() }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label class="block">
                            <span class="text-xs font-semibold uppercase tracking-[0.12em] text-muted">Position</span>
                            <input name="position" type="number" min="0" class="mt-1 w-full rounded-lg border border-line bg-white px-3 py-2 text-sm text-ink" placeholder="{{ $newsletter->sections->count() + 1 }}">
                        </label>
                    </div>
                    <label class="block">
                        <span class="text-xs font-semibold uppercase tracking-[0.12em] text-muted">Title</span>
                        <input name="title" class="mt-1 w-full rounded-lg border border-line bg-white px-3 py-2 text-sm text-ink" placeholder="Lead story">
                    </label>
                        <label class="block">
                            <span class="text-xs font-semibold uppercase tracking-[0.12em] text-muted">Content asset</span>
                            <select name="content_asset_id" class="mt-1 w-full rounded-lg border border-line bg-white px-3 py-2 text-sm text-ink">
                            <option value="">No content asset</option>
                            @foreach ($contentAssets as $asset)
                                <option value="{{ $asset->id }}">{{ $asset->title }} · {{ strtoupper($asset->language) }}</option>
                            @endforeach
                        </select>
                    </label>
                    <textarea name="body" rows="5" class="w-full rounded-lg border border-line bg-white px-3 py-2 text-sm text-ink" placeholder="Section body"></textarea>
                    <x-ui.button type="submit">Add section</x-ui.button>
                </form>
            </x-dashboard.section>
        </div>

        <div class="mt-6 grid gap-6 lg:grid-cols-[1fr_1fr]">
            <x-dashboard.section title="Sections" description="Use position numbers to reorder sections. Lower numbers appear first.">
                @if ($newsletter->sections->isEmpty())
                    <x-dashboard.empty-state title="No sections" message="Add sections to assemble this edition." />
                @else
                    <form method="POST" action="{{ route('app.newsletters.sections.reorder', $newsletter) }}" class="space-y-3">
                        @csrf
                        @foreach ($newsletter->sections as $section)
                            <x-newsletters.section-card :section="$section" />
                        @endforeach
                        <x-ui.button type="submit">Update order</x-ui.button>
                    </form>
                @endif
            </x-dashboard.section>

            <x-dashboard.section title="Preview newsletter" description="Static preview only; no email sending is connected here.">
                <x-newsletters.preview :newsletter="$newsletter" />
            </x-dashboard.section>
        </div>
    </div>
</x-app.layout>
