@php
    $features = [
        ['AI Visibility', 'Track how ChatGPT, Perplexity, Gemini and AI Overviews cite your brand across thousands of prompts.', 'A'],
        ['Brand Intelligence', 'Monitor mentions, sentiment and share of voice across search, social and the open web in real time.', 'B'],
        ['Competitive Intelligence', 'Benchmark visibility, topics and growth velocity against competitors. Spot gaps before they cost you.', 'C'],
        ['Agentic Marketing', 'Autonomous agents draft content, refresh decaying pages, monitor topics and launch campaigns on your behalf.', 'G'],
        ['Content Orchestration', 'Plan, generate and ship content across every surface from a single intelligence-driven workspace.', 'O'],
        ['Search Intelligence', 'Entity coverage, topic authority and answer coverage measured on the new search surfaces, not the old ones.', 'S'],
    ];

    $agents = [
        ['Content Agent', 'Active', 'Drafted EV charging trends', '+42 citations'],
        ['Research Agent', 'Idle', 'Mapped 31 entities in EV cluster', '93% coverage'],
        ['SEO Agent', 'Idle', 'Refreshed 4 decaying pages', '+21% visibility'],
        ['Social Agent', 'Active', 'Scheduled 6 LinkedIn posts', '+31% reach'],
        ['Monitoring Agent', 'Active', 'Flagged 12 brand mentions', 'High priority'],
        ['Competitive Agent', 'Active', 'Tracked Hyundai launch', 'Gap detected'],
        ['Campaign Agent', 'Paused', 'QA awareness campaign', '+18% reach'],
        ['Lifecycle Agent', 'Idle', 'Re-engaged 1,248 leads', '6.2% reply'],
    ];
@endphp

<x-marketing.layout>
    <section class="relative overflow-hidden border-b border-line bg-white">
        <div class="container-page pb-20 pt-16 text-center sm:pb-24 sm:pt-24">
            <x-ui.badge variant="blue">Now monitoring ChatGPT, Perplexity, Gemini & Google AI Overviews</x-ui.badge>
            <h1 class="mx-auto mt-6 max-w-4xl text-5xl font-semibold leading-[0.95] tracking-tight text-ink sm:text-7xl">
                See how AI talks about your brand.
            </h1>
            <p class="mx-auto mt-6 max-w-2xl text-base leading-7 text-muted sm:text-lg">
                Argusly monitors visibility across AI assistants, search, competitors and social. Discover opportunities, automate actions and grow with agentic marketing intelligence.
            </p>
            <div class="mt-8 flex flex-col items-center justify-center gap-3 sm:flex-row">
                <x-ui.button href="{{ url('/dashboard') }}" size="lg">Start monitoring</x-ui.button>
                <x-ui.button href="#demo" variant="secondary" size="lg">Book demo</x-ui.button>
            </div>
            <p class="mt-4 text-xs font-medium text-muted">No credit card. 14-day trial. Cancel anytime.</p>

            <div class="mx-auto mt-14 max-w-4xl rounded-2xl border border-line bg-white p-3 text-left">
                <div class="rounded-xl border border-line bg-panel/70 p-3">
                    <div class="mb-3 flex items-center justify-between px-2 text-xs text-muted">
                        <span><strong class="text-ink">Argusly</strong> / Dashboard / Kia Europe</span>
                        <span>Last 30 days</span>
                    </div>
                    <div class="grid gap-3 lg:grid-cols-[160px_1fr]">
                        <div class="rounded-xl border border-line bg-white p-3">
                            @foreach (['Dashboard', 'Intelligence', 'Visibility', 'Competitors', 'Mentions', 'Content', 'Campaigns', 'Automations', 'Reports'] as $item)
                                <div class="rounded-lg px-2 py-2 text-xs font-medium {{ $item === 'Dashboard' ? 'bg-panel text-ink' : 'text-muted' }}">{{ $item }}</div>
                            @endforeach
                        </div>
                        <div class="space-y-3">
                            <div class="metric-grid">
                                <x-ui.metric-card label="AI Visibility" value="78" change="+8.4%" />
                                <x-ui.metric-card label="Brand Mentions" value="1,284" change="+12%" />
                                <x-ui.metric-card label="Share of Voice" value="34%" change="+18%" />
                                <x-ui.metric-card label="Sentiment" value="+62" change="-1.4%" tone="flat" />
                            </div>
                            <div class="grid gap-3 lg:grid-cols-[1fr_230px]">
                                <x-ui.card class="p-5">
                                    <div class="flex items-center justify-between">
                                        <div>
                                            <h3 class="text-sm font-semibold text-ink">Visibility across surfaces</h3>
                                            <p class="text-xs text-muted">AI Search, Organic, Social</p>
                                        </div>
                                        <div class="flex gap-3 text-xs text-muted"><span class="text-blue">AI</span><span>Organic</span><span>Social</span></div>
                                    </div>
                                    <svg viewBox="0 0 640 180" class="mt-6 h-44 w-full" role="img" aria-label="Visibility trend chart">
                                        <path d="M0 142 L64 132 L128 136 L192 118 L256 116 L320 96 L384 86 L448 72 L512 58 L576 42 L640 28" fill="none" stroke="#235cff" stroke-width="4" />
                                        <path d="M0 156 L64 148 L128 142 L192 140 L256 126 L320 120 L384 110 L448 100 L512 90 L576 84 L640 76" fill="none" stroke="#c9ced8" stroke-width="4" />
                                        <path d="M0 165 L640 165" stroke="#e7eaf0" />
                                        <path d="M0 118 L640 118" stroke="#e7eaf0" />
                                        <path d="M0 72 L640 72" stroke="#e7eaf0" />
                                    </svg>
                                </x-ui.card>
                                <x-ui.card class="p-5">
                                    <h3 class="text-sm font-semibold text-ink">Intelligence feed</h3>
                                    <div class="mt-4 space-y-3">
                                        @foreach (['Competitor Hyundai gained AI citations on EV topic', 'New brand mention in Perplexity answer', 'Content opportunity: compare SUV 2026', 'Sentiment dip detected on Reddit claims'] as $feed)
                                            <div class="rounded-xl border border-line p-3 text-xs text-muted">{{ $feed }}</div>
                                        @endforeach
                                    </div>
                                </x-ui.card>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="mt-14">
                <p class="text-xs font-semibold uppercase tracking-[0.18em] text-muted">Trusted by intelligence-first teams</p>
                <div class="mt-6 flex flex-wrap items-center justify-center gap-x-8 gap-y-3 text-sm font-semibold text-muted">
                    @foreach (['Kia', 'Hyundai', 'Lexus', 'Stripe', 'Linear', 'Vercel', 'Attio'] as $logo)
                        <span>{{ $logo }}</span>
                    @endforeach
                </div>
            </div>
        </div>
    </section>

    <section id="platform" class="section-pad bg-white">
        <div class="container-page">
            <div class="mx-auto max-w-2xl text-center">
                <p class="eyebrow">The platform</p>
                <h2 class="mt-3 text-4xl font-semibold leading-tight tracking-tight text-ink sm:text-5xl">An operating system for AI visibility.</h2>
                <p class="mt-4 text-sm leading-6 text-muted">Six disciplines, one intelligence layer. Argusly replaces the patchwork of SEO tools, monitoring software and marketing dashboards.</p>
            </div>
            <div class="mt-12 grid gap-4 md:grid-cols-2 lg:grid-cols-3">
                @foreach ($features as [$title, $description, $icon])
                    <x-ui.feature-card :title="$title" :description="$description" :icon="$icon" />
                @endforeach
            </div>
        </div>
    </section>

    <section id="intelligence" class="section-pad border-y border-line bg-panel">
        <div class="container-page">
            <div class="mx-auto max-w-2xl text-center">
                <p class="eyebrow">Intelligence workspace</p>
                <h2 class="mt-3 text-4xl font-semibold leading-tight tracking-tight text-ink sm:text-5xl">Stop reading dashboards. Read insights.</h2>
                <p class="mt-4 text-sm leading-6 text-muted">Argusly turns telemetry into prioritized, scored, actionable cards. Click to launch the agent that executes.</p>
            </div>
            <div class="mt-12 grid gap-4 md:grid-cols-2">
                <x-ui.insight-card signal="Content Agent" title="Refresh declining article: EV charging guide" description="Citations dropped 34% in ChatGPT. AI-suggested rewrite ready." impact="Impact 82" action="Refresh content" />
                <x-ui.insight-card signal="SEO Agent" title="Launch cluster: compact hybrid SUV 2026" description="Detected unanswered demand across Perplexity and Google AIO." impact="Impact 87" action="Launch cluster" />
                <x-ui.insight-card signal="Competitive" title="Competitor Hyundai gaining share on safety topics" description="AI visibility is 18% over 14d. Recommended counter-narrative." impact="Impact 79" action="Monitor competitor" />
                <x-ui.insight-card signal="Social Agent" title="LinkedIn engagement +23% on EV thought leadership" description="Pattern detected. Recommend 3-post campaign this week." impact="Impact 74" action="Create campaign" />
            </div>
        </div>
    </section>

    <section id="agents" class="section-pad bg-white">
        <div class="container-page">
            <div class="mx-auto max-w-2xl text-center">
                <p class="eyebrow">Agentic marketing</p>
                <h2 class="mt-3 text-4xl font-semibold leading-tight tracking-tight text-ink sm:text-5xl">Fight autonomous agents. One marketing org.</h2>
                <p class="mt-4 text-sm leading-6 text-muted">Each agent monitors, decides and executes within guardrails you define. You stay in control. The work just happens.</p>
            </div>
            <div class="mt-12 grid gap-4 md:grid-cols-2 lg:grid-cols-4">
                @foreach ($agents as [$name, $status, $action, $impact])
                    <x-ui.agent-card :name="$name" :status="$status" :action="$action" :impact="$impact" />
                @endforeach
            </div>
        </div>
    </section>

    <section class="section-pad border-y border-line bg-panel">
        <div class="container-page">
            <div class="mx-auto max-w-2xl text-center">
                <p class="eyebrow">Competitive intelligence</p>
                <h2 class="mt-3 text-4xl font-semibold leading-tight tracking-tight text-ink sm:text-5xl">Know where you stand. Every surface.</h2>
                <p class="mt-4 text-sm leading-6 text-muted">Visibility, share of voice, sentiment and growth velocity. Side by side. Updated continuously.</p>
            </div>
            <x-ui.card class="mt-12 overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[680px] text-left text-sm">
                        <thead class="border-b border-line bg-white text-xs uppercase tracking-[0.12em] text-muted">
                            <tr><th class="px-5 py-4">Brand</th><th class="px-5 py-4">AI Visibility</th><th class="px-5 py-4">Share</th><th class="px-5 py-4">Mentions</th><th class="px-5 py-4">Change</th></tr>
                        </thead>
                        <tbody class="divide-y divide-line">
                            @foreach ([['Your brand', 78, '34%', '+62', '+12.4%'], ['Infodation', 71, '28%', '+54', '+10.2%'], ['Keyora', 64, '22%', '+48', '+4.1%'], ['Lexus', 48, '9%', '+58', '+6.0%'], ['Volkswagen', 40, '7%', '+38', '-3.3%']] as [$brand, $score, $share, $mentions, $change])
                                <tr>
                                    <td class="px-5 py-4 font-semibold text-ink">{{ $brand }} @if($brand === 'Your brand') <x-ui.badge variant="dark" class="ml-2">You</x-ui.badge> @endif</td>
                                    <td class="px-5 py-4"><div class="h-2 w-40 rounded-full bg-slate-100"><div class="h-2 rounded-full bg-ink" style="width: {{ $score }}%"></div></div></td>
                                    <td class="px-5 py-4 text-muted">{{ $share }}</td>
                                    <td class="px-5 py-4 text-muted">{{ $mentions }}</td>
                                    <td class="px-5 py-4 font-semibold text-blue">{{ $change }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </x-ui.card>
        </div>
    </section>

    <section class="section-pad bg-white">
        <div class="container-page">
            <div class="mx-auto max-w-2xl text-center">
                <p class="eyebrow">Customer signal</p>
                <h2 class="mt-3 text-4xl font-semibold tracking-tight text-ink sm:text-5xl">Operators don't go back.</h2>
                <p class="mt-4 text-sm leading-6 text-muted">Marketing and intelligence teams using Argusly to run modern brand operations.</p>
            </div>
            <div class="mt-12 grid gap-4 md:grid-cols-3">
                @foreach ([['Argusly replaced four tools. Our team finally sees AI visibility, brand mentions and competitive moves in one place.', 'Maria Chen', 'VP Marketing, Kia Europe'], ['The agentic workflows ship work while we sleep. It feels like hiring a 24/7 intelligence team.', 'Daniel Roth', 'Head of Growth, Vercel'], ['We caught a competitor surge in AI Overviews on day one. Argusly paid for itself in the first week.', 'Alex Tanaka', 'Director of SEO, Lexus']] as [$quote, $name, $role])
                    <x-ui.card class="p-6">
                        <p class="text-sm leading-6 text-ink">"{{ $quote }}"</p>
                        <div class="mt-6 flex items-center gap-3">
                            <div class="grid size-8 place-items-center rounded-full bg-panel text-xs font-bold text-ink">{{ substr($name, 0, 1) }}</div>
                            <div><p class="text-sm font-semibold text-ink">{{ $name }}</p><p class="text-xs text-muted">{{ $role }}</p></div>
                        </div>
                    </x-ui.card>
                @endforeach
            </div>
        </div>
    </section>

    <section id="pricing" class="bg-white pb-20">
        <div class="container-page">
            <div class="rounded-2xl bg-gradient-to-br from-blue to-purple px-6 py-16 text-center text-white sm:px-10">
                <h2 class="mx-auto max-w-2xl text-4xl font-semibold leading-tight tracking-tight sm:text-5xl">Start seeing what AI says about you.</h2>
                <p class="mx-auto mt-4 max-w-xl text-sm leading-6 text-white/75">Free 14-day trial. No credit card required. Full access to monitoring, intelligence and agents.</p>
                <div class="mt-8 flex flex-col items-center justify-center gap-3 sm:flex-row">
                    <x-ui.button href="{{ url('/dashboard') }}" variant="light">Start monitoring</x-ui.button>
                    <x-ui.button href="#demo" variant="secondary" class="border-white/25 bg-white/10 text-white hover:bg-white/20">Book demo</x-ui.button>
                </div>
                <div class="mt-8 flex flex-wrap justify-center gap-5 text-xs font-medium text-white/75">
                    <span>SOC 2 ready</span><span>GDPR compliant</span><span>EU data residency</span><span>Single sign-on</span>
                </div>
            </div>
        </div>
    </section>
</x-marketing.layout>
