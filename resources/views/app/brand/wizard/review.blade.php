@extends('layouts.app', ['title' => 'Review AI Brand Setup'])

@section('content')
    @php
        $sectionsCount = count($generatedSections ?? []);
        $canApply = $isReady && $sectionsCount > 0;
        $statusLabel = ucfirst(str_replace('_', ' ', (string) $run->status));
    @endphp

    <div class="mb-6">
        <nav class="mb-2 text-sm text-textSecondary">
            <a href="{{ route('app.brand.company-profile') }}" class="hover:text-textPrimary">Brand</a>
            <span class="mx-1">/</span>
            <a href="{{ route('app.brand.wizard') }}" class="hover:text-textPrimary">Generate with AI</a>
            <span class="mx-1">/</span>
            <span class="text-textPrimary">Review</span>
        </nav>
        <div class="flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">
            <div>
                <h1 class="text-2xl font-semibold tracking-tight text-textPrimary">Review AI brand setup</h1>
                <p class="mt-1 text-textSecondary">Check the generated context, choose which sections to apply, and keep manual editing available afterwards.</p>
            </div>
            <div class="text-sm text-textSecondary">
                Run {{ substr((string) $run->id, 0, 8) }} · {{ $statusLabel }}
            </div>
        </div>
    </div>

    @if ($errors->any())
        <x-alert variant="error" class="mb-4">
            <ul class="list-disc list-inside">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </x-alert>
    @endif

    <div
        class="space-y-6"
        data-brand-wizard-root
        data-status="{{ $run->status }}"
        data-progress="{{ (float) $run->progress }}"
        data-status-url="{{ route('app.brand.wizard.status', $run) }}"
        data-review-url="{{ route('app.brand.wizard.review', $run) }}"
        data-terminal-statuses='@json([\App\Models\EnrichmentRun::STATUS_COMPLETED, \App\Models\EnrichmentRun::STATUS_COMPLETED_EMPTY, \App\Models\EnrichmentRun::STATUS_FAILED])'
    >
        @if ($isProcessing)
            <div class="rounded-lg border border-border bg-surface p-6" data-brand-wizard-processing>
                <div class="flex items-start gap-4">
                    <div class="relative mt-1 h-10 w-10 shrink-0">
                        <div class="absolute inset-0 rounded-full border-2 border-primary/15"></div>
                        <div class="absolute inset-0 rounded-full border-2 border-transparent border-t-primary animate-spin"></div>
                        <div class="absolute inset-[7px] rounded-full bg-primary/10 animate-pulse"></div>
                    </div>
                    <div class="min-w-0 flex-1">
                        <h2 class="text-lg font-semibold text-textPrimary" data-brand-wizard-message-title>AI is preparing your brand setup</h2>
                        <p class="mt-1 text-sm text-textSecondary" data-brand-wizard-message>{{ $statusMessage }}</p>

                        <div class="mt-4 space-y-2">
                            <div class="h-2 overflow-hidden rounded-full bg-background" data-brand-wizard-progress-track>
                                <div
                                    class="h-full rounded-full bg-primary/80 transition-[width] duration-500 ease-out {{ (float) $run->progress > 0 ? '' : 'animate-pulse' }}"
                                    data-brand-wizard-progress-bar
                                    style="width: {{ (float) $run->progress > 0 ? max(6, (int) round($run->progress * 100)) : 35 }}%;"
                                ></div>
                            </div>
                            <div class="overflow-hidden rounded-md border border-border bg-background/80 p-4">
                                <div class="grid gap-3 md:grid-cols-2">
                                    <div class="space-y-2">
                                        <div class="h-3 w-28 rounded bg-primary/10 animate-pulse"></div>
                                        <div class="h-3 w-full rounded bg-primary/10 animate-pulse"></div>
                                        <div class="h-3 w-5/6 rounded bg-primary/10 animate-pulse"></div>
                                    </div>
                                    <div class="space-y-2">
                                        <div class="h-3 w-24 rounded bg-primary/10 animate-pulse"></div>
                                        <div class="h-3 w-full rounded bg-primary/10 animate-pulse"></div>
                                        <div class="h-3 w-2/3 rounded bg-primary/10 animate-pulse"></div>
                                    </div>
                                </div>
                            </div>
                            <p class="text-xs text-textSecondary">This page checks for updates automatically and keeps the latest stored progress.</p>
                        </div>
                    </div>
                </div>
            </div>
        @elseif ($isFailed || $isCompletedEmpty)
            <div class="rounded-lg border border-rose-500/30 bg-rose-500/10 p-5 text-sm text-rose-900">
                <div class="font-medium">{{ $isCompletedEmpty ? 'No brand sections were generated' : 'Generation failed' }}</div>
                <p class="mt-1">{{ $run->error_message ?: 'The AI run did not complete successfully.' }}</p>
                <div class="mt-4 flex flex-wrap items-center gap-3">
                    <form method="POST" action="{{ route('app.brand.wizard.retry', $run) }}">
                        @csrf
                        <button type="submit" class="inline-flex items-center rounded-md bg-primary px-4 py-2 text-sm font-medium text-textInverse hover:bg-primaryHover">
                            Retry generation
                        </button>
                    </form>
                    <a href="{{ route('app.brand.wizard') }}" class="inline-flex items-center rounded-md border border-rose-500/30 px-3 py-2 text-sm font-medium text-rose-900 hover:bg-rose-500/10">
                        Back to brand setup
                    </a>
                </div>

                @if ($canViewDiagnostics && ! empty($diagnostics))
                    <details class="mt-4 rounded-md border border-rose-500/20 bg-white/50 p-3">
                        <summary class="cursor-pointer font-medium text-rose-900">View technical details</summary>
                        <div class="mt-3 grid gap-2 text-xs text-rose-950">
                            @foreach ($diagnostics as $key => $value)
                                <div class="flex items-start justify-between gap-3">
                                    <span class="uppercase tracking-wide text-rose-700">{{ str_replace('_', ' ', (string) $key) }}</span>
                                    <span class="text-right">{{ is_scalar($value) ? (string) $value : json_encode($value) }}</span>
                                </div>
                            @endforeach
                        </div>
                    </details>
                @endif
            </div>
        @elseif ($canApply)
            <form method="POST" action="{{ route('app.brand.wizard.apply', $run) }}" class="space-y-6" data-brand-wizard-apply-form>
                @csrf

                <div class="rounded-lg border border-border bg-surface p-5">
                    <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                        <div>
                            <h2 class="text-lg font-semibold text-textPrimary">Generated context</h2>
                            <p class="mt-1 text-sm text-textSecondary">
                                Source: {{ ucfirst(str_replace('_', ' ', (string) $run->source_type)) }}
                                @if ($brandContextId)
                                    · Context {{ substr((string) $brandContextId, 0, 8) }}
                                @endif
                            </p>
                        </div>
                        <div class="text-sm text-textSecondary">
                            Mode: {{ ucfirst(str_replace('_', ' ', (string) ($run->generation_mode ?? 'full'))) }}
                        </div>
                    </div>
                </div>

                <div class="space-y-4">
                    @if (isset($generatedSections['company_profile']))
                        @php($company = (array) $generatedSections['company_profile'])
                        <label class="block rounded-lg border border-border bg-surface p-5">
                            <div class="flex items-start justify-between gap-4">
                                <div>
                                    <div class="text-lg font-semibold text-textPrimary">Company Profile</div>
                                    <p class="mt-1 text-sm text-textSecondary">Description, positioning, services, audience, mission and vision.</p>
                                </div>
                                <input type="checkbox" name="sections[]" value="company_profile" checked class="mt-1" data-brand-wizard-section-checkbox>
                            </div>
                            <div class="mt-4 grid gap-4 lg:grid-cols-2">
                                <div class="rounded-md border border-border bg-background p-4">
                                    <div class="text-xs uppercase tracking-wide text-textSecondary">Company</div>
                                    <div class="mt-2 text-sm text-textPrimary">{{ $company['company_name'] ?? $organization->name }}</div>
                                    <div class="mt-2 text-sm text-textSecondary">{{ $company['short_description'] ?? $company['target_audience'] ?? 'No summary generated.' }}</div>
                                </div>
                                <div class="rounded-md border border-border bg-background p-4">
                                    <div class="text-xs uppercase tracking-wide text-textSecondary">Core Focus</div>
                                    <div class="mt-2 text-sm text-textPrimary">{{ $company['value_proposition'] ?? 'No value proposition generated.' }}</div>
                                </div>
                            </div>
                        </label>
                    @endif

                    @if (isset($generatedSections['brand_voices']))
                        <label class="block rounded-lg border border-border bg-surface p-5">
                            <div class="flex items-start justify-between gap-4">
                                <div>
                                    <div class="text-lg font-semibold text-textPrimary">Brand Voices</div>
                                    <p class="mt-1 text-sm text-textSecondary">Distinct voice cards with do and don’t guidance.</p>
                                </div>
                                <input type="checkbox" name="sections[]" value="brand_voices" checked class="mt-1" data-brand-wizard-section-checkbox>
                            </div>
                            <div class="mt-4 grid gap-4 xl:grid-cols-2">
                                @foreach ((array) $generatedSections['brand_voices'] as $voice)
                                    <div class="rounded-md border border-border bg-background p-4">
                                        <div class="font-medium text-textPrimary">{{ $voice['name'] ?? 'Voice' }}</div>
                                        <p class="mt-2 text-sm text-textSecondary">{{ $voice['description'] ?? $voice['writing_style'] ?? '' }}</p>
                                        @if (!empty($voice['example_paragraph']))
                                            <p class="mt-3 text-sm text-textPrimary">{{ $voice['example_paragraph'] }}</p>
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                        </label>
                    @endif

                    @if (isset($generatedSections['buyer_personas']))
                        <label class="block rounded-lg border border-border bg-surface p-5">
                            <div class="flex items-start justify-between gap-4">
                                <div>
                                    <div class="text-lg font-semibold text-textPrimary">Buyer Personas</div>
                                    <p class="mt-1 text-sm text-textSecondary">Audience goals, objections, triggers and content preferences.</p>
                                </div>
                                <input type="checkbox" name="sections[]" value="buyer_personas" checked class="mt-1" data-brand-wizard-section-checkbox>
                            </div>
                            <div class="mt-4 space-y-3">
                                @foreach ((array) $generatedSections['buyer_personas'] as $persona)
                                    <details class="rounded-md border border-border bg-background p-4" @open($loop->first)>
                                        <summary class="cursor-pointer list-none">
                                            <div class="flex items-center justify-between gap-3">
                                                <div>
                                                    <div class="font-medium text-textPrimary">{{ $persona['name'] ?? 'Persona' }}</div>
                                                    <div class="text-xs uppercase tracking-wide text-textSecondary">{{ str_replace('_', ' ', (string) ($persona['type'] ?? 'buyer')) }}</div>
                                                </div>
                                                <span class="text-xs text-textSecondary">{{ $persona['role'] ?? '' }}</span>
                                            </div>
                                        </summary>
                                        <div class="mt-3 text-sm text-textSecondary">
                                            {{ $persona['summary'] ?? 'No summary generated.' }}
                                        </div>
                                    </details>
                                @endforeach
                            </div>
                        </label>
                    @endif

                    @if (isset($generatedSections['team_personas']))
                        <label class="block rounded-lg border border-border bg-surface p-5">
                            <div class="flex items-start justify-between gap-4">
                                <div>
                                    <div class="text-lg font-semibold text-textPrimary">Team Personas</div>
                                    <p class="mt-1 text-sm text-textSecondary">Suggested author roles, perspectives and expertise areas for content creation.</p>
                                </div>
                                <input type="checkbox" name="sections[]" value="team_personas" checked class="mt-1" data-brand-wizard-section-checkbox>
                            </div>
                            <div class="mt-4 grid gap-4 xl:grid-cols-2">
                                @foreach ((array) $generatedSections['team_personas'] as $member)
                                    <div class="rounded-md border border-border bg-background p-4">
                                        <div class="font-medium text-textPrimary">{{ $member['name'] ?? 'Team persona' }}</div>
                                        <div class="mt-1 text-sm text-textSecondary">{{ $member['title'] ?? $member['role'] ?? '' }}</div>
                                        <p class="mt-3 text-sm text-textPrimary">{{ $member['writing_perspective'] ?? 'No writing perspective generated.' }}</p>
                                    </div>
                                @endforeach
                            </div>
                        </label>
                    @endif
                </div>

                <div class="flex flex-wrap items-center justify-between gap-3">
                    <a href="{{ route('app.brand.wizard') }}" class="text-sm text-textSecondary hover:text-textPrimary">Back</a>
                    <button
                        type="submit"
                        class="inline-flex items-center rounded-md bg-primary px-5 py-2.5 text-sm font-medium text-textInverse hover:bg-primaryHover disabled:cursor-not-allowed disabled:bg-primary/40"
                        data-brand-wizard-apply-button
                    >
                        Apply selected sections
                    </button>
                </div>
            </form>
        @endif
    </div>

    @once
        <script>
            (() => {
                const root = document.querySelector('[data-brand-wizard-root]');
                if (!root) {
                    return;
                }

                const terminalStatuses = JSON.parse(root.dataset.terminalStatuses || '[]');
                const statusUrl = root.dataset.statusUrl;
                const reviewUrl = root.dataset.reviewUrl;
                const processingCard = root.querySelector('[data-brand-wizard-processing]');
                const progressBar = root.querySelector('[data-brand-wizard-progress-bar]');
                const message = root.querySelector('[data-brand-wizard-message]');
                let latestProgress = Number(root.dataset.progress || 0);
                let currentStatus = root.dataset.status || '';

                const applyForm = root.querySelector('[data-brand-wizard-apply-form]');
                const applyButton = root.querySelector('[data-brand-wizard-apply-button]');

                const syncApplyButton = () => {
                    if (!applyForm || !applyButton) {
                        return;
                    }

                    const checked = applyForm.querySelectorAll('[data-brand-wizard-section-checkbox]:checked').length;
                    applyButton.disabled = checked === 0;
                };

                syncApplyButton();
                applyForm?.addEventListener('change', syncApplyButton);

                if (!processingCard || terminalStatuses.includes(currentStatus)) {
                    return;
                }

                const updateProgressBar = (progress) => {
                    if (!progressBar) {
                        return;
                    }

                    if (progress > 0) {
                        progressBar.classList.remove('animate-pulse');
                        progressBar.style.width = `${Math.max(6, Math.round(progress * 100))}%`;
                    } else {
                        progressBar.classList.add('animate-pulse');
                        progressBar.style.width = '35%';
                    }
                };

                updateProgressBar(latestProgress);

                const poll = async () => {
                    try {
                        const response = await fetch(statusUrl, {
                            headers: {
                                'Accept': 'application/json',
                            },
                        });

                        if (!response.ok) {
                            window.setTimeout(poll, 2000);
                            return;
                        }

                        const payload = await response.json();
                        currentStatus = payload.status || currentStatus;
                        latestProgress = Math.max(latestProgress, Number(payload.progress || 0));
                        updateProgressBar(latestProgress);

                        if (message && typeof payload.message === 'string' && payload.message.trim() !== '') {
                            message.textContent = payload.message;
                        }

                        if (terminalStatuses.includes(currentStatus)) {
                            window.location.assign(reviewUrl);
                            return;
                        }
                    } catch (error) {
                        // Keep polling; failures here are transient.
                    }

                    window.setTimeout(poll, 2000);
                };

                window.setTimeout(poll, 1200);
            })();
        </script>
    @endonce
@endsection
