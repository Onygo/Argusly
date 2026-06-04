@php
    $features = [
        ['AI Visibility', 'Track how ChatGPT, Perplexity, Gemini and AI Overviews cite your brand across thousands of prompts.', 'eye'],
        ['Brand Intelligence', 'Monitor mentions, sentiment and share of voice across search, social and the open web in real time.', 'radar'],
        ['Competitive Intelligence', 'Benchmark visibility, topics and growth velocity against competitors. Spot gaps before they cost you.', 'activity'],
        ['Agentic Marketing', 'Autonomous agents draft content, refresh decaying pages, monitor topics and launch campaigns on your behalf.', 'bot'],
        ['Content Orchestration', 'Plan, generate and ship content across every surface from a single intelligence-driven workspace.', 'sparkles'],
        ['Search Intelligence', 'Entity coverage, topic authority and answer coverage measured on the new search surfaces, not the old ones.', 'globe'],
    ];

    $agents = [
        ['Content Agent', 'Active', 'Drafted answer-ready article', '+42 citations'],
        ['Research Agent', 'Idle', 'Mapped 31 brand entities', '93% coverage'],
        ['SEO Agent', 'Idle', 'Refreshed 4 decaying pages', '+21% visibility'],
        ['Social Agent', 'Active', 'Scheduled 6 LinkedIn posts', '+31% reach'],
        ['Monitoring Agent', 'Active', 'Flagged 12 brand mentions', 'High priority'],
        ['Competitive Agent', 'Active', 'Tracked competitor launch', 'Gap detected'],
        ['Campaign Agent', 'Paused', 'QA awareness campaign', '+18% reach'],
        ['Lifecycle Agent', 'Idle', 'Re-engaged 1,248 leads', '6.2% reply'],
    ];
@endphp

<x-marketing.layout>
    <section class="relative overflow-hidden border-b border-line bg-white">
        <div class="pointer-events-none absolute inset-0 argusly-grid opacity-40 [mask-image:radial-gradient(ellipse_at_top,black,transparent_70%)]"></div>
        <div class="container-page relative pb-20 pt-16 text-center sm:pb-24 sm:pt-24">
            <h1 class="mx-auto max-w-4xl text-5xl font-semibold leading-[0.95] tracking-tight text-ink sm:text-7xl">
                See how AI talks about your brand.
            </h1>
            <p class="mx-auto mt-6 max-w-2xl text-base leading-7 text-muted sm:text-lg">
                Monitor AI visibility, discover opportunities and orchestrate growth from a single intelligence platform.
            </p>
            <div class="mt-8 flex flex-col items-center justify-center gap-3 sm:flex-row">
                <x-ui.button href="{{ route('marketing.signup') }}" variant="dark" size="lg" shape="pill">
                    Request pilot access
                    <x-app.icon name="arrow-right" class="size-4" />
                </x-ui.button>
                <x-ui.button href="{{ route('marketing.contact', ['topic' => 'sales']) }}" variant="secondary" size="lg" shape="pill">Book demo</x-ui.button>
            </div>

            <div class="mx-auto mt-14 max-w-5xl rounded-2xl border border-line bg-white/85 text-left backdrop-blur">
                <div class="overflow-hidden rounded-2xl">
                    <div class="flex items-center justify-between border-b border-line bg-panel/60 px-4 py-3 text-xs text-muted">
                        <span class="flex items-center gap-2">
                            <span class="flex gap-1.5">
                                <span class="size-2.5 rounded-full bg-muted/30"></span>
                                <span class="size-2.5 rounded-full bg-muted/30"></span>
                                <span class="size-2.5 rounded-full bg-muted/30"></span>
                            </span>
                            <strong class="ml-2 text-ink">Argusly</strong> / Dashboard / Demo workspace
                        </span>
                        <span>Last 30 days</span>
                    </div>
                    <div class="grid lg:grid-cols-[190px_1fr]">
                        <div class="hidden border-r border-line bg-white p-4 lg:block">
                            @foreach ([['Dashboard', 'layout-dashboard'], ['Intelligence', 'sparkles'], ['Visibility', 'eye'], ['Competitors', 'activity'], ['Mentions', 'message'], ['Content', 'file-text'], ['Campaigns', 'megaphone'], ['Automations', 'bot'], ['Reports', 'bar-chart']] as [$item, $navIcon])
                                <div class="flex items-center gap-2 rounded-md px-2.5 py-2 text-xs font-medium {{ $item === 'Dashboard' ? 'bg-panel text-ink' : 'text-muted' }}">
                                    <x-app.icon :name="$navIcon" class="size-3.5 opacity-60" />
                                    {{ $item }}
                                </div>
                            @endforeach
                        </div>
                        <div class="space-y-4 p-4">
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
                                        @foreach ([['Competitor gained AI citations on priority topic', 'trend-up', 'High'], ['New brand mention in Perplexity answer', 'message', 'Med'], ['Content opportunity: strengthen buyer FAQ', 'target', 'High'], ['Sentiment dip detected on product claim', 'shield', 'Med']] as [$feed, $feedIcon, $priority])
                                            <div class="flex items-start gap-2 rounded-xl border border-line p-3 text-xs text-muted">
                                                <x-app.icon :name="$feedIcon" class="mt-0.5 size-3.5 shrink-0" />
                                                <span class="min-w-0 flex-1">{{ $feed }}</span>
                                                <span class="rounded-full border border-line px-1.5 py-0.5 text-[10px]">{{ $priority }}</span>
                                            </div>
                                        @endforeach
                                    </div>
                                </x-ui.card>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="mt-14">
                <p class="text-xs font-semibold uppercase tracking-[0.18em] text-muted">Built for emerging AI visibility teams</p>
                <div class="mt-6 flex flex-wrap items-center justify-center gap-x-6 gap-y-3 text-sm font-semibold text-muted">
                    <span>Brand monitoring</span>
                    <span>AI search tracking</span>
                    <span>Competitor intelligence</span>
                    <span>Agent workflows</span>
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
            <div class="mt-12 grid gap-px overflow-hidden rounded-2xl border border-line bg-line md:grid-cols-2 lg:grid-cols-3">
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
                <x-ui.insight-card signal="Content Agent" title="Refresh declining article: category guide" description="Citations dropped 34% in ChatGPT. AI-suggested rewrite ready." impact="Impact 82" action="Refresh content" />
                <x-ui.insight-card signal="SEO Agent" title="Launch cluster: buyer questions and comparisons" description="Detected unanswered demand across Perplexity and Google AIO." impact="Impact 87" action="Launch cluster" />
                <x-ui.insight-card signal="Competitive" title="Competitor gaining share on priority topic" description="AI visibility is 18% over 14d. Recommended counter-narrative." impact="Impact 79" action="Monitor competitor" />
                <x-ui.insight-card signal="Social Agent" title="LinkedIn engagement +23% on expert posts" description="Pattern detected. Recommend 3-post campaign this week." impact="Impact 74" action="Create campaign" />
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
                            @foreach ([['Your brand', 78, '34%', '+62', '+12.4%'], ['Competitor A', 71, '28%', '+54', '+10.2%'], ['Competitor B', 64, '22%', '+48', '+4.1%'], ['Competitor C', 48, '9%', '+58', '+6.0%'], ['Competitor D', 40, '7%', '+38', '-3.3%']] as [$brand, $score, $share, $mentions, $change])
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
                @foreach ([['Unify AI visibility, brand mentions and competitive moves in one place.', 'Visibility team', 'Brand monitoring'], ['Turn telemetry into workflows your team can review, approve and execute.', 'Growth team', 'Agent workflows'], ['Spot competitor movement in AI answers before it becomes a positioning gap.', 'Marketing team', 'Competitive intelligence']] as [$quote, $name, $role])
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

    <section id="pilot" class="bg-white pb-20">
        <div class="container-page">
            <div class="rounded-2xl bg-gradient-to-br from-blue to-purple px-6 py-16 text-center text-white sm:px-10">
                <h2 class="mx-auto max-w-2xl text-4xl font-semibold leading-tight tracking-tight sm:text-5xl">Join the Argusly pilot.</h2>
                <p class="mx-auto mt-4 max-w-xl text-sm leading-6 text-white/75">Leave your details and we will follow up when your pilot workspace is ready. We will share next steps after reviewing your request.</p>
                <div class="mt-8 flex flex-col items-center justify-center gap-3 sm:flex-row">
                    <x-ui.button href="{{ route('marketing.signup') }}" variant="light" shape="pill">
                        Request pilot access
                        <x-app.icon name="arrow-right" class="size-4" />
                    </x-ui.button>
                    <x-ui.button href="{{ route('marketing.contact', ['topic' => 'sales']) }}" variant="glass" shape="pill">Book demo</x-ui.button>
                </div>
                <div class="mt-8 flex flex-wrap justify-center gap-5 text-xs font-medium text-white/75">
                    <span>GDPR compliant setup</span><span>Core data on EU servers</span><span>EU-based email delivery</span><span>Transparent AI subprocessors</span>
                </div>
            </div>
        </div>
    </section>
</x-marketing.layout>
