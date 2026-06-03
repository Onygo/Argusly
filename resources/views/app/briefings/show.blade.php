<x-app.layout title="{{ $briefing->title }} | Argusly">
    <div class="mx-auto max-w-5xl">
        <div class="flex flex-col justify-between gap-4 lg:flex-row lg:items-end">
            <div>
                <p class="eyebrow">Briefing detail</p>
                <h1 class="mt-2 text-3xl font-semibold tracking-tight text-ink sm:text-4xl">{{ $briefing->title }}</h1>
                <p class="mt-2 max-w-2xl text-sm leading-6 text-muted">{{ $briefing->objective ?: 'No objective yet.' }}</p>
            </div>
            <div class="flex flex-wrap gap-2">
                <x-ui.button href="{{ route('app.briefings') }}" variant="secondary">All briefings</x-ui.button>
                @if ($briefing->status !== 'approved')
                    <form method="POST" action="{{ route('app.briefings.approval.request', $briefing) }}">
                        @csrf
                        <x-ui.button type="submit" variant="secondary">Request approval</x-ui.button>
                    </form>
                @endif
            </div>
        </div>

        @if (session('status'))
            <div class="mt-6 rounded-md border border-line bg-white p-4 text-sm font-medium text-ink">{{ session('status') }}</div>
        @endif

        <div class="mt-8 grid gap-4 md:grid-cols-2 xl:grid-cols-4">
            <x-dashboard.info-card label="Status" value="{{ str($briefing->status)->headline() }}" />
            <x-dashboard.info-card label="Campaign" :value="$briefing->campaign?->name" empty="No campaign" />
            <x-dashboard.info-card label="Brand" :value="$briefing->brand?->name" empty="Account-wide" />
            <x-dashboard.info-card label="Approved" :value="$briefing->approved_at?->format('M j, Y')" empty="Not approved" />
        </div>

        <div class="mt-6 grid gap-6 lg:grid-cols-2">
            <x-dashboard.section title="Strategic direction" description="Core inputs for future content generation.">
                <dl class="space-y-4 text-sm">
                    <div>
                        <dt class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">Audience</dt>
                        <dd class="mt-1 text-ink">{{ $briefing->audience ?: 'Not set' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">Tone of voice</dt>
                        <dd class="mt-1 text-ink">{{ $briefing->tone_of_voice ?: 'Not set' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">Key message</dt>
                        <dd class="mt-1 text-ink">{{ $briefing->key_message ?: 'Not set' }}</dd>
                    </div>
                </dl>
            </x-dashboard.section>

            <x-dashboard.section title="Distribution context" description="Channels and language scope validated for this brand.">
                <div class="space-y-5">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">Channels</p>
                        <div class="mt-2 flex flex-wrap gap-2">
                            @forelse ($briefing->channels ?? [] as $channel)
                                <x-ui.badge>{{ str($channel)->headline() }}</x-ui.badge>
                            @empty
                                <p class="text-sm text-muted">No channels selected.</p>
                            @endforelse
                        </div>
                    </div>
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">Languages</p>
                        <div class="mt-2 flex flex-wrap gap-2">
                            @forelse ($briefing->languages ?? [] as $language)
                                <x-ui.badge>{{ strtoupper($language) }}</x-ui.badge>
                            @empty
                                <p class="text-sm text-muted">No languages selected.</p>
                            @endforelse
                        </div>
                    </div>
                </div>
            </x-dashboard.section>
        </div>

        @if ($briefing->status === 'review')
            <div class="mt-6">
                <x-dashboard.section title="Approval" description="Approve this briefing so downstream workflows can rely on it.">
                    <form method="POST" action="{{ route('app.briefings.approve', $briefing) }}">
                        @csrf
                        <x-ui.button type="submit">Approve briefing</x-ui.button>
                    </form>
                </x-dashboard.section>
            </div>
        @endif
    </div>
</x-app.layout>
