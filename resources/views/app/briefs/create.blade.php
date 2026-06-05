@extends('layouts.app', ['title' => 'Create content', 'pageWidth' => 'wide'])

@section('content')
    @php
        $singleSiteId = ($sites ?? collect())->count() === 1 ? (string) ($sites ?? collect())->first()->id : null;
        $selectedCreateSiteId = old('site_id', $singleSiteId);
    @endphp

    <div class="mb-6 flex items-start justify-between gap-3">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight text-textPrimary">Create content</h1>
            <p class="mt-1 text-textSecondary">Define brief settings to start the content lifecycle. You can generate drafts and comparisons in the content workspace.</p>
        </div>
        <a href="{{ route('app.content.index') }}" class="rounded border border-border px-3 py-2 text-sm">Back to content</a>
    </div>

    @if (!empty($briefIntelligenceEnabled))
        <div class="mb-4 rounded-lg border border-border bg-surface p-4">
            <div class="mb-3 flex items-center justify-between gap-2">
                <div>
                    <h2 class="text-sm font-semibold text-textPrimary">Create from research</h2>
                    <p class="mt-1 text-xs text-textSecondary">Bootstrap a new brief from research summary and selected findings.</p>
                </div>
                <a href="{{ route('app.research.index') }}" class="rounded border border-border px-2 py-1 text-xs">Open research</a>
            </div>

            <form method="POST" action="{{ route('app.content.create.from-research') }}" class="space-y-3">
                @csrf
                <div class="grid gap-3 md:grid-cols-2">
                    <div>
                        <label class="mb-1 block text-xs text-textSecondary">Research project</label>
                        <select name="research_project_id" class="pl-select bg-background" required>
                            <option value="">Select research project</option>
                            @foreach (($researchProjects ?? collect()) as $project)
                                <option value="{{ $project->id }}" @selected(old('research_project_id') === (string) $project->id)>
                                    {{ $project->name }} · {{ strtoupper((string) ($project->status?->value ?? $project->status)) }}
                                </option>
                            @endforeach
                        </select>
                        @error('research_project_id')<p class="mt-1 text-xs text-rose-700">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label class="mb-1 block text-xs text-textSecondary">Site (optional override)</label>
                        <select name="site_id" class="pl-select bg-background">
                            <option value="">Use project site when available</option>
                            @foreach ($sites as $site)
                                <option value="{{ $site->id }}" @selected(old('site_id') === (string) $site->id)>{{ $site->name }}</option>
                            @endforeach
                        </select>
                        @error('site_id')<p class="mt-1 text-xs text-rose-700">{{ $message }}</p>@enderror
                    </div>
                </div>
                <div class="grid gap-3 md:grid-cols-3">
                    <div>
                        <label class="mb-1 block text-xs text-textSecondary">Title override (optional)</label>
                        <input type="text" name="title" value="{{ old('title') }}" class="w-full rounded border border-border bg-background px-3 py-2 text-sm" maxlength="255">
                    </div>
                    <div>
                        <label class="mb-1 block text-xs text-textSecondary">Content type</label>
                        <select name="content_type" class="pl-select bg-background">
                            @foreach ($contentTypeOptions as $value => $label)
                                <option value="{{ $value }}" @selected(old('content_type', 'blog') === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs text-textSecondary">Language</label>
                        <select name="language" class="pl-select bg-background">
                            <option value="nl" @selected(old('language') === 'nl')>Dutch (NL)</option>
                            <option value="en" @selected(old('language', 'en') === 'en')>English (EN)</option>
                        </select>
                    </div>
                </div>
                <div class="flex justify-end">
                    <button class="rounded border border-border bg-background px-3 py-2 text-sm">Create from research</button>
                </div>
            </form>
        </div>
    @endif

    <div id="source-briefing" class="mb-4 rounded-lg border border-border bg-surface p-4">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h2 class="text-sm font-semibold text-textPrimary">Generate brief from URL</h2>
                <p class="mt-1 text-xs text-textSecondary">Analyze a public article to create an original, brand-aligned brief. This is source-based briefing, not rewriting.</p>
            </div>
            <span class="rounded-full border border-amber-200 bg-amber-50 px-2 py-1 text-[11px] font-medium text-amber-800">
                Not for copying source content
            </span>
        </div>

        <div class="mt-3 rounded-md border border-border bg-background px-3 py-2 text-xs leading-5 text-textSecondary">
            This feature analyzes external content to create original, brand aligned briefs and content opportunities. It is not intended to reproduce source content.
        </div>

        <form method="POST" action="{{ route('app.content.create.from-url.generate') }}" class="mt-4 space-y-3" id="source-generate-start-form">
            @csrf
            <div>
                <label class="mb-1 block text-xs text-textSecondary">Public article URL</label>
                <input
                    type="text"
                    name="source_url"
                    value="{{ old('source_url', $sourcePreview?->source_url) }}"
                    placeholder="https://example.com/article"
                    class="w-full rounded border border-border bg-background px-3 py-2 text-sm"
                    required
                >
                @error('source_url')<p class="mt-1 text-xs text-rose-700">{{ $message }}</p>@enderror
            </div>
            <div>
                <label class="mb-1 block text-xs text-textSecondary">Paste source notes manually</label>
                <textarea
                    name="manual_source_notes"
                    rows="4"
                    class="w-full rounded border border-border bg-background px-3 py-2 text-sm"
                    placeholder="Optional: paste notes, headings, or key observations if the source blocks automated extraction."
                >{{ old('manual_source_notes') }}</textarea>
            </div>
            <div>
                <label class="mb-2 block text-xs text-textSecondary">Output type</label>
                <div class="grid gap-2 lg:grid-cols-3">
                    @foreach ([
                        'brief_only' => ['label' => 'Brief only', 'copy' => 'Generate a structured brief only.'],
                        'brief_keywords' => ['label' => 'Brief + keywords', 'copy' => 'Add keyword and entity opportunities.'],
                        'brief_chain' => ['label' => 'Brief + chain proposal', 'copy' => 'Add chained content recommendations.'],
                    ] as $value => $option)
                        <label class="rounded-md border border-border bg-background px-3 py-3 text-sm">
                            <input type="radio" name="output_mode" value="{{ $value }}" class="mr-2" @checked(old('output_mode', 'brief_only') === $value)>
                            <span class="font-medium text-textPrimary">{{ $option['label'] }}</span>
                            <span class="mt-1 block text-xs text-textSecondary">{{ $option['copy'] }}</span>
                        </label>
                    @endforeach
                </div>
                @error('output_mode')<p class="mt-1 text-xs text-rose-700">{{ $message }}</p>@enderror
            </div>
            <div id="source-generate-start-feedback" class="hidden rounded-md border px-3 py-2 text-sm"></div>
            <div class="flex justify-end">
                <button id="source-generate-start-btn" class="rounded border border-border bg-background px-3 py-2 text-sm">Generate from URL</button>
            </div>
        </form>
        <script>
            (function() {
                const form = document.getElementById('source-generate-start-form');
                const button = document.getElementById('source-generate-start-btn');
                const feedback = document.getElementById('source-generate-start-feedback');
                if (!form || !button || !feedback) return;

                form.addEventListener('submit', async function(event) {
                    event.preventDefault();
                    button.disabled = true;
                    button.textContent = 'Starting generation...';
                    feedback.className = 'rounded-md border border-sky-200 bg-sky-50 px-3 py-2 text-sm text-sky-700';
                    feedback.textContent = 'Starting URL generation. You will be redirected to a live progress view.';
                    feedback.classList.remove('hidden');

                    try {
                        const response = await fetch(form.action, {
                            method: 'POST',
                            body: new FormData(form),
                            headers: {
                                'Accept': 'application/json',
                                'X-Requested-With': 'XMLHttpRequest',
                            },
                        });

                        const contentType = response.headers.get('content-type') || '';
                        const data = contentType.includes('application/json')
                            ? await response.json()
                            : { message: await response.text() };

                        if (!response.ok) {
                            throw new Error(data.message || data.error || 'We could not start URL generation. Try another public article URL.');
                        }

                        if (data.status === 'failed') {
                            throw new Error((data.failure_message || 'Brief generation failed.') + (data.error_code ? ' (' + data.error_code + ')' : ''));
                        }

                        if (data.redirect_url) {
                            window.location.href = data.redirect_url;
                            return;
                        }

                        feedback.className = 'rounded-md border border-sky-200 bg-sky-50 px-3 py-2 text-sm text-sky-700';
                        feedback.textContent = 'Generation started. Refresh this page if you are not redirected.';
                    } catch (error) {
                        button.disabled = false;
                        button.textContent = 'Generate from URL';
                        feedback.className = 'rounded-md border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700';
                        feedback.textContent = error?.message || error?.error || 'We could not start URL generation. Try another public article URL.';
                    }
                });
            })();
        </script>

        @if ($sourcePreview)
            <div class="mt-4 rounded-lg border border-border bg-background p-4">
                <div class="grid gap-4 xl:grid-cols-3 xl:gap-8">
                    <div class="space-y-4 xl:col-span-2">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <p class="text-xs uppercase tracking-wide text-textSecondary">Source preview</p>
                                <h3 class="mt-1 text-base font-semibold text-textPrimary">{{ $sourcePreview->source_title ?: 'Untitled source' }}</h3>
                                <p class="mt-1 text-sm text-textSecondary">
                                    {{ $sourcePreview->source_domain ?: '-' }}
                                    · {{ strtoupper((string) ($sourcePreview->source_language ?: 'en')) }}
                                    · {{ ucfirst((string) $sourcePreview->extraction_status) }}
                                </p>
                            </div>
                            <div class="flex flex-wrap gap-2">
                                <a href="{{ $sourcePreview->final_url ?: $sourcePreview->source_url }}" target="_blank" rel="noreferrer" class="rounded border border-border px-3 py-2 text-xs text-textPrimary hover:bg-surfaceSubtle">Open source</a>
                                @if ((string) $sourcePreview->extraction_status === 'failed')
                                    <form method="POST" action="{{ route('app.content.create.from-url.preview') }}">
                                        @csrf
                                        <input type="hidden" name="source_url" value="{{ $sourcePreview->source_url }}">
                                        <input type="hidden" name="extraction_mode" value="alternative">
                                        <button class="rounded border border-border px-3 py-2 text-xs text-textPrimary hover:bg-surfaceSubtle">Retry with alternative extraction</button>
                                    </form>
                                @endif
                            </div>
                        </div>

                        <div class="rounded-md border border-border bg-surface px-3 py-3">
                            <p class="text-xs uppercase tracking-wide text-textSecondary">Summary</p>
                            <p class="mt-2 text-sm text-textPrimary">{{ data_get($sourcePreview->metadata_json, 'extraction.summary', data_get($sourcePreview->metadata_json, 'error', 'No summary available.')) }}</p>
                        </div>

                        @if ($sourceExtractionPending ?? false)
                            <div class="space-y-3 rounded-md border border-sky-200 bg-sky-50 px-4 py-4">
                                <div class="flex items-start gap-3">
                                    <i data-lucide="loader" class="mt-0.5 h-5 w-5 text-sky-600"></i>
                                    <div>
                                        <p class="text-sm font-medium text-sky-800">Source extraction is still running</p>
                                        <p class="mt-1 text-xs text-sky-700">We are trying fallback methods. You can leave this page and come back.</p>
                                    </div>
                                </div>
                            </div>
                        @elseif ($sourceGenerationPending ?? false)
                            <div
                                id="source-generation-pending"
                                class="space-y-3 rounded-md border border-sky-200 bg-sky-50 px-4 py-4"
                                data-status-url="{{ route('app.content.create.from-url.jobs.status', $sourcePreview->id) }}"
                                data-current-status="{{ $sourcePreview->generation_status }}"
                            >
                                <div class="flex items-center gap-3">
                                    <svg class="h-5 w-5 animate-spin text-sky-600" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                    </svg>
                                    <div>
                                        <p class="text-sm font-medium text-sky-800">Generating brief from source</p>
                                        <p class="text-xs text-sky-700">{{ $sourcePreview->getGenerationProgressLabel() }}</p>
                                    </div>
                                </div>
                                <p class="text-xs text-sky-600">This page will automatically update when the generation is complete.</p>
                            </div>
                            <script>
                                (function() {
                                    const container = document.getElementById('source-generation-pending');
                                    if (!container) return;

                                    const statusUrl = container.dataset.statusUrl;
                                    let pollInterval = null;

                                    function escapeHtml(value) {
                                        return String(value || '')
                                            .replace(/&/g, '&amp;')
                                            .replace(/</g, '&lt;')
                                            .replace(/>/g, '&gt;')
                                            .replace(/"/g, '&quot;')
                                            .replace(/'/g, '&#039;');
                                    }

                                    function pollStatus() {
                                        fetch(statusUrl, {
                                            headers: { 'Accept': 'application/json' }
                                        })
                                        .then(async response => {
                                            const contentType = response.headers.get('content-type') || '';
                                            return contentType.includes('application/json')
                                                ? response.json()
                                                : { is_failed: true, failure_message: 'We could not read the generation status. Refresh the page to try again.', error_code: 'PL-URL-STATUS' };
                                        })
                                        .then(data => {
                                            if (data.is_completed && data.redirect_url) {
                                                clearInterval(pollInterval);
                                                window.location.href = data.redirect_url;
                                            } else if (data.is_failed) {
                                                clearInterval(pollInterval);
                                                container.className = 'space-y-3 rounded-md border border-rose-200 bg-rose-50 px-4 py-4';
                                                container.innerHTML = '<div class="flex items-start gap-3"><i data-lucide="alert-circle" class="mt-0.5 h-5 w-5 text-rose-600"></i><div><p class="text-sm font-medium text-rose-800">Brief generation failed</p><p class="mt-1 text-xs text-rose-700">' + escapeHtml(data.failure_message || 'An error occurred during generation.') + (data.error_code ? ' (' + escapeHtml(data.error_code) + ')' : '') + '</p></div></div>';
                                                if (window.lucide) {
                                                    window.lucide.createIcons();
                                                }
                                            }
                                        })
                                        .catch(() => {
                                            // Keep polling on transient errors
                                        });
                                    }

                                    pollInterval = setInterval(pollStatus, 3000);
                                    // Initial poll after 2 seconds
                                    setTimeout(pollStatus, 2000);
                                })();
                            </script>
                        @elseif ($sourceGenerationFailed ?? false)
                            <div class="space-y-3 rounded-md border border-rose-200 bg-rose-50 px-4 py-4">
                                <div class="flex items-start gap-3">
                                    <i data-lucide="alert-circle" class="mt-0.5 h-5 w-5 text-rose-600"></i>
                                    <div>
                                        <p class="text-sm font-medium text-rose-800">Brief generation failed</p>
                                        <p class="mt-1 text-xs text-rose-700">{{ $sourcePreview->generation_failure_message ?? 'We could not extract this source automatically. You can paste source notes manually or try another URL.' }}</p>
                                    </div>
                                </div>
                                <form
                                    method="POST"
                                    action="{{ route('app.content.create.from-url.generate') }}"
                                    class="space-y-2"
                                >
                                    @csrf
                                    <input type="hidden" name="content_source_id" value="{{ $sourcePreview->id }}">
                                    <input type="hidden" name="output_mode" value="{{ $sourcePreview->generation_output_mode ?: 'brief_only' }}">
                                    <textarea name="manual_source_notes" rows="4" class="w-full rounded border border-rose-200 bg-white px-3 py-2 text-sm" placeholder="Paste source notes manually"></textarea>
                                    <button class="rounded border border-rose-300 bg-white px-3 py-2 text-xs font-medium text-rose-700 hover:bg-rose-50">
                                        Generate from manual notes
                                    </button>
                                </form>
                                <div class="flex gap-2">
                                    <form method="POST" action="{{ route('app.content.create.from-url.retry', $sourcePreview->id) }}">
                                        @csrf
                                        <button class="rounded border border-rose-300 bg-white px-3 py-2 text-xs font-medium text-rose-700 hover:bg-rose-50">
                                            Retry generation
                                        </button>
                                    </form>
                                    <form method="POST" action="{{ route('app.content.create.from-url.discard', $sourcePreview->id) }}">
                                        @csrf
                                        <button class="rounded border border-border bg-background px-3 py-2 text-xs text-textSecondary hover:bg-surfaceSubtle">
                                            Discard and start over
                                        </button>
                                    </form>
                                </div>
                                @if (($canViewSourceDiagnostics ?? false) && $sourcePreview->generation_diagnostics_json)
                                    <details class="mt-2">
                                        <summary class="cursor-pointer text-xs text-rose-600">View diagnostics (admin)</summary>
                                        <pre class="mt-2 max-h-48 overflow-auto rounded bg-rose-100 p-2 text-[10px] text-rose-800">{{ json_encode($sourcePreview->generation_diagnostics_json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                                    </details>
                                @endif
                            </div>
                        @elseif ((string) $sourcePreview->extraction_status !== 'failed' && ! $sourceGenerated)
                            @if (data_get($sourcePreview->metadata_json, 'extraction.method') && data_get($sourcePreview->metadata_json, 'extraction.method') !== 'direct')
                                <div class="rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2 text-xs text-emerald-800">
                                    Source extracted using fallback method: {{ data_get($sourcePreview->metadata_json, 'extraction.method') }}.
                                </div>
                            @endif
                            <form
                                method="POST"
                                action="{{ route('app.content.create.from-url.generate') }}"
                                class="space-y-3 rounded-md border border-border bg-surface px-3 py-3"
                                id="source-generate-form"
                            >
                                @csrf
                                <input type="hidden" name="content_source_id" value="{{ $sourcePreview->id }}">
                                <div>
                                    <label class="mb-1 block text-xs text-textSecondary">Paste source notes manually</label>
                                    <textarea name="manual_source_notes" rows="4" class="w-full rounded border border-border bg-background px-3 py-2 text-sm" placeholder="Optional: add source notes or corrections for the brief generator.">{{ old('manual_source_notes') }}</textarea>
                                </div>
                                <div>
                                    <label class="mb-2 block text-xs text-textSecondary">Output type</label>
                                    <div class="grid gap-2 lg:grid-cols-3">
                                        @foreach ([
                                            'brief_only' => ['label' => 'Brief only', 'copy' => 'Generate a structured brief only.'],
                                            'brief_keywords' => ['label' => 'Brief + keywords', 'copy' => 'Add keyword and entity opportunities.'],
                                            'brief_chain' => ['label' => 'Brief + chain proposal', 'copy' => 'Add chained content recommendations.'],
                                        ] as $value => $option)
                                            <label class="rounded-md border border-border bg-background px-3 py-3 text-sm">
                                                <input type="radio" name="output_mode" value="{{ $value }}" class="mr-2" @checked(old('output_mode', 'brief_only') === $value)>
                                                <span class="font-medium text-textPrimary">{{ $option['label'] }}</span>
                                                <span class="mt-1 block text-xs text-textSecondary">{{ $option['copy'] }}</span>
                                            </label>
                                        @endforeach
                                    </div>
                                    @error('output_mode')<p class="mt-1 text-xs text-rose-700">{{ $message }}</p>@enderror
                                </div>
                                <div class="flex justify-end">
                                    <button id="source-generate-btn" class="rounded border border-border bg-background px-3 py-2 text-sm">Generate brief from source</button>
                                </div>
                            </form>
                            <script>
                                (function() {
                                    const form = document.getElementById('source-generate-form');
                                    const btn = document.getElementById('source-generate-btn');
                                    if (!form || !btn) return;

                                    form.addEventListener('submit', function() {
                                        btn.disabled = true;
                                        btn.textContent = 'Starting generation...';
                                    });
                                })();
                            </script>
                        @endif

                        @if ($sourceGenerated)
                            @php
                                $generatedBrief = (array) ($sourceGenerated['brief'] ?? []);
                                $generatedKeywords = (array) ($sourceGenerated['keywords'] ?? []);
                                $generatedChain = (array) ($sourceGenerated['chain_proposal'] ?? []);
                            @endphp
                            <div class="border-t border-border pt-4">
                                <div class="flex flex-wrap items-start justify-between gap-3">
                                    <div>
                                        <p class="text-xs uppercase tracking-wide text-textSecondary">Generated result</p>
                                        <h3 class="mt-1 text-base font-semibold text-textPrimary">{{ $generatedBrief['working_title'] ?? 'Generated brief' }}</h3>
                                        <p class="mt-1 text-sm text-textSecondary">{{ $generatedBrief['summary'] ?? '' }}</p>
                                    </div>
                                    <form method="POST" action="{{ route('app.content.create.from-url.discard', $sourcePreview->id) }}">
                                        @csrf
                                        <button class="rounded border border-border px-3 py-2 text-xs text-textPrimary hover:bg-surfaceSubtle">Discard</button>
                                    </form>
                                </div>

                                <div class="mt-4 grid gap-3 lg:grid-cols-2">
                                    <div class="rounded-md border border-border bg-surface px-3 py-3">
                                        <p class="text-xs uppercase tracking-wide text-textSecondary">Brief</p>
                                        <dl class="mt-2 space-y-2 text-sm text-textPrimary">
                                            <div>
                                                <dt class="text-textSecondary">Primary keyword</dt>
                                                <dd>{{ $generatedBrief['primary_keyword'] ?? '-' }}</dd>
                                            </div>
                                            <div>
                                                <dt class="text-textSecondary">Audience</dt>
                                                <dd>{{ $generatedBrief['target_audience'] ?? '-' }}</dd>
                                            </div>
                                            <div>
                                                <dt class="text-textSecondary">Search intent</dt>
                                                <dd>{{ $generatedBrief['search_intent'] ?? '-' }}</dd>
                                            </div>
                                            <div>
                                                <dt class="text-textSecondary">Recommended differentiators</dt>
                                                <dd>{{ implode(', ', (array) ($generatedBrief['recommended_differentiators'] ?? [])) ?: '-' }}</dd>
                                            </div>
                                        </dl>
                                    </div>
                                    <div class="rounded-md border border-border bg-surface px-3 py-3">
                                        <p class="text-xs uppercase tracking-wide text-textSecondary">Recommended structure</p>
                                        <ul class="mt-2 space-y-1 text-sm text-textPrimary">
                                            @foreach ((array) ($generatedBrief['recommended_structure'] ?? []) as $item)
                                                <li>• {{ $item }}</li>
                                            @endforeach
                                        </ul>
                                    </div>
                                </div>

                                @if ($generatedKeywords !== [])
                                    <div class="mt-3 rounded-md border border-border bg-surface px-3 py-3">
                                        <p class="text-xs uppercase tracking-wide text-textSecondary">Keyword and entity opportunities</p>
                                        <p class="mt-2 text-sm text-textPrimary">
                                            {{ implode(', ', (array) ($generatedKeywords['secondary_keywords'] ?? [])) ?: 'No secondary keywords generated.' }}
                                        </p>
                                        <p class="mt-2 text-xs text-textSecondary">
                                            Entities: {{ implode(', ', (array) ($generatedKeywords['entities'] ?? [])) ?: '-' }}
                                        </p>
                                    </div>
                                @endif

                                @if ($generatedChain !== [])
                                    <div class="mt-3 rounded-md border border-border bg-surface px-3 py-3">
                                        <p class="text-xs uppercase tracking-wide text-textSecondary">Chain proposal</p>
                                        <p class="mt-2 text-sm font-medium text-textPrimary">{{ $generatedChain['pillar_topic'] ?? '-' }}</p>
                                        <ul class="mt-2 space-y-1 text-sm text-textPrimary">
                                            @foreach ((array) ($generatedChain['supporting_subtopics'] ?? []) as $row)
                                                <li>• {{ data_get($row, 'title', '') }}</li>
                                            @endforeach
                                        </ul>
                                        <p class="mt-2 text-xs text-textSecondary">{{ $generatedChain['source_fit'] ?? '' }}</p>
                                    </div>
                                @endif
                            </div>
                        @endif
                    </div>

                    <div class="space-y-4 xl:col-span-1">
                        <div class="rounded-md border border-border bg-surface px-3 py-3">
                            <p class="text-xs uppercase tracking-wide text-textSecondary">Extraction data</p>
                            <dl class="mt-2 space-y-1 text-sm text-textPrimary">
                                <div class="flex justify-between gap-3">
                                    <dt class="text-textSecondary">Word count</dt>
                                    <dd>{{ (int) data_get($sourcePreview->metadata_json, 'extraction.word_count', 0) }}</dd>
                                </div>
                                <div class="flex justify-between gap-3">
                                    <dt class="text-textSecondary">Author</dt>
                                    <dd>{{ data_get($sourcePreview->metadata_json, 'extraction.author', '-') ?: '-' }}</dd>
                                </div>
                                <div class="flex justify-between gap-3">
                                    <dt class="text-textSecondary">Publish date</dt>
                                    <dd>{{ data_get($sourcePreview->metadata_json, 'extraction.publish_date', '-') ?: '-' }}</dd>
                                </div>
                                <div class="flex justify-between gap-3">
                                    <dt class="text-textSecondary">Method</dt>
                                    <dd>{{ data_get($sourcePreview->metadata_json, 'extraction.method', '-') ?: '-' }}</dd>
                                </div>
                                @if (app()->isLocal() || config('app.debug'))
                                    <div class="flex justify-between gap-3">
                                        <dt class="text-textSecondary">Chars</dt>
                                        <dd>{{ (int) data_get($sourcePreview->metadata_json, 'extraction.extracted_characters', mb_strlen((string) $sourcePreview->extracted_text)) }}</dd>
                                    </div>
                                    <div class="flex justify-between gap-3">
                                        <dt class="text-textSecondary">Est. tokens</dt>
                                        <dd>{{ (int) data_get($sourcePreview->metadata_json, 'extraction.estimated_tokens', 0) }}</dd>
                                    </div>
                                    <div class="flex justify-between gap-3">
                                        <dt class="text-textSecondary">AI</dt>
                                        <dd>{{ data_get($sourcePreview->analysis_json, '_debug.ai_provider', '-') ?: '-' }} {{ data_get($sourcePreview->analysis_json, '_debug.ai_model') ? '/ '.data_get($sourcePreview->analysis_json, '_debug.ai_model') : '' }}</dd>
                                    </div>
                                    <div class="flex justify-between gap-3">
                                        <dt class="text-textSecondary">AI ms</dt>
                                        <dd>{{ data_get($sourcePreview->analysis_json, '_debug.generation_duration_ms', '-') ?: '-' }}</dd>
                                    </div>
                                @endif
                            </dl>
                        </div>

                        @if ($sourceGenerated)
                            <form method="POST" action="{{ route('app.content.create.from-url.save') }}" class="space-y-4 rounded-md border border-border bg-surface p-4">
                                @csrf
                                <input type="hidden" name="content_source_id" value="{{ $sourcePreview->id }}">

                                <div class="grid gap-3">
                                    <div>
                                        <label class="mb-1 block text-xs text-textSecondary">Destination mode</label>
                                        <select name="destination_mode" class="pl-select bg-background">
                                            <option value="connected" @selected(old('destination_mode', 'connected') === 'connected')>Connected CMS</option>
                                            <option value="api_only" @selected(old('destination_mode') === 'api_only')>API only</option>
                                            <option value="hybrid" @selected(old('destination_mode') === 'hybrid')>Hybrid</option>
                                        </select>
                                    </div>
                                    <div>
                                        <label class="mb-1 block text-xs text-textSecondary">Site</label>
                                        <select name="site_id" class="pl-select bg-background">
                                            <option value="">Select publishing site</option>
                                            @foreach ($sites as $site)
                                                <option value="{{ $site->id }}" @selected($selectedCreateSiteId === (string) $site->id)>{{ $site->name }}</option>
                                            @endforeach
                                        </select>
                                        <p class="mt-1 text-xs text-textSecondary">Required for connected CMS publishing.</p>
                                        @error('site_id')<p class="mt-1 text-xs text-rose-700">{{ $message }}</p>@enderror
                                    </div>
                                    <div>
                                        <label class="mb-1 block text-xs text-textSecondary">Destination (API/hybrid)</label>
                                        <select name="content_destination_id" class="pl-select bg-background">
                                            <option value="">Select destination</option>
                                            @foreach ($destinations as $destination)
                                                <option value="{{ $destination->id }}" @selected(old('content_destination_id') === (string) $destination->id)>
                                                    {{ $destination->name }} ({{ $destination->type?->value ?? $destination->type }})
                                                </option>
                                            @endforeach
                                        </select>
                                        @error('content_destination_id')<p class="mt-1 text-xs text-rose-700">{{ $message }}</p>@enderror
                                    </div>
                                </div>

                                <div class="grid gap-2">
                                    <button name="next_action" value="save" class="rounded border border-border bg-background px-3 py-2 text-sm">Save as brief</button>
                                    <button name="next_action" value="create_chain" class="rounded border border-border bg-background px-3 py-2 text-sm">Create chain</button>
                                    <button name="next_action" value="generate_draft" class="rounded border border-border bg-background px-3 py-2 text-sm">Generate first draft</button>
                                </div>
                            </form>
                        @endif

                        @if ($canViewSourceDiagnostics)
                            <details class="rounded-md border border-border bg-surface px-3 py-3">
                                <summary class="cursor-pointer text-sm font-medium text-textPrimary">Admin diagnostics</summary>
                                <div class="mt-3 grid gap-3">
                                    <pre class="overflow-auto rounded border border-border bg-background p-3 text-xs text-textPrimary">{{ json_encode($sourcePreview->metadata_json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                                    <pre class="overflow-auto rounded border border-border bg-background p-3 text-xs text-textPrimary">{{ json_encode($sourcePreview->analysis_json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                                </div>
                            </details>
                        @endif
                    </div>
                </div>
            </div>
        @endif
    </div>

    <form method="POST" action="{{ route('app.content.create.store') }}" class="space-y-4 rounded-lg border border-border bg-surface p-4">
        @csrf

        <div class="rounded border border-border bg-background px-3 py-2">
            <h2 class="text-sm font-semibold text-textPrimary">Brief settings</h2>
            <p class="mt-1 text-xs text-textSecondary">Phase 1 of the workflow: define what this content needs to achieve.</p>
        </div>

        <div class="grid gap-3 md:grid-cols-4">
            <div>
                <label class="mb-1 block text-xs text-textSecondary">Destination mode</label>
                <select name="destination_mode" class="pl-select bg-background">
                    <option value="connected" @selected(old('destination_mode', 'connected') === 'connected')>Connected CMS</option>
                    <option value="api_only" @selected(old('destination_mode') === 'api_only')>API only</option>
                    <option value="hybrid" @selected(old('destination_mode') === 'hybrid')>Hybrid</option>
                </select>
                @error('destination_mode')<p class="mt-1 text-xs text-rose-700">{{ $message }}</p>@enderror
            </div>
            <div>
                <label class="mb-1 block text-xs text-textSecondary">Site</label>
                <select name="site_id" class="pl-select bg-background">
                    <option value="">Select publishing site</option>
                    @foreach ($sites as $site)
                        <option value="{{ $site->id }}" @selected($selectedCreateSiteId === (string) $site->id)>{{ $site->name }}</option>
                    @endforeach
                </select>
                <p class="mt-1 text-xs text-textSecondary">Required for connected CMS publishing.</p>
                @error('site_id')<p class="mt-1 text-xs text-rose-700">{{ $message }}</p>@enderror
            </div>
            <div>
                <label class="mb-1 block text-xs text-textSecondary">Destination (API/hybrid)</label>
                <select name="content_destination_id" class="pl-select bg-background">
                    <option value="">Select destination</option>
                    @foreach ($destinations as $destination)
                        <option value="{{ $destination->id }}" @selected(old('content_destination_id') === (string) $destination->id)>
                            {{ $destination->name }} ({{ $destination->type?->value ?? $destination->type }})
                        </option>
                    @endforeach
                </select>
                @error('content_destination_id')<p class="mt-1 text-xs text-rose-700">{{ $message }}</p>@enderror
            </div>
            <div>
                <label class="mb-1 block text-xs text-textSecondary">Language</label>
                <select name="language" class="pl-select bg-background" required>
                    <option value="nl" @selected(old('language', 'nl') === 'nl')>Dutch (NL)</option>
                    <option value="en" @selected(old('language') === 'en')>English (EN)</option>
                </select>
                @error('language')<p class="mt-1 text-xs text-rose-700">{{ $message }}</p>@enderror
            </div>
            <div>
                <label class="mb-1 block text-xs text-textSecondary">Content type</label>
                <select name="content_type" class="pl-select bg-background" required>
                    @foreach ($contentTypeOptions as $value => $label)
                        <option value="{{ $value }}" @selected(old('content_type', 'blog') === $value)>{{ $label }}</option>
                    @endforeach
                </select>
                @error('content_type')<p class="mt-1 text-xs text-rose-700">{{ $message }}</p>@enderror
            </div>
        </div>

        <div>
            <label class="mb-1 block text-xs text-textSecondary">Title</label>
            <input type="text" name="title" value="{{ old('title') }}" class="w-full rounded border border-border bg-background px-3 py-2 text-sm" maxlength="255" required>
            @error('title')<p class="mt-1 text-xs text-rose-700">{{ $message }}</p>@enderror
        </div>

        <div class="grid gap-3 md:grid-cols-2">
            <div>
                <label class="mb-1 block text-xs text-textSecondary">Primary keyword</label>
                <input type="text" name="primary_keyword" value="{{ old('primary_keyword') }}" class="w-full rounded border border-border bg-background px-3 py-2 text-sm" maxlength="255">
                @error('primary_keyword')<p class="mt-1 text-xs text-rose-700">{{ $message }}</p>@enderror
            </div>
            <div>
                <label class="mb-1 block text-xs text-textSecondary">Secondary keywords</label>
                <textarea name="secondary_keywords" rows="2" class="w-full rounded border border-border bg-background px-3 py-2 text-sm" placeholder="comma or new line separated">{{ old('secondary_keywords') }}</textarea>
                @error('secondary_keywords')<p class="mt-1 text-xs text-rose-700">{{ $message }}</p>@enderror
            </div>
        </div>

        <div class="grid gap-3 md:grid-cols-2">
            <div>
                <label class="mb-1 block text-xs text-textSecondary">Target audience</label>
                <select name="audience_keys[]" class="pl-select bg-background" multiple size="5">
                    @foreach ($audienceOptions as $value => $label)
                        <option value="{{ $value }}" @selected(in_array($value, (array) old('audience_keys', []), true))>{{ $label }}</option>
                    @endforeach
                </select>
                <p class="mt-1 text-xs text-textSecondary">Select one or more audience tags.</p>
                @error('audience_keys')<p class="mt-1 text-xs text-rose-700">{{ $message }}</p>@enderror
                @error('audience_keys.*')<p class="mt-1 text-xs text-rose-700">{{ $message }}</p>@enderror
            </div>
            <div>
                <label class="mb-1 block text-xs text-textSecondary">Tone of voice</label>
                <textarea name="tone_of_voice" rows="2" class="w-full rounded border border-border bg-background px-3 py-2 text-sm">{{ old('tone_of_voice') }}</textarea>
                @error('tone_of_voice')<p class="mt-1 text-xs text-rose-700">{{ $message }}</p>@enderror
            </div>
        </div>

        <div class="grid gap-3 md:grid-cols-3">
            <div>
                <label class="mb-1 block text-xs text-textSecondary">Funnel stage</label>
                <select name="funnel_stage" class="pl-select bg-background">
                    <option value="">-</option>
                    @foreach ($funnelStageOptions as $value => $label)
                        <option value="{{ $value }}" @selected(old('funnel_stage') === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="mb-1 block text-xs text-textSecondary">Search intent</label>
                <select name="search_intent" class="pl-select bg-background">
                    <option value="">-</option>
                    @foreach ($searchIntentOptions as $value => $label)
                        <option value="{{ $value }}" @selected(old('search_intent') === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="grid grid-cols-2 gap-2">
                <div>
                    <label class="mb-1 block text-xs text-textSecondary">Min words</label>
                    <input type="number" name="desired_length_min" value="{{ old('desired_length_min', 900) }}" class="w-full rounded border border-border bg-background px-3 py-2 text-sm" min="300" max="10000">
                </div>
                <div>
                    <label class="mb-1 block text-xs text-textSecondary">Max words</label>
                    <input type="number" name="desired_length_max" value="{{ old('desired_length_max', 1200) }}" class="w-full rounded border border-border bg-background px-3 py-2 text-sm" min="300" max="10000">
                </div>
            </div>
        </div>
        @error('desired_length_min')<p class="mt-1 text-xs text-rose-700">{{ $message }}</p>@enderror
        @error('desired_length_max')<p class="mt-1 text-xs text-rose-700">{{ $message }}</p>@enderror

        <div>
            <label class="mb-1 block text-xs text-textSecondary">Unique angle</label>
            <textarea name="unique_angle" rows="2" class="w-full rounded border border-border bg-background px-3 py-2 text-sm">{{ old('unique_angle') }}</textarea>
        </div>

        <div>
            <label class="mb-1 block text-xs text-textSecondary">Key points</label>
            <textarea name="key_points" rows="4" class="w-full rounded border border-border bg-background px-3 py-2 text-sm" placeholder="one point per line">{{ old('key_points') }}</textarea>
        </div>

        <div>
            <label class="mb-1 block text-xs text-textSecondary">Call to action</label>
            <textarea name="call_to_action" rows="2" class="w-full rounded border border-border bg-background px-3 py-2 text-sm">{{ old('call_to_action') }}</textarea>
        </div>

        <div>
            <label class="mb-1 block text-xs text-textSecondary">Notes</label>
            <textarea name="notes" rows="4" class="w-full rounded border border-border bg-background px-3 py-2 text-sm">{{ old('notes') }}</textarea>
        </div>

        <div class="flex items-center justify-end gap-2">
            <a href="{{ route('app.content.index') }}" class="rounded border border-border px-3 py-2 text-sm">Cancel</a>
            <button class="rounded border border-border bg-background px-3 py-2 text-sm">Create content</button>
        </div>
    </form>
@endsection
