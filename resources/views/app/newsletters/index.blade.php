<x-app.layout title="Newsletters | Argusly">
    <div class="w-full">
        <div class="flex flex-col justify-between gap-4 lg:flex-row lg:items-end">
            <div>
                <p class="eyebrow">Marketing OS</p>
                <h1 class="mt-2 text-3xl font-semibold tracking-tight text-ink sm:text-4xl">Newsletters</h1>
                <p class="mt-2 max-w-2xl text-sm leading-6 text-muted">Plan email editions from approved campaign and content assets.</p>
            </div>
            <x-ui.button href="{{ route('app.marketing') }}" variant="secondary">Marketing OS</x-ui.button>
        </div>

        @if (session('status'))
            <div class="mt-6 rounded-md border border-line bg-white p-4 text-sm font-medium text-ink">{{ session('status') }}</div>
        @endif

        <div class="mt-8 grid gap-6 xl:grid-cols-[0.8fr_1.2fr]">
            <x-dashboard.section title="Create newsletter" description="Set the editorial frame, language and send status.">
                <form method="POST" action="{{ route('app.newsletters.store') }}" class="space-y-4">
                    @csrf
                    <div class="grid gap-3 sm:grid-cols-2">
                        <label class="block">
                            <span class="text-xs font-semibold uppercase tracking-[0.12em] text-muted">Language</span>
                            <select name="language" class="mt-1 w-full rounded-md border border-line bg-white px-3 py-2 text-sm text-ink">
                                @foreach ($languages as $language)
                                    <option value="{{ $language->code }}">{{ $language->name }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label class="block">
                            <span class="text-xs font-semibold uppercase tracking-[0.12em] text-muted">Status</span>
                            <select name="status" class="mt-1 w-full rounded-md border border-line bg-white px-3 py-2 text-sm text-ink">
                                @foreach ($statuses as $status)
                                    <option value="{{ $status }}">{{ str($status)->headline() }}</option>
                                @endforeach
                            </select>
                        </label>
                    </div>
                    <label class="block">
                        <span class="text-xs font-semibold uppercase tracking-[0.12em] text-muted">Title</span>
                        <input name="title" required class="mt-1 w-full rounded-md border border-line bg-white px-3 py-2 text-sm text-ink" placeholder="June market intelligence digest">
                    </label>
                    <label class="block">
                        <span class="text-xs font-semibold uppercase tracking-[0.12em] text-muted">Campaign</span>
                        <select name="campaign_id" class="mt-1 w-full rounded-md border border-line bg-white px-3 py-2 text-sm text-ink">
                            <option value="">No campaign</option>
                            @foreach ($campaigns as $campaign)
                                <option value="{{ $campaign->id }}">{{ $campaign->name }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="block">
                        <span class="text-xs font-semibold uppercase tracking-[0.12em] text-muted">Subject</span>
                        <input name="subject" class="mt-1 w-full rounded-md border border-line bg-white px-3 py-2 text-sm text-ink" placeholder="What changed in AI visibility this week">
                    </label>
                    <label class="block">
                        <span class="text-xs font-semibold uppercase tracking-[0.12em] text-muted">Preheader</span>
                        <input name="preheader" class="mt-1 w-full rounded-md border border-line bg-white px-3 py-2 text-sm text-ink" placeholder="Signals, actions and content to ship next.">
                    </label>
                    <label class="block">
                        <span class="text-xs font-semibold uppercase tracking-[0.12em] text-muted">Scheduled at</span>
                        <input name="scheduled_at" type="datetime-local" class="mt-1 w-full rounded-md border border-line bg-white px-3 py-2 text-sm text-ink">
                    </label>
                    <x-ui.button type="submit">Create newsletter</x-ui.button>
                </form>
            </x-dashboard.section>

            <x-dashboard.section title="Newsletter pipeline" description="Drafts, review editions and scheduled sends for the current brand.">
                @if ($newsletters->isEmpty())
                    <x-dashboard.empty-state title="No newsletters" message="Create a newsletter to start assembling an edition." />
                @else
                    <div class="space-y-3">
                        @foreach ($newsletters as $newsletter)
                            <a href="{{ route('app.newsletters.show', $newsletter) }}" class="block rounded-md border border-line bg-panel p-4 transition hover:border-slate-300 hover:bg-white">
                                <div class="flex flex-wrap items-center gap-2">
                                    <p class="text-sm font-semibold text-ink">{{ $newsletter->title }}</p>
                                    <x-ui.badge variant="{{ in_array($newsletter->status, ['approved', 'sent'], true) ? 'success' : (in_array($newsletter->status, ['review', 'scheduled', 'sending'], true) ? 'blue' : 'default') }}">{{ str($newsletter->status)->headline() }}</x-ui.badge>
                                    <x-ui.badge>{{ strtoupper($newsletter->language) }}</x-ui.badge>
                                </div>
                                <p class="mt-2 line-clamp-2 text-sm leading-6 text-muted">{{ $newsletter->subject ?: $newsletter->preheader ?: 'No subject yet.' }}</p>
                                <p class="mt-2 text-xs text-muted">{{ $newsletter->campaign?->name ?? 'No campaign' }}</p>
                            </a>
                        @endforeach
                    </div>
                    <div class="mt-5">{{ $newsletters->links() }}</div>
                @endif
            </x-dashboard.section>
        </div>
    </div>
</x-app.layout>
