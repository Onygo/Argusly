@extends('layouts.app', ['title' => 'Content workspace'])

@section('content')
    @php
        $activeSection = $activeSection ?? 'overview';
        $isArchived = (string) $brief->status === 'archived';
        $latestDraft = $brief->drafts->sortByDesc('created_at')->first();
        $recentComparisons = $brief->draftComparisons->sortByDesc('created_at')->values();
        $draftCompareEnabled = (bool) data_get($draftCompareCapabilities ?? [], 'enabled', true);
        $sections = [
            'overview' => ['label' => 'Overview', 'icon' => 'O', 'url' => route('app.content.workspace.overview', $brief)],
            'brief' => ['label' => 'Brief', 'icon' => 'B', 'url' => route('app.content.workspace.brief', $brief)],
            'drafts' => ['label' => 'Draft', 'icon' => 'D', 'url' => route('app.content.workspace.drafts', $brief)],
            'compare' => ['label' => 'Compare', 'icon' => 'C', 'url' => route('app.content.workspace.compare.setup', $brief)],
        ];

        $siteName = $brief->clientSite?->name ?? 'No site linked';
        $sourceLabel = $brief->source ? \Illuminate\Support\Str::headline(str_replace('_', ' ', (string) $brief->source)) : 'Manual';
        $languageLabel = strtoupper((string) ($brief->language ?: 'n/a'));
        $contentTypeLabel = \Illuminate\Support\Str::headline(str_replace('_', ' ', (string) ($brief->content_type ?: 'blog')));
        $outputTypeLabel = \Illuminate\Support\Str::headline(str_replace('_', ' ', (string) ($brief->output_type ?: 'kb_article')));
        $workspaceStateLabel = \Illuminate\Support\Str::headline(str_replace('_', ' ', (string) ($brief->status ?: 'draft')));
        $destinationLabel = $brief->contentDestination?->name
            ?? $brief->content?->contentDestination?->name
            ?? $siteName;
        $statusTone = match ((string) $brief->status) {
            'done', 'ready', 'published' => 'green',
            'archived' => 'amber',
            'failed' => 'red',
            default => 'slate',
        };

        $audienceValue = trim((string) ($brief->target_audience ?: $brief->audience ?: ''));
        $lengthLabel = match (true) {
            filled($brief->desired_length_min) && filled($brief->desired_length_max) => number_format((int) $brief->desired_length_min) . ' - ' . number_format((int) $brief->desired_length_max) . ' words',
            filled($brief->desired_length_min) => 'From ' . number_format((int) $brief->desired_length_min) . ' words',
            filled($brief->desired_length_max) => 'Up to ' . number_format((int) $brief->desired_length_max) . ' words',
            default => '',
        };

        $keyPointItems = collect(is_array($brief->key_points) ? $brief->key_points : preg_split('/\r\n|\r|\n/', (string) ($brief->key_points ?? '')))
            ->map(function ($point): string {
                return trim((string) preg_replace('/^[\-\*\•\d\.\)\s]+/', '', (string) $point));
            })
            ->filter()
            ->values();

        $structuredNoteLabels = [
            'Series' => 'series',
            'Article number' => 'article_number',
            'Chain role' => 'chain_role',
            'Supporting articles' => 'supporting_articles',
            'Slug' => 'slug',
            'Planned URL' => 'planned_url',
            'Internal links to' => 'internal_links_to',
        ];

        $chainContext = [];
        $narrativeNotes = [];
        $rawNoteLines = preg_split('/\r\n|\r|\n/', (string) ($brief->notes ?? '')) ?: [];

        foreach ($rawNoteLines as $line) {
            $trimmedLine = trim((string) $line);
            if ($trimmedLine === '') {
                continue;
            }

            $matchedLabel = null;
            foreach ($structuredNoteLabels as $displayLabel => $key) {
                if (\Illuminate\Support\Str::startsWith(\Illuminate\Support\Str::lower($trimmedLine), \Illuminate\Support\Str::lower($displayLabel) . ':')) {
                    $matchedLabel = $displayLabel;
                    $chainContext[] = [
                        'label' => $displayLabel,
                        'value' => trim((string) \Illuminate\Support\Str::after($trimmedLine, ':')),
                    ];
                    break;
                }
            }

            if ($matchedLabel === null) {
                $narrativeNotes[] = $trimmedLine;
            }
        }

        $notesText = implode("\n", $narrativeNotes);

        $completenessItems = collect([
            ['label' => 'Primary keyword', 'filled' => filled($brief->primary_keyword), 'required' => true],
            ['label' => 'Target audience', 'filled' => filled($audienceValue), 'required' => true],
            ['label' => 'Language', 'filled' => filled($brief->language), 'required' => true],
            ['label' => 'Output type', 'filled' => filled($brief->output_type), 'required' => true],
            ['label' => 'Content type', 'filled' => filled($brief->content_type), 'required' => true],
            ['label' => 'Search intent', 'filled' => filled($brief->search_intent), 'required' => true],
            ['label' => 'Funnel stage', 'filled' => filled($brief->funnel_stage), 'required' => false],
            ['label' => 'Tone of voice', 'filled' => filled($brief->tone_of_voice), 'required' => false],
            ['label' => 'Unique angle', 'filled' => filled($brief->unique_angle), 'required' => true],
            ['label' => 'Call to action', 'filled' => filled($brief->call_to_action), 'required' => true],
            ['label' => 'Key points', 'filled' => $keyPointItems->isNotEmpty(), 'required' => true],
            ['label' => 'Notes', 'filled' => filled($brief->notes), 'required' => false],
        ]);

        $filledItems = $completenessItems->where('filled', true)->count();
        $completenessScore = (int) round(($filledItems / max(1, $completenessItems->count())) * 100);
        $missingItems = $completenessItems->where('filled', false)->pluck('label')->values();
        $missingCoreItems = $completenessItems
            ->filter(fn (array $item): bool => $item['required'] && ! $item['filled'])
            ->pluck('label')
            ->values();

        if ($missingCoreItems->isEmpty()) {
            $readinessLabel = 'Ready for generation';
            $readinessTone = 'green';
            $readinessHelper = 'The core strategic inputs are in place for a strong first draft.';
        } elseif ($completenessScore >= 65) {
            $readinessLabel = 'Almost ready';
            $readinessTone = 'amber';
            $readinessHelper = 'A few strategic gaps remain before this brief is fully generation-ready.';
        } else {
            $readinessLabel = 'Needs more direction';
            $readinessTone = 'slate';
            $readinessHelper = 'Add the missing strategic inputs to strengthen the next draft.';
        }

        $intelligence = is_array($briefIntelligenceContext ?? null) ? $briefIntelligenceContext : [];
        $aiCompleteness = is_array($intelligence['completeness'] ?? null) ? $intelligence['completeness'] : [];
        $linkedResearch = is_array($intelligence['linked_research'] ?? null) ? $intelligence['linked_research'] : [];
        $linkedProject = $intelligence['linked_research_project'] ?? null;

        $quickStats = [
            ['label' => 'Drafts', 'value' => number_format((int) $brief->drafts->count()), 'icon' => 'files'],
            ['label' => 'Compare runs', 'value' => number_format((int) $recentComparisons->count()), 'icon' => 'git-compare'],
            ['label' => 'Suggestions', 'value' => number_format((int) ($brief->suggestions?->count() ?? 0)), 'icon' => 'sparkles'],
        ];
    @endphp

    @if (session('status'))
        <x-alert class="mb-4">{{ session('status') }}</x-alert>
    @endif

    @if ($errors->has('brief'))
        <div class="mb-4 rounded-lg border border-rose-500/30 bg-rose-500/10 px-4 py-3 text-sm text-rose-800">{{ $errors->first('brief') }}</div>
    @endif
    @if ($errors->has('draft_compare'))
        <div class="mb-4 rounded-lg border border-rose-500/30 bg-rose-500/10 px-4 py-3 text-sm text-rose-800">{{ $errors->first('draft_compare') }}</div>
    @endif
    @if ($errors->has('brief_intelligence'))
        <div class="mb-4 rounded-lg border border-rose-500/30 bg-rose-500/10 px-4 py-3 text-sm text-rose-800">{{ $errors->first('brief_intelligence') }}</div>
    @endif

    <section class="mb-6 overflow-hidden rounded-2xl border border-border bg-gradient-to-br from-white via-surface to-surfaceSubtle">
        <div class="border-b border-border/70 px-4 py-4 sm:px-6">
            <div class="flex flex-col gap-4 xl:flex-row xl:items-start xl:justify-between">
                <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-2 text-xs font-medium uppercase tracking-[0.18em] text-textSecondary">
                        <span>Editorial Header</span>
                        <span class="h-1 w-1 rounded-full bg-border"></span>
                        <span>{{ $siteName }}</span>
                    </div>

                    <h1 class="mt-3 break-words text-2xl font-semibold tracking-tight text-textPrimary sm:text-3xl">{{ $brief->title }}</h1>

                    <div class="mt-3 flex flex-wrap items-center gap-2 text-sm">
                        <x-status-badge :status="$brief->status" :color="$statusTone" size="sm" dot />
                        <span class="pl-badge border-amber-200 bg-amber-50 text-amber-900"><span class="pl-badge__label">Source: {{ $sourceLabel }}</span></span>
                        <span class="pl-badge border-slate-200 bg-slate-100 text-slate-700"><span class="pl-badge__label">{{ $languageLabel }}</span></span>
                        <span class="pl-badge border-emerald-200 bg-emerald-50 text-emerald-800"><span class="pl-badge__label">Workspace draft</span></span>
                    </div>

                    <div class="mt-4 grid gap-3 text-sm text-textSecondary sm:grid-cols-2 xl:grid-cols-4">
                        <div>
                            <div class="text-[11px] uppercase tracking-wide text-textFaint">Publish State</div>
                            <div class="mt-1 font-medium text-textPrimary">{{ $workspaceStateLabel }} / Draft workspace</div>
                        </div>
                        <div>
                            <div class="text-[11px] uppercase tracking-wide text-textFaint">Updated</div>
                            <div class="mt-1 font-medium text-textPrimary">{{ $brief->updated_at?->diffForHumans() ?? 'n/a' }}</div>
                        </div>
                        <div>
                            <div class="text-[11px] uppercase tracking-wide text-textFaint">Language</div>
                            <div class="mt-1 font-medium text-textPrimary">Language: {{ $languageLabel }}</div>
                        </div>
                        <div>
                            <div class="text-[11px] uppercase tracking-wide text-textFaint">Destination</div>
                            <div class="mt-1 font-medium text-textPrimary">{{ $destinationLabel }} · {{ $outputTypeLabel }}</div>
                        </div>
                    </div>
                </div>

                <div class="flex w-full flex-wrap items-center justify-start gap-2 xl:w-auto xl:max-w-[28rem] xl:justify-end">
                    @if (! $isArchived)
                        @if ($draftCompareEnabled)
                            <a href="{{ route('app.content.workspace.compare.setup', $brief) }}" class="inline-flex items-center gap-2 rounded-full border border-border bg-white px-4 py-2 text-sm font-medium text-textPrimary transition hover:bg-surfaceSubtle">
                                <i data-lucide="git-compare" class="h-4 w-4" aria-hidden="true"></i>
                                <span>Start comparison</span>
                            </a>
                        @else
                            <span class="inline-flex items-center gap-2 rounded-full border border-border bg-white px-4 py-2 text-sm text-textSecondary">
                                <i data-lucide="lock" class="h-4 w-4" aria-hidden="true"></i>
                                <span>Start comparison (Upgrade required)</span>
                            </span>
                        @endif
                    @endif

                    <a href="{{ route('app.content.workspace.brief.edit', $brief) }}" class="inline-flex items-center gap-2 rounded-full border border-border bg-white px-4 py-2 text-sm font-medium text-textPrimary transition hover:bg-surfaceSubtle">
                        <i data-lucide="square-pen" class="h-4 w-4" aria-hidden="true"></i>
                        <span>Edit brief</span>
                    </a>

                    @if (!empty($briefIntelligenceEnabled) && !empty($canEnhanceBrief))
                        <form method="POST" action="{{ route('app.content.workspace.brief.enhance', $brief) }}">
                            @csrf
                            <button class="inline-flex items-center gap-2 rounded-full border border-border bg-white px-4 py-2 text-sm font-medium text-textPrimary transition hover:bg-surfaceSubtle">
                                <i data-lucide="sparkles" class="h-4 w-4" aria-hidden="true"></i>
                                <span>Enhance brief</span>
                            </button>
                        </form>
                    @endif

                    @if (!empty($briefIntelligenceEnabled) && !empty($canCreateBriefFromResearch))
                        <a href="{{ route('app.content.create') }}" class="inline-flex items-center gap-2 rounded-full border border-border bg-white px-4 py-2 text-sm font-medium text-textPrimary transition hover:bg-surfaceSubtle">
                            <i data-lucide="flask-conical" class="h-4 w-4" aria-hidden="true"></i>
                            <span>Create from research</span>
                        </a>
                    @endif

                    @if (! $isArchived)
                        <form method="POST" action="{{ route('app.content.workspace.archive', $brief) }}" onsubmit="return confirm('Archive this content?')">
                            @csrf
                            <button class="inline-flex items-center gap-2 rounded-full border border-rose-300 bg-white px-4 py-2 text-sm font-medium text-rose-700 transition hover:bg-rose-50">
                                <i data-lucide="archive" class="h-4 w-4" aria-hidden="true"></i>
                                <span>Archive</span>
                            </button>
                        </form>
                    @endif
                </div>
            </div>
        </div>

        <div class="border-t border-border/70 bg-white/90 px-4 py-3 sm:px-6">
            <div class="pl-tab-scroll">
                <div class="pl-tab-scroll__inner text-sm">
                @foreach ($sections as $key => $section)
                    <a
                        href="{{ $section['url'] }}"
                        class="inline-flex items-center gap-2 rounded-xl px-3 py-2 font-medium transition {{ $activeSection === $key ? 'bg-textPrimary text-white' : 'text-textSecondary hover:bg-surfaceSubtle hover:text-textPrimary' }}"
                    >
                        <span class="inline-flex h-6 w-6 items-center justify-center rounded-full {{ $activeSection === $key ? 'bg-white/15 text-white' : 'bg-slate-100 text-slate-600' }}">{{ $section['icon'] }}</span>
                        <span>{{ $section['label'] }}</span>
                    </a>
                @endforeach
                </div>
            </div>
        </div>
    </section>

    @if ($activeSection === 'overview')
        <div class="mt-6 grid gap-6 xl:grid-cols-[minmax(0,1.8fr)_minmax(320px,1fr)]">
            <div class="space-y-6">
                <section class="rounded-lg border border-border bg-surface p-6">
                    <div class="flex items-center gap-2">
                        <span class="inline-flex h-10 w-10 items-center justify-center rounded-lg border border-border bg-background text-textSecondary">
                            <i data-lucide="scroll-text" class="h-4 w-4" aria-hidden="true"></i>
                        </span>
                        <div>
                            <h2 class="text-lg font-semibold text-textPrimary">Brief summary</h2>
                            <p class="text-sm text-textSecondary">Core brief settings and strategic inputs at a glance.</p>
                        </div>
                    </div>

                    <div class="mt-6 grid gap-6 md:grid-cols-2">
                        <x-brief-workspace.detail-item label="Primary keyword" :value="$brief->primary_keyword" placeholder="No primary keyword defined yet" />
                        <x-brief-workspace.detail-item label="Target audience" :value="$audienceValue" placeholder="No target audience defined yet" />
                        <x-brief-workspace.detail-item label="Search intent" :value="$brief->search_intent" placeholder="Search intent is not defined yet" />
                        <x-brief-workspace.detail-item label="Funnel stage" :value="$brief->funnel_stage" placeholder="Funnel stage is not defined yet" />
                        <x-brief-workspace.detail-item label="Tone of voice" :value="$brief->tone_of_voice" placeholder="Tone of voice is not defined yet" />
                        <x-brief-workspace.detail-item label="Output size" :value="$lengthLabel" placeholder="Output length is not defined yet" />
                    </div>
                </section>

                <div class="grid gap-6 lg:grid-cols-2">
                    <section class="rounded-lg border border-border bg-gradient-to-br from-surface to-orange-50/40 p-6">
                        <div class="flex items-center gap-2">
                            <span class="inline-flex h-10 w-10 items-center justify-center rounded-lg border border-border bg-background text-textSecondary">
                                <i data-lucide="compass" class="h-4 w-4" aria-hidden="true"></i>
                            </span>
                            <div>
                                <h3 class="text-base font-semibold text-textPrimary">Unique angle</h3>
                                <p class="text-sm text-textSecondary">What makes this brief strategically distinct.</p>
                            </div>
                        </div>
                        <div class="mt-4 text-sm leading-7 text-textPrimary">
                            @if (filled($brief->unique_angle))
                                <p class="whitespace-pre-wrap">{{ $brief->unique_angle }}</p>
                            @else
                                <x-brief-workspace.empty-state message="No unique angle defined yet" :action-href="route('app.content.workspace.brief.edit', $brief)" />
                            @endif
                        </div>
                    </section>

                    <section class="rounded-lg border border-border bg-gradient-to-br from-surface to-sky-50/40 p-6">
                        <div class="flex items-center gap-2">
                            <span class="inline-flex h-10 w-10 items-center justify-center rounded-lg border border-border bg-background text-textSecondary">
                                <i data-lucide="send" class="h-4 w-4" aria-hidden="true"></i>
                            </span>
                            <div>
                                <h3 class="text-base font-semibold text-textPrimary">Call to action</h3>
                                <p class="text-sm text-textSecondary">The action or next step the draft should drive.</p>
                            </div>
                        </div>
                        <div class="mt-4 text-sm leading-7 text-textPrimary">
                            @if (filled($brief->call_to_action))
                                <p class="whitespace-pre-wrap">{{ $brief->call_to_action }}</p>
                            @else
                                <x-brief-workspace.empty-state message="No CTA added yet" :action-href="route('app.content.workspace.brief.edit', $brief)" />
                            @endif
                        </div>
                    </section>
                </div>

                <section class="rounded-lg border border-border bg-surface p-6">
                    <div class="flex items-center justify-between gap-3">
                        <div class="flex items-center gap-2">
                            <span class="inline-flex h-10 w-10 items-center justify-center rounded-lg border border-border bg-background text-textSecondary">
                                <i data-lucide="file-pen" class="h-4 w-4" aria-hidden="true"></i>
                            </span>
                            <div>
                                <h3 class="text-base font-semibold text-textPrimary">Latest draft</h3>
                                <p class="text-sm text-textSecondary">Jump back into the most recent draft revision.</p>
                            </div>
                        </div>
                        @if ($latestDraft)
                            <a href="{{ route('app.drafts.show', $latestDraft) }}" class="inline-flex items-center gap-2 rounded-lg border border-border px-3 py-2 text-sm font-medium text-textPrimary hover:bg-surfaceSubtle">
                                <i data-lucide="arrow-up-right" class="h-4 w-4" aria-hidden="true"></i>
                                <span>Open draft</span>
                            </a>
                        @endif
                    </div>

                    <div class="mt-5">
                        @if ($latestDraft)
                            <div class="rounded-lg border border-border bg-background px-4 py-4">
                                <div class="text-sm font-medium text-textPrimary">{{ $latestDraft->title ?: 'Untitled draft' }}</div>
                                <div class="mt-1 text-xs text-textSecondary">{{ $latestDraft->status }} · {{ optional($latestDraft->updated_at)->format('Y-m-d H:i') }}</div>
                            </div>
                        @else
                            <x-brief-workspace.empty-state message="No draft generated yet" />
                        @endif
                    </div>
                </section>
            </div>

            <aside class="space-y-6">
                <x-brief-workspace.sidebar-card title="Generate draft" icon="wand-sparkles" subtitle="Move from strategy into production." tone="primary">
                    @if (! $isArchived)
                        @include('app.briefs.partials.workspace-generate-draft-form', [
                            'brief' => $brief,
                            'outputTokenOptions' => $outputTokenOptions,
                            'estimatedCredits' => $estimatedCredits,
                            'maxCredits' => $maxCredits,
                            'formAction' => route('app.content.workspace.drafts.generate', $brief),
                            'buttonLabel' => 'Generate draft',
                            'inputIdPrefix' => 'workspace_requested_max_output_tokens',
                        ])
                    @else
                        <x-brief-workspace.empty-state message="Archived content cannot generate drafts." tone="primary" />
                    @endif
                </x-brief-workspace.sidebar-card>

                <x-brief-workspace.sidebar-card title="Recent compare runs" icon="git-compare" subtitle="Model evaluations and compare history.">
                    @include('app.briefs.partials.workspace-compare-runs', [
                        'brief' => $brief,
                        'comparisons' => $recentComparisons,
                    ])
                </x-brief-workspace.sidebar-card>
            </aside>
        </div>
    @elseif ($activeSection === 'brief')
        <div class="mt-6 grid gap-6 xl:grid-cols-[minmax(0,1.9fr)_minmax(320px,1fr)]">
            <main class="space-y-6">
                <section class="rounded-lg border border-border bg-surface p-6">
                    <div class="flex items-start justify-between gap-4">
                        <div class="flex items-center gap-3">
                            <span class="inline-flex h-11 w-11 items-center justify-center rounded-lg border border-border bg-background text-textSecondary">
                                <i data-lucide="scroll-text" class="h-5 w-5" aria-hidden="true"></i>
                            </span>
                            <div>
                                <h2 class="text-lg font-semibold text-textPrimary">Brief summary</h2>
                                <p class="text-sm text-textSecondary">A structured content strategy snapshot for editors and marketers.</p>
                            </div>
                        </div>

                        <a href="{{ route('app.content.workspace.brief.edit', $brief) }}" class="inline-flex items-center gap-2 rounded-lg border border-border px-3 py-2 text-sm font-medium text-textPrimary hover:bg-surfaceSubtle">
                            <i data-lucide="square-pen" class="h-4 w-4" aria-hidden="true"></i>
                            <span>Edit brief</span>
                        </a>
                    </div>

                    <div class="mt-6 grid gap-6 lg:grid-cols-2">
                        <div class="rounded-lg border border-border bg-gradient-to-br from-surface to-orange-50/40 p-5">
                            <div class="flex items-center gap-2">
                                <span class="inline-flex h-9 w-9 items-center justify-center rounded-lg border border-border bg-background text-textSecondary">
                                    <i data-lucide="target" class="h-4 w-4" aria-hidden="true"></i>
                                </span>
                                <div>
                                    <h3 class="text-base font-semibold text-textPrimary">Strategy</h3>
                                    <p class="text-sm text-textSecondary">Audience, intent, and narrative direction.</p>
                                </div>
                            </div>

                            <div class="mt-5 grid gap-5 sm:grid-cols-2">
                                <x-brief-workspace.detail-item label="Primary keyword" :value="$brief->primary_keyword" placeholder="Primary keyword not defined yet" />
                                <x-brief-workspace.detail-item label="Funnel stage" :value="$brief->funnel_stage" placeholder="Funnel stage not defined yet" />
                                <x-brief-workspace.detail-item label="Search intent" :value="$brief->search_intent" placeholder="Search intent not defined yet" />
                                <x-brief-workspace.detail-item label="Target audience" :value="$audienceValue" placeholder="Target audience not defined yet" />
                                <x-brief-workspace.detail-item class="sm:col-span-2" label="Tone of voice" :value="$brief->tone_of_voice" placeholder="Tone of voice not defined yet" />
                            </div>
                        </div>

                        <div class="rounded-lg border border-border bg-gradient-to-br from-surface to-sky-50/40 p-5">
                            <div class="flex items-center gap-2">
                                <span class="inline-flex h-9 w-9 items-center justify-center rounded-lg border border-border bg-background text-textSecondary">
                                    <i data-lucide="settings-2" class="h-4 w-4" aria-hidden="true"></i>
                                </span>
                                <div>
                                    <h3 class="text-base font-semibold text-textPrimary">Production</h3>
                                    <p class="text-sm text-textSecondary">Format, channel, and generation parameters.</p>
                                </div>
                            </div>

                            <div class="mt-5 grid gap-5 sm:grid-cols-2">
                                <x-brief-workspace.detail-item label="Language" :value="$languageLabel" placeholder="Language not defined yet" />
                                <x-brief-workspace.detail-item label="Output type" :value="$outputTypeLabel" placeholder="Output type not defined yet" />
                                <x-brief-workspace.detail-item label="Output size" :value="$lengthLabel" placeholder="Output size not defined yet" />
                                <x-brief-workspace.detail-item label="Site" :value="$siteName" placeholder="Site not linked yet" />
                                <x-brief-workspace.detail-item class="sm:col-span-2" label="Content type" :value="$contentTypeLabel" placeholder="Content type not defined yet" />
                            </div>
                        </div>
                    </div>
                </section>

                <section class="rounded-lg border border-border bg-surface p-6">
                    <div class="flex items-center gap-3">
                        <span class="inline-flex h-11 w-11 items-center justify-center rounded-lg border border-border bg-background text-textSecondary">
                            <i data-lucide="compass" class="h-5 w-5" aria-hidden="true"></i>
                        </span>
                        <div>
                            <h2 class="text-lg font-semibold text-textPrimary">Content direction</h2>
                            <p class="text-sm text-textSecondary">Message framing, conversion intent, and editorial guidance.</p>
                        </div>
                    </div>

                    <div class="mt-6 grid gap-6 lg:grid-cols-2">
                        <div class="rounded-lg border border-border bg-gradient-to-br from-surface to-orange-50/50 p-5">
                            <div class="flex items-center gap-2">
                                <i data-lucide="sparkles" class="h-4 w-4 text-textSecondary" aria-hidden="true"></i>
                                <h3 class="text-base font-semibold text-textPrimary">Unique angle</h3>
                            </div>
                            <div class="mt-4 text-sm leading-7 text-textPrimary">
                                @if (filled($brief->unique_angle))
                                    <p class="whitespace-pre-wrap">{{ $brief->unique_angle }}</p>
                                @else
                                    <x-brief-workspace.empty-state message="No unique angle defined yet" :action-href="route('app.content.workspace.brief.edit', $brief)" tone="warm" />
                                @endif
                            </div>
                        </div>

                        <div class="rounded-lg border border-border bg-gradient-to-br from-surface to-sky-50/50 p-5">
                            <div class="flex items-center gap-2">
                                <i data-lucide="send" class="h-4 w-4 text-textSecondary" aria-hidden="true"></i>
                                <h3 class="text-base font-semibold text-textPrimary">Call to action</h3>
                            </div>
                            <div class="mt-4 text-sm leading-7 text-textPrimary">
                                @if (filled($brief->call_to_action))
                                    <p class="whitespace-pre-wrap">{{ $brief->call_to_action }}</p>
                                @else
                                    <x-brief-workspace.empty-state message="No CTA added yet" :action-href="route('app.content.workspace.brief.edit', $brief)" />
                                @endif
                            </div>
                        </div>
                    </div>
                </section>

                <section class="rounded-lg border border-border bg-surface p-6">
                    <div class="flex items-center gap-3">
                        <span class="inline-flex h-11 w-11 items-center justify-center rounded-lg border border-border bg-background text-textSecondary">
                            <i data-lucide="list-todo" class="h-5 w-5" aria-hidden="true"></i>
                        </span>
                        <div>
                            <h2 class="text-lg font-semibold text-textPrimary">Key points</h2>
                            <p class="text-sm text-textSecondary">The most important ideas the draft should cover.</p>
                        </div>
                    </div>

                    <div class="mt-5">
                        @if ($keyPointItems->isNotEmpty())
                            <ul class="space-y-3">
                                @foreach ($keyPointItems as $point)
                                    <li class="flex items-start gap-3 rounded-lg border border-border bg-background px-4 py-3">
                                        <span class="mt-1 inline-flex h-5 w-5 items-center justify-center rounded-full bg-textPrimary text-[11px] font-semibold text-white">{{ $loop->iteration }}</span>
                                        <span class="text-sm leading-6 text-textPrimary">{{ $point }}</span>
                                    </li>
                                @endforeach
                            </ul>
                        @else
                            <x-brief-workspace.empty-state message="No key points added yet" :action-href="route('app.content.workspace.brief.edit', $brief)" />
                        @endif
                    </div>
                </section>

                @if (collect($chainContext)->isNotEmpty())
                    <section class="rounded-lg border border-border bg-gradient-to-br from-amber-50/70 via-surface to-background p-6">
                        <div class="flex items-center gap-3">
                            <span class="inline-flex h-11 w-11 items-center justify-center rounded-lg border border-border bg-background text-textSecondary">
                                <i data-lucide="network" class="h-5 w-5" aria-hidden="true"></i>
                            </span>
                            <div>
                                <h2 class="text-lg font-semibold text-textPrimary">Chain context</h2>
                                <p class="text-sm text-textSecondary">Structured planning notes extracted from the editorial brief.</p>
                            </div>
                        </div>

                        <div class="mt-5 grid gap-4 sm:grid-cols-2">
                            @foreach ($chainContext as $item)
                                <div class="rounded-lg border border-border bg-background/80 px-4 py-4">
                                    <div class="text-[11px] font-semibold uppercase tracking-[0.14em] text-textSecondary">{{ $item['label'] }}</div>
                                    <div class="mt-1 text-sm font-medium leading-6 text-textPrimary">{{ $item['value'] !== '' ? $item['value'] : 'Not defined yet' }}</div>
                                </div>
                            @endforeach
                        </div>
                    </section>
                @endif

                <section class="rounded-lg border border-border bg-gradient-to-br from-amber-50/50 via-surface to-background p-6">
                    <div class="flex items-center gap-3">
                        <span class="inline-flex h-11 w-11 items-center justify-center rounded-lg border border-border bg-background text-textSecondary">
                            <i data-lucide="notebook-pen" class="h-5 w-5" aria-hidden="true"></i>
                        </span>
                        <div>
                            <h2 class="text-lg font-semibold text-textPrimary">Editorial notes</h2>
                            <p class="text-sm text-textSecondary">Planning notes, supporting context, and briefing guidance.</p>
                        </div>
                    </div>

                    <div class="mt-5">
                        @if ($notesText !== '')
                            <div class="rounded-lg border border-border bg-background/80 px-5 py-5">
                                <p class="whitespace-pre-wrap text-sm leading-7 text-textPrimary">{{ $notesText }}</p>
                            </div>
                        @elseif (filled($brief->notes))
                            <div class="rounded-lg border border-border bg-background/80 px-5 py-5">
                                <p class="whitespace-pre-wrap text-sm leading-7 text-textPrimary">{{ $brief->notes }}</p>
                            </div>
                        @else
                            <x-brief-workspace.empty-state message="No editorial notes added yet" :action-href="route('app.content.workspace.brief.edit', $brief)" tone="warm" />
                        @endif
                    </div>
                </section>

                @if (!empty($briefIntelligenceEnabled))
                    <section class="rounded-lg border border-border bg-surface p-6">
                        @php
                            $suggestions = $brief->suggestions ?? collect();
                        @endphp
                        <div class="mb-4 flex items-center justify-between gap-3">
                            <div class="flex items-center gap-3">
                                <span class="inline-flex h-11 w-11 items-center justify-center rounded-lg border border-border bg-background text-textSecondary">
                                    <i data-lucide="sparkles" class="h-5 w-5" aria-hidden="true"></i>
                                </span>
                                <div>
                                    <h2 class="text-lg font-semibold text-textPrimary">AI suggestions</h2>
                                    <p class="text-sm text-textSecondary">Refinement proposals for improving the brief before generation.</p>
                                </div>
                            </div>
                            <span class="rounded-full border border-border bg-background px-3 py-1 text-xs font-medium text-textSecondary">{{ $suggestions->count() }} total</span>
                        </div>

                        <div class="space-y-3">
                            @forelse ($suggestions->take(40) as $suggestion)
                                @php
                                    $format = (string) data_get($suggestion->meta, 'value_format', 'text');
                                    $rawCurrent = trim((string) ($suggestion->original_value ?? ''));
                                    $rawSuggested = trim((string) ($suggestion->suggested_value ?? ''));
                                    $currentValues = $format === 'json' ? ((is_array(json_decode($rawCurrent, true)) ? json_decode($rawCurrent, true) : [])) : [];
                                    $suggestedValues = $format === 'json' ? ((is_array(json_decode($rawSuggested, true)) ? json_decode($rawSuggested, true) : [])) : [];
                                @endphp
                                <div class="rounded-lg border border-border bg-background p-4">
                                    <div class="mb-3 flex items-center justify-between gap-2">
                                        <p class="text-sm font-semibold text-textPrimary">{{ \Illuminate\Support\Str::headline((string) $suggestion->suggestion_type) }}</p>
                                        <span class="rounded-full border border-border bg-surface px-2.5 py-1 text-[11px] uppercase tracking-wide text-textSecondary">{{ $suggestion->status }}</span>
                                    </div>
                                    @if ($format === 'json')
                                        <div class="grid gap-4 text-xs md:grid-cols-2">
                                            <div class="rounded-lg border border-border bg-surface px-3 py-3">
                                                <p class="font-semibold uppercase tracking-[0.14em] text-textSecondary">Current</p>
                                                <ul class="mt-2 list-disc space-y-1 pl-4 text-sm text-textPrimary">
                                                    @forelse ($currentValues as $item)
                                                        <li>{{ $item }}</li>
                                                    @empty
                                                        <li class="italic text-textSecondary">Not defined yet</li>
                                                    @endforelse
                                                </ul>
                                            </div>
                                            <div class="rounded-lg border border-border bg-surface px-3 py-3">
                                                <p class="font-semibold uppercase tracking-[0.14em] text-textSecondary">Suggested</p>
                                                <ul class="mt-2 list-disc space-y-1 pl-4 text-sm text-textPrimary">
                                                    @foreach ($suggestedValues as $item)
                                                        <li>{{ $item }}</li>
                                                    @endforeach
                                                </ul>
                                            </div>
                                        </div>
                                    @else
                                        <div class="grid gap-4 md:grid-cols-2">
                                            <div class="rounded-lg border border-border bg-surface px-3 py-3">
                                                <p class="text-[11px] font-semibold uppercase tracking-[0.14em] text-textSecondary">Current</p>
                                                <p class="mt-2 text-sm leading-6 text-textPrimary">{{ $rawCurrent !== '' ? $rawCurrent : 'Not defined yet' }}</p>
                                            </div>
                                            <div class="rounded-lg border border-border bg-surface px-3 py-3">
                                                <p class="text-[11px] font-semibold uppercase tracking-[0.14em] text-textSecondary">Suggested</p>
                                                <p class="mt-2 text-sm leading-6 text-textPrimary">{{ $rawSuggested }}</p>
                                            </div>
                                        </div>
                                    @endif
                                    @if (!empty($suggestion->rationale))
                                        <p class="mt-3 text-xs leading-5 text-textSecondary">{{ $suggestion->rationale }}</p>
                                    @endif
                                    @if ((string) $suggestion->status === 'pending' && !empty($canManageBriefSuggestions))
                                        <div class="mt-3 flex flex-wrap items-center gap-2">
                                            <form method="POST" action="{{ route('app.content.workspace.brief.suggestions.apply', [$brief, $suggestion->id]) }}">
                                                @csrf
                                                <button class="rounded-lg border border-border bg-background px-3 py-2 text-xs font-medium text-textPrimary hover:bg-surfaceSubtle">Apply</button>
                                            </form>
                                            <form method="POST" action="{{ route('app.content.workspace.brief.suggestions.reject', [$brief, $suggestion->id]) }}">
                                                @csrf
                                                <button class="rounded-lg border border-border px-3 py-2 text-xs font-medium text-textPrimary hover:bg-surfaceSubtle">Reject</button>
                                            </form>
                                        </div>
                                    @endif
                                </div>
                            @empty
                                <x-brief-workspace.empty-state message="No AI suggestions yet" />
                            @endforelse
                        </div>
                    </section>
                @endif
            </main>

            <aside class="space-y-6">
                <div class="space-y-6 xl:sticky xl:top-6">
                    <x-brief-workspace.sidebar-card title="Generate draft" icon="wand-sparkles" subtitle="Choose the output size and launch the next draft revision." tone="primary">
                        @if (! $isArchived)
                            @include('app.briefs.partials.workspace-generate-draft-form', [
                                'brief' => $brief,
                                'outputTokenOptions' => $outputTokenOptions,
                                'estimatedCredits' => $estimatedCredits,
                                'maxCredits' => $maxCredits,
                                'formAction' => route('app.content.workspace.drafts.generate', $brief),
                                'buttonLabel' => 'Generate draft',
                                'inputIdPrefix' => 'brief_requested_max_output_tokens',
                            ])
                        @else
                            <x-brief-workspace.empty-state message="Archived content cannot generate drafts." tone="primary" />
                        @endif
                    </x-brief-workspace.sidebar-card>

                    <x-brief-workspace.sidebar-card title="Brief completeness" icon="gauge" subtitle="A simple readiness indicator based on the current brief inputs." tone="sky">
                        <div class="flex items-start justify-between gap-4">
                            <div>
                                <div class="text-3xl font-semibold tracking-tight text-textPrimary">{{ $completenessScore }}%</div>
                                <div class="mt-1 text-sm text-textSecondary">{{ $filledItems }} of {{ $completenessItems->count() }} inputs defined</div>
                            </div>
                            <x-status-badge :label="$readinessLabel" :color="$readinessTone" size="sm" />
                        </div>

                        <div class="mt-4 h-2 overflow-hidden rounded-full bg-background">
                            <div class="h-full rounded-full bg-textPrimary transition-all" style="width: {{ $completenessScore }}%"></div>
                        </div>

                        <p class="mt-4 text-sm leading-6 text-textSecondary">{{ $readinessHelper }}</p>

                        @if ($missingItems->isNotEmpty())
                            <div class="mt-4 rounded-lg border border-border bg-background px-4 py-4">
                                <div class="text-[11px] font-semibold uppercase tracking-[0.14em] text-textSecondary">Missing</div>
                                <div class="mt-2 flex flex-wrap gap-2">
                                    @foreach ($missingItems->take(6) as $item)
                                        <span class="rounded-full border border-border bg-surface px-2.5 py-1 text-xs text-textSecondary">{{ $item }}</span>
                                    @endforeach
                                </div>
                            </div>
                        @endif

                        @if (!empty($aiCompleteness['recommendation']))
                            <div class="mt-4 rounded-lg border border-border bg-background px-4 py-4 text-sm leading-6 text-textSecondary">
                                {{ $aiCompleteness['recommendation'] }}
                            </div>
                        @endif
                    </x-brief-workspace.sidebar-card>

                    <x-brief-workspace.sidebar-card title="Output settings" icon="sliders-horizontal" subtitle="Production settings and delivery shape.">
                        <div class="grid gap-4">
                            <x-brief-workspace.detail-item label="Output type" :value="$outputTypeLabel" placeholder="Output type not defined yet" />
                            <x-brief-workspace.detail-item label="Output size" :value="$lengthLabel" placeholder="No output size defined yet" />
                            <x-brief-workspace.detail-item label="Content type" :value="$contentTypeLabel" placeholder="No content type defined yet" />
                            <x-brief-workspace.detail-item label="Language" :value="$languageLabel" placeholder="Language not defined yet" />
                        </div>
                    </x-brief-workspace.sidebar-card>

                    <x-brief-workspace.sidebar-card title="Content metadata" icon="info" subtitle="Workspace context and editorial ownership.">
                        <div class="grid gap-4">
                            <x-brief-workspace.detail-item label="Site" :value="$siteName" />
                            <x-brief-workspace.detail-item label="Source" :value="$sourceLabel" />
                            <x-brief-workspace.detail-item label="Created by" :value="$brief->creator?->name" placeholder="Creator not available" />
                            <x-brief-workspace.detail-item label="Created" :value="optional($brief->created_at)->format('Y-m-d H:i')" placeholder="No timestamp available" />
                            <x-brief-workspace.detail-item label="Updated" :value="optional($brief->updated_at)->diffForHumans()" placeholder="No recent updates recorded" />
                        </div>
                    </x-brief-workspace.sidebar-card>

                    <x-brief-workspace.sidebar-card title="Quick stats" icon="chart-column" subtitle="Signals around this content workspace." tone="warm">
                        <div class="grid gap-3">
                            @foreach ($quickStats as $stat)
                                <div class="flex items-center justify-between gap-3 rounded-lg border border-border bg-background px-4 py-3">
                                    <div class="flex items-center gap-2">
                                        <i data-lucide="{{ $stat['icon'] }}" class="h-4 w-4 text-textSecondary" aria-hidden="true"></i>
                                        <span class="text-sm text-textSecondary">{{ $stat['label'] }}</span>
                                    </div>
                                    <span class="text-sm font-semibold text-textPrimary">{{ $stat['value'] }}</span>
                                </div>
                            @endforeach
                        </div>
                    </x-brief-workspace.sidebar-card>

                    @if ($linkedProject || !empty($linkedResearch))
                        <x-brief-workspace.sidebar-card title="Research context" icon="flask-conical" subtitle="Linked project or source material informing this brief.">
                            <div class="grid gap-4">
                                <x-brief-workspace.detail-item label="Project" :value="$linkedProject?->name ?? ($linkedResearch['project_name'] ?? null)" placeholder="No linked project" />
                                @if ($linkedProject)
                                    <x-brief-workspace.detail-item label="Research status" :value="strtoupper((string) ($linkedProject->status?->value ?? $linkedProject->status))" />
                                    <a href="{{ route('app.research.show', $linkedProject) }}" class="inline-flex items-center gap-2 rounded-lg border border-border px-3 py-2 text-sm font-medium text-textPrimary hover:bg-surfaceSubtle">
                                        <i data-lucide="arrow-up-right" class="h-4 w-4" aria-hidden="true"></i>
                                        <span>Open project</span>
                                    </a>
                                @endif
                            </div>
                        </x-brief-workspace.sidebar-card>
                    @endif
                </div>
            </aside>
        </div>
    @elseif ($activeSection === 'drafts')
        <div class="mt-6 grid gap-6 xl:grid-cols-[minmax(0,1.8fr)_minmax(320px,1fr)]">
            <div class="rounded-lg border border-border bg-surface p-6">
                <div class="flex items-center gap-3">
                    <span class="inline-flex h-11 w-11 items-center justify-center rounded-lg border border-border bg-background text-textSecondary">
                        <i data-lucide="files" class="h-5 w-5" aria-hidden="true"></i>
                    </span>
                    <div>
                        <h2 class="text-lg font-semibold text-textPrimary">Drafts</h2>
                        <p class="text-sm text-textSecondary">Generated revisions for this brief.</p>
                    </div>
                </div>

                <div class="mt-5 space-y-3">
                    @forelse ($brief->drafts->sortByDesc('created_at') as $draft)
                        <a class="block rounded-lg border border-border bg-background px-4 py-4 text-sm text-textPrimary transition hover:bg-surfaceSubtle" href="{{ route('app.drafts.show', $draft) }}">
                            <div class="font-medium">{{ $draft->title ?: 'Untitled draft' }}</div>
                            <div class="mt-1 text-xs text-textSecondary">{{ $draft->status }} · {{ $draft->created_at?->format('Y-m-d H:i') }}</div>
                        </a>
                    @empty
                        <x-brief-workspace.empty-state message="No drafts yet" />
                    @endforelse
                </div>
            </div>

            <div class="space-y-6">
                <x-brief-workspace.sidebar-card title="Generate draft" icon="wand-sparkles" subtitle="Choose output size and generate the next revision." tone="primary">
                    @if (! $isArchived)
                        @include('app.briefs.partials.workspace-generate-draft-form', [
                            'brief' => $brief,
                            'outputTokenOptions' => $outputTokenOptions,
                            'estimatedCredits' => $estimatedCredits,
                            'maxCredits' => $maxCredits,
                            'formAction' => route('app.content.workspace.drafts.generate', $brief),
                            'buttonLabel' => 'Generate draft',
                            'inputIdPrefix' => 'drafts_requested_max_output_tokens',
                        ])
                    @else
                        <x-brief-workspace.empty-state message="Archived content cannot generate drafts." tone="primary" />
                    @endif
                </x-brief-workspace.sidebar-card>

                <x-brief-workspace.sidebar-card title="Latest draft" icon="file-pen" subtitle="Quick access to the current working draft.">
                    @if ($latestDraft)
                        <div class="rounded-lg border border-border bg-background px-4 py-4">
                            <div class="text-sm font-medium text-textPrimary">{{ $latestDraft->title ?: 'Untitled draft' }}</div>
                            <div class="mt-1 text-xs text-textSecondary">{{ $latestDraft->status }} · {{ optional($latestDraft->updated_at)->format('Y-m-d H:i') }}</div>
                        </div>
                        <a href="{{ route('app.drafts.show', $latestDraft) }}" class="mt-4 inline-flex items-center gap-2 rounded-lg border border-border px-3 py-2 text-sm font-medium text-textPrimary hover:bg-surfaceSubtle">
                            <i data-lucide="arrow-up-right" class="h-4 w-4" aria-hidden="true"></i>
                            <span>Open draft</span>
                        </a>
                    @else
                        <x-brief-workspace.empty-state message="No draft generated yet" />
                    @endif
                </x-brief-workspace.sidebar-card>
            </div>
        </div>
    @else
        <div class="mt-6 grid gap-6 xl:grid-cols-[minmax(0,1.8fr)_minmax(320px,1fr)]">
            <div class="rounded-lg border border-border bg-surface p-6">
                <div class="flex items-center gap-3">
                    <span class="inline-flex h-11 w-11 items-center justify-center rounded-lg border border-border bg-background text-textSecondary">
                        <i data-lucide="git-compare" class="h-5 w-5" aria-hidden="true"></i>
                    </span>
                    <div>
                        <h2 class="text-lg font-semibold text-textPrimary">Comparison setup</h2>
                        <p class="text-sm text-textSecondary">Compare drafts from multiple models and evaluate quality, SEO, and brand fit.</p>
                    </div>
                </div>

                @if ($draftCompareEnabled)
                    <a href="{{ route('app.content.workspace.compare.setup', $brief) }}" class="mt-5 inline-flex items-center gap-2 rounded-lg border border-border px-4 py-3 text-sm font-medium text-textPrimary hover:bg-surfaceSubtle">
                        <i data-lucide="git-compare" class="h-4 w-4" aria-hidden="true"></i>
                        <span>Start comparison</span>
                    </a>
                @else
                    <div class="mt-5 rounded-lg border border-border bg-background px-4 py-4 text-sm text-textSecondary">
                        Comparison is not available on your current plan.
                    </div>
                @endif
            </div>

            <x-brief-workspace.sidebar-card title="Recent compare runs" icon="history" subtitle="Latest model evaluations for this brief.">
                @include('app.briefs.partials.workspace-compare-runs', [
                    'brief' => $brief,
                    'comparisons' => $recentComparisons,
                ])
            </x-brief-workspace.sidebar-card>
        </div>
    @endif

    <script>
        (() => {
            const map = {
                '{{ (int) $outputTokenOptions['standard'] }}': {{ (int) ($estimatedCredits['standard'] ?? 10) }},
                '{{ (int) $outputTokenOptions['long'] }}': {{ (int) ($estimatedCredits['long'] ?? 12) }},
                '{{ (int) $outputTokenOptions['max'] }}': {{ (int) ($estimatedCredits['max'] ?? (int) $maxCredits) }},
            };

            const update = (select) => {
                const label = select.closest('form')?.querySelector('[data-credit-preview-label]');
                if (!label) {
                    return;
                }
                label.textContent = String(map[select.value] ?? {{ (int) ($estimatedCredits['standard'] ?? 10) }});
            };

            document.querySelectorAll('[data-credit-preview-select]').forEach((select) => {
                update(select);
                select.addEventListener('change', () => update(select));
            });
        })();
    </script>
@endsection
