<x-app.layout title="Briefings | Argusly">
    <div class="mx-auto max-w-7xl">
        <div class="flex flex-col justify-between gap-4 lg:flex-row lg:items-end">
            <div>
                <p class="eyebrow">Marketing OS</p>
                <h1 class="mt-2 text-3xl font-semibold tracking-tight text-ink sm:text-4xl">Briefings</h1>
                <p class="mt-2 max-w-2xl text-sm leading-6 text-muted">Create campaign-ready briefs that can guide content generation later.</p>
            </div>
            <x-ui.button href="{{ route('app.marketing') }}" variant="secondary">Marketing OS</x-ui.button>
        </div>

        @if (session('status'))
            <div class="mt-6 rounded-lg border border-line bg-white p-4 text-sm font-medium text-ink">{{ session('status') }}</div>
        @endif

        <div class="mt-8 grid gap-6 xl:grid-cols-[0.8fr_1.2fr]">
            <x-dashboard.section title="Create briefing" description="Attach strategy to a campaign, channel mix and language set.">
                <form method="POST" action="{{ route('app.briefings.store') }}" class="space-y-4">
                    @csrf
                    <div class="grid gap-3 sm:grid-cols-2">
                        <label class="block">
                            <span class="text-xs font-semibold uppercase tracking-[0.12em] text-muted">Scope</span>
                            <select name="scope" class="mt-1 w-full rounded-lg border border-line bg-white px-3 py-2 text-sm text-ink">
                                <option value="brand">{{ $brand?->name ?? 'Current brand' }}</option>
                                <option value="account">Account-wide</option>
                            </select>
                        </label>
                        <label class="block">
                            <span class="text-xs font-semibold uppercase tracking-[0.12em] text-muted">Status</span>
                            <select name="status" class="mt-1 w-full rounded-lg border border-line bg-white px-3 py-2 text-sm text-ink">
                                @foreach ($statuses as $status)
                                    <option value="{{ $status }}">{{ str($status)->headline() }}</option>
                                @endforeach
                            </select>
                        </label>
                    </div>
                    <label class="block">
                        <span class="text-xs font-semibold uppercase tracking-[0.12em] text-muted">Title</span>
                        <input name="title" required class="mt-1 w-full rounded-lg border border-line bg-white px-3 py-2 text-sm text-ink" placeholder="Q3 awareness launch brief">
                    </label>
                    <label class="block">
                        <span class="text-xs font-semibold uppercase tracking-[0.12em] text-muted">Campaign</span>
                        <select name="campaign_id" class="mt-1 w-full rounded-lg border border-line bg-white px-3 py-2 text-sm text-ink">
                            <option value="">No campaign</option>
                            @foreach ($campaigns as $campaign)
                                <option value="{{ $campaign->id }}">{{ $campaign->name }}</option>
                            @endforeach
                        </select>
                    </label>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <label class="block">
                            <span class="text-xs font-semibold uppercase tracking-[0.12em] text-muted">Audience</span>
                            <input name="audience" class="mt-1 w-full rounded-lg border border-line bg-white px-3 py-2 text-sm text-ink" placeholder="B2B marketing leaders">
                        </label>
                        <label class="block">
                            <span class="text-xs font-semibold uppercase tracking-[0.12em] text-muted">Tone</span>
                            <input name="tone_of_voice" class="mt-1 w-full rounded-lg border border-line bg-white px-3 py-2 text-sm text-ink" placeholder="Sharp, useful, direct">
                        </label>
                    </div>
                    <textarea name="objective" rows="2" class="w-full rounded-lg border border-line bg-white px-3 py-2 text-sm text-ink" placeholder="Objective"></textarea>
                    <textarea name="key_message" rows="2" class="w-full rounded-lg border border-line bg-white px-3 py-2 text-sm text-ink" placeholder="Key message"></textarea>
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-[0.12em] text-muted">Channels</p>
                        <div class="mt-2 flex flex-wrap gap-2">
                            @foreach ($channels as $channel)
                                <label class="inline-flex items-center gap-2 rounded-lg border border-line bg-panel px-3 py-2 text-sm text-ink">
                                    <input type="checkbox" name="channels[]" value="{{ $channel }}">
                                    {{ str($channel)->headline() }}
                                </label>
                            @endforeach
                        </div>
                    </div>
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-[0.12em] text-muted">Languages</p>
                        <div class="mt-2 flex flex-wrap gap-2">
                            @foreach ($languages as $language)
                                <label class="inline-flex items-center gap-2 rounded-lg border border-line bg-panel px-3 py-2 text-sm text-ink">
                                    <input type="checkbox" name="languages[]" value="{{ $language->code }}">
                                    {{ $language->name }}
                                </label>
                            @endforeach
                        </div>
                    </div>
                    <x-ui.button type="submit">Create briefing</x-ui.button>
                </form>
            </x-dashboard.section>

            <x-dashboard.section title="Briefings" description="Campaign and account briefs ready for planning, approval and future generation.">
                @if ($briefings->isEmpty())
                    <x-dashboard.empty-state title="No briefings" message="Create a briefing to guide campaign and content work." />
                @else
                    <div class="space-y-3">
                        @foreach ($briefings as $briefing)
                            <a href="{{ route('app.briefings.show', $briefing) }}" class="block rounded-lg border border-line bg-panel p-4 transition hover:border-slate-300 hover:bg-white">
                                <div class="flex flex-wrap items-center gap-2">
                                    <p class="text-sm font-semibold text-ink">{{ $briefing->title }}</p>
                                    <x-ui.badge variant="{{ $briefing->status === 'approved' ? 'success' : ($briefing->status === 'review' ? 'blue' : 'default') }}">{{ str($briefing->status)->headline() }}</x-ui.badge>
                                    <x-ui.badge>{{ $briefing->brand?->name ?? 'Account-wide' }}</x-ui.badge>
                                </div>
                                <p class="mt-2 line-clamp-2 text-sm leading-6 text-muted">{{ $briefing->objective ?: $briefing->key_message ?: 'No objective yet.' }}</p>
                                <p class="mt-2 text-xs text-muted">{{ $briefing->campaign?->name ?? 'No campaign' }}</p>
                            </a>
                        @endforeach
                    </div>
                    <div class="mt-5">{{ $briefings->links() }}</div>
                @endif
            </x-dashboard.section>
        </div>
    </div>
</x-app.layout>
