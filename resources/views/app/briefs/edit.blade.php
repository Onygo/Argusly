@extends('layouts.app', ['title' => 'Edit Brief', 'pageWidth' => 'constrained'])

@section('content')
    <div class="mb-6 flex items-start justify-between gap-3">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight text-textPrimary">Edit brief</h1>
            <p class="mt-1 text-textSecondary">Update briefing data before generating or regenerating a draft.</p>
        </div>
        <a href="{{ route('app.content.workspace.brief', $brief) }}" class="rounded border border-border px-3 py-2 text-sm">Back to workspace</a>
    </div>

    @if (session('status'))
        <x-alert class="mb-4">{{ session('status') }}</x-alert>
    @endif

    @if ($errors->has('brief'))
        <div class="mb-4 rounded-md border border-rose-500/30 bg-rose-500/10 px-3 py-2 text-sm text-rose-800">{{ $errors->first('brief') }}</div>
    @endif

    @php
        $draftStatus = $latestDraft?->status;
    @endphp
    <div class="mb-4 rounded-lg border border-border bg-surface p-4">
        <div class="grid gap-2 text-xs text-textSecondary md:grid-cols-4">
            <div>Brief status: <strong class="text-textPrimary">{{ $brief->status }}</strong></div>
            <div>Last updated: <strong class="text-textPrimary">{{ optional($brief->updated_at)->format('Y-m-d H:i:s') ?? 'n/a' }}</strong></div>
            <div>Draft status: <strong class="text-textPrimary">{{ $draftStatus ?: 'none' }}</strong></div>
            <div>Draft updated: <strong class="text-textPrimary">{{ optional($latestDraft?->updated_at)->format('Y-m-d H:i:s') ?? 'n/a' }}</strong></div>
        </div>
        @if ($latestDraft && !empty($latestDraft->last_error))
            <div class="mt-3 rounded border border-rose-500/30 bg-rose-500/10 px-3 py-2 text-xs text-rose-800">
                Last generation error: {{ $latestDraft->last_error }}
            </div>
        @endif
        @if ($latestDraft && $draftStatus === 'failed')
            <form method="POST" action="{{ route('app.content.workspace.drafts.generate', $brief) }}" class="mt-3 inline-flex">
                @csrf
                <button class="rounded border border-border px-3 py-2 text-sm">Retry draft generation</button>
            </form>
        @endif
    </div>

    @if ($errors->has('brief_intelligence'))
        <div class="mb-4 rounded-md border border-rose-500/30 bg-rose-500/10 px-3 py-2 text-sm text-rose-800">{{ $errors->first('brief_intelligence') }}</div>
    @endif

    @if (!empty($briefIntelligenceEnabled))
        @php
            $intelligence = is_array($briefIntelligenceContext ?? null) ? $briefIntelligenceContext : [];
            $completeness = is_array($intelligence['completeness'] ?? null) ? $intelligence['completeness'] : [];
            $linkedResearch = is_array($intelligence['linked_research'] ?? null) ? $intelligence['linked_research'] : [];
            $linkedProject = $intelligence['linked_research_project'] ?? null;
            $runtime = is_array($intelligence['runtime'] ?? null) ? $intelligence['runtime'] : [];
            $score = (int) ($completeness['score'] ?? 0);
            $missingInputs = (array) ($completeness['missing_inputs'] ?? []);
            $strongestInputs = (array) ($completeness['strongest_inputs'] ?? []);
            $suggestions = $brief->suggestions ?? collect();
        @endphp

        <div class="mb-4 grid gap-4 lg:grid-cols-3">
            <div class="space-y-4 lg:col-span-2">
                <div class="rounded-lg border border-border bg-surface p-4">
                    <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                        <div>
                            <h2 class="text-sm font-semibold text-textPrimary">Brief intelligence</h2>
                            <p class="mt-1 text-xs text-textSecondary">Generate suggestions without overwriting current inputs.</p>
                        </div>
                        <div class="flex items-center gap-2">
                            @if (!empty($canEnhanceBrief))
                                <form method="POST" action="{{ route('app.content.workspace.brief.enhance', $brief) }}">
                                    @csrf
                                    <button class="rounded border border-border bg-background px-3 py-1.5 text-sm">Enhance brief</button>
                                </form>
                                <form method="POST" action="{{ route('app.content.workspace.brief.enhance', $brief) }}">
                                    @csrf
                                    <input type="hidden" name="force" value="1">
                                    <button class="rounded border border-border px-3 py-1.5 text-sm">Rerun</button>
                                </form>
                            @endif
                            @if (!empty($canCreateBriefFromResearch))
                                <a href="{{ route('app.content.create') }}" class="rounded border border-border px-3 py-1.5 text-sm">Create from research</a>
                            @endif
                        </div>
                    </div>
                    <div class="grid gap-3 text-xs text-textSecondary md:grid-cols-3">
                        <div>Run status: <strong class="text-textPrimary">{{ $runtime['status'] ?? 'idle' }}</strong></div>
                        <div>Queued: <strong class="text-textPrimary">{{ !empty($runtime['queued_at']) ? \Illuminate\Support\Carbon::parse($runtime['queued_at'])->format('Y-m-d H:i') : '-' }}</strong></div>
                        <div>Completed: <strong class="text-textPrimary">{{ !empty($runtime['completed_at']) ? \Illuminate\Support\Carbon::parse($runtime['completed_at'])->format('Y-m-d H:i') : '-' }}</strong></div>
                    </div>
                    @if (!empty($runtime['failure_reason']))
                        <p class="mt-2 text-xs text-rose-700">{{ $runtime['failure_reason'] }}</p>
                    @endif
                </div>

                <div class="rounded-lg border border-border bg-surface p-4">
                    <div class="mb-2 flex items-center justify-between gap-2">
                        <h3 class="text-sm font-semibold text-textPrimary">AI suggestions</h3>
                        <span class="text-xs text-textSecondary">{{ $suggestions->count() }} total</span>
                    </div>
                    <div class="space-y-2">
                        @forelse ($suggestions as $suggestion)
                            @php
                                $format = (string) data_get($suggestion->meta, 'value_format', 'text');
                                $rawCurrent = trim((string) ($suggestion->original_value ?? ''));
                                $rawSuggested = trim((string) ($suggestion->suggested_value ?? ''));
                                $currentValues = $format === 'json' ? ((is_array(json_decode($rawCurrent, true)) ? json_decode($rawCurrent, true) : [])) : [];
                                $suggestedValues = $format === 'json' ? ((is_array(json_decode($rawSuggested, true)) ? json_decode($rawSuggested, true) : [])) : [];
                            @endphp
                            <div class="rounded border border-border bg-background p-3">
                                <div class="mb-2 flex items-center justify-between gap-2">
                                    <p class="text-sm font-medium text-textPrimary">{{ \Illuminate\Support\Str::headline((string) $suggestion->suggestion_type) }}</p>
                                    <span class="rounded border border-border px-2 py-0.5 text-[11px] uppercase tracking-wide text-textSecondary">{{ $suggestion->status }}</span>
                                </div>
                                <div class="grid gap-3 text-xs md:grid-cols-2">
                                    <div>
                                        <p class="text-textSecondary">Current value</p>
                                        @if ($format === 'json')
                                            @if (!empty($currentValues))
                                                <ul class="mt-1 list-disc pl-4 text-textPrimary">
                                                    @foreach ($currentValues as $item)
                                                        <li>{{ $item }}</li>
                                                    @endforeach
                                                </ul>
                                            @else
                                                <p class="mt-1 text-textPrimary">-</p>
                                            @endif
                                        @else
                                            <p class="mt-1 whitespace-pre-wrap text-textPrimary">{{ $rawCurrent !== '' ? $rawCurrent : '-' }}</p>
                                        @endif
                                    </div>
                                    <div>
                                        <p class="text-textSecondary">Suggested value</p>
                                        @if ($format === 'json')
                                            <ul class="mt-1 list-disc pl-4 text-textPrimary">
                                                @foreach ($suggestedValues as $item)
                                                    <li>{{ $item }}</li>
                                                @endforeach
                                            </ul>
                                        @else
                                            <p class="mt-1 whitespace-pre-wrap text-textPrimary">{{ $rawSuggested }}</p>
                                        @endif
                                    </div>
                                </div>
                                @if (!empty($suggestion->rationale))
                                    <p class="mt-2 text-xs text-textSecondary">{{ $suggestion->rationale }}</p>
                                @endif
                                @if ((string) $suggestion->status === 'pending' && !empty($canManageBriefSuggestions))
                                    <div class="mt-3 flex items-center gap-2">
                                        <form method="POST" action="{{ route('app.content.workspace.brief.suggestions.apply', [$brief, $suggestion->id]) }}">
                                            @csrf
                                            <button class="rounded border border-border bg-background px-2 py-1 text-xs">Apply</button>
                                        </form>
                                        <form method="POST" action="{{ route('app.content.workspace.brief.suggestions.reject', [$brief, $suggestion->id]) }}" class="flex items-center gap-2">
                                            @csrf
                                            <input type="text" name="reason" placeholder="Optional reason" class="w-44 rounded border border-border bg-background px-2 py-1 text-xs">
                                            <button class="rounded border border-border px-2 py-1 text-xs">Reject</button>
                                        </form>
                                    </div>
                                @endif
                            </div>
                        @empty
                            <p class="text-sm text-textSecondary">No suggestions yet. Run Enhance brief to generate suggestions.</p>
                        @endforelse
                    </div>
                </div>
            </div>

            <div class="space-y-4">
                <div class="rounded-lg border border-border bg-surface p-4">
                    <h3 class="text-sm font-semibold text-textPrimary">Brief completeness</h3>
                    <p class="mt-2 text-2xl font-semibold text-textPrimary">{{ $score }}/100</p>
                    @if (!empty($completeness['recommendation']))
                        <p class="mt-2 text-xs text-textSecondary">{{ $completeness['recommendation'] }}</p>
                    @endif
                    <div class="mt-3 text-xs">
                        <p class="font-medium text-textPrimary">Missing inputs</p>
                        @if (!empty($missingInputs))
                            <ul class="mt-1 list-disc pl-4 text-textSecondary">
                                @foreach (array_slice($missingInputs, 0, 6) as $item)
                                    <li>{{ $item }}</li>
                                @endforeach
                            </ul>
                        @else
                            <p class="mt-1 text-textSecondary">None detected.</p>
                        @endif
                    </div>
                    <div class="mt-3 text-xs">
                        <p class="font-medium text-textPrimary">Strongest inputs</p>
                        @if (!empty($strongestInputs))
                            <ul class="mt-1 list-disc pl-4 text-textSecondary">
                                @foreach (array_slice($strongestInputs, 0, 6) as $item)
                                    <li>{{ $item }}</li>
                                @endforeach
                            </ul>
                        @else
                            <p class="mt-1 text-textSecondary">Not enough signal yet.</p>
                        @endif
                    </div>
                </div>

                <div class="rounded-lg border border-border bg-surface p-4">
                    <h3 class="text-sm font-semibold text-textPrimary">Research context</h3>
                    @if ($linkedProject || !empty($linkedResearch))
                        <p class="mt-2 text-xs text-textSecondary">
                            Project: <span class="text-textPrimary">{{ $linkedProject?->name ?? ($linkedResearch['project_name'] ?? 'Linked project') }}</span>
                        </p>
                        @if ($linkedProject)
                            <p class="mt-1 text-xs text-textSecondary">Status: {{ strtoupper((string) ($linkedProject->status?->value ?? $linkedProject->status)) }}</p>
                            <a href="{{ route('app.research.show', $linkedProject) }}" class="mt-2 inline-flex rounded border border-border px-2 py-1 text-xs">Open project</a>
                        @endif
                        @if (!empty($intelligence['intelligence_summary']))
                            <p class="mt-3 whitespace-pre-wrap text-xs text-textPrimary">{{ $intelligence['intelligence_summary'] }}</p>
                        @endif
                    @else
                        <p class="mt-2 text-xs text-textSecondary">No linked research yet.</p>
                        @if (!empty($canCreateBriefFromResearch))
                            <a href="{{ route('app.content.create') }}" class="mt-2 inline-flex rounded border border-border px-2 py-1 text-xs">Create from research</a>
                        @endif
                    @endif
                </div>

                @if (!empty($canCreateBriefFromResearch))
                    <div class="rounded-lg border border-border bg-surface p-4">
                        <h3 class="text-sm font-semibold text-textPrimary">Create another brief from research</h3>
                        <form method="POST" action="{{ route('app.content.create.from-research') }}" class="mt-3 space-y-2">
                            @csrf
                            <select name="research_project_id" class="pl-select bg-background" required>
                                <option value="">Select research project</option>
                                @foreach (($researchProjects ?? collect()) as $project)
                                    <option value="{{ $project->id }}">{{ $project->name }} · {{ strtoupper((string) ($project->status?->value ?? $project->status)) }}</option>
                                @endforeach
                            </select>
                            <input type="hidden" name="site_id" value="{{ $brief->client_site_id }}">
                            <button class="rounded border border-border bg-background px-3 py-1.5 text-xs">Create from research</button>
                        </form>
                    </div>
                @endif
            </div>
        </div>
    @endif

    <form method="POST" action="{{ route('app.content.workspace.brief.update', $brief) }}" class="space-y-4 rounded-lg border border-border bg-surface p-4">
        @csrf
        @method('PUT')

        <div class="grid gap-3 md:grid-cols-4">
            <div>
                <label class="mb-1 block text-xs text-textSecondary">Site</label>
                @if (!empty($canChangeBriefSite))
                    <select name="site_id" class="pl-select bg-background" required>
                        @foreach ($sites as $site)
                            <option value="{{ $site->id }}" @selected(old('site_id', (string) $brief->client_site_id) === (string) $site->id)>{{ $site->name }}</option>
                        @endforeach
                    </select>
                    <p class="mt-1 text-xs text-textSecondary">Can be changed until the first draft or publication is created.</p>
                @else
                    <input type="hidden" name="site_id" value="{{ $brief->client_site_id }}">
                    <select class="pl-select bg-background" disabled>
                        @foreach ($sites as $site)
                            <option value="{{ $site->id }}" @selected((string) $brief->client_site_id === (string) $site->id)>{{ $site->name }}</option>
                        @endforeach
                    </select>
                    <p class="mt-1 text-xs text-textSecondary">Locked after draft generation or publication starts.</p>
                @endif
                @error('site_id')<p class="mt-1 text-xs text-rose-700">{{ $message }}</p>@enderror
            </div>
            <div>
                <label class="mb-1 block text-xs text-textSecondary">Language</label>
                <select name="language" class="pl-select bg-background" required>
                    <option value="nl" @selected(old('language', $brief->language) === 'nl')>Dutch (NL)</option>
                    <option value="en" @selected(old('language', $brief->language) === 'en')>English (EN)</option>
                </select>
            </div>
            <div>
                <label class="mb-1 block text-xs text-textSecondary">Content type</label>
                <select name="content_type" class="pl-select bg-background" required>
                    @foreach ($contentTypeOptions as $value => $label)
                        <option value="{{ $value }}" @selected(old('content_type', $brief->content_type ?: 'blog') === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="mb-1 block text-xs text-textSecondary">Status</label>
                <select name="status" class="pl-select bg-background">
                    <option value="">Keep current</option>
                    <option value="draft" @selected(old('status', $brief->status) === 'draft')>Draft</option>
                    <option value="ready_for_generation" @selected(old('status', $brief->status) === 'ready_for_generation')>Ready for generation</option>
                    <option value="queued" @selected(old('status', $brief->status) === 'queued')>Queued</option>
                    <option value="archived" @selected(old('status', $brief->status) === 'archived')>Archived</option>
                </select>
            </div>
        </div>

        <div>
            <label class="mb-1 block text-xs text-textSecondary">Title</label>
            <input type="text" name="title" value="{{ old('title', $brief->title) }}" class="w-full rounded border border-border bg-background px-3 py-2 text-sm" maxlength="255" required>
            @error('title')<p class="mt-1 text-xs text-rose-700">{{ $message }}</p>@enderror
        </div>

        <div class="grid gap-3 md:grid-cols-2">
            <div>
                <label class="mb-1 block text-xs text-textSecondary">Primary keyword</label>
                <input type="text" name="primary_keyword" value="{{ old('primary_keyword', $brief->primary_keyword) }}" class="w-full rounded border border-border bg-background px-3 py-2 text-sm" maxlength="255">
            </div>
            <div>
                <label class="mb-1 block text-xs text-textSecondary">Secondary keywords</label>
                <textarea name="secondary_keywords" rows="2" class="w-full rounded border border-border bg-background px-3 py-2 text-sm" placeholder="comma or new line separated">{{ old('secondary_keywords', implode("\n", (array) ($brief->secondary_keywords ?? []))) }}</textarea>
            </div>
        </div>

        <div class="grid gap-3 md:grid-cols-2">
            <div>
                <label class="mb-1 block text-xs text-textSecondary">Target audience</label>
                @php
                    $audienceSelected = (array) old('audience_keys', $selectedAudienceKeys ?? []);
                @endphp
                <select name="audience_keys[]" class="pl-select bg-background" multiple size="5">
                    @foreach ($audienceOptions as $value => $label)
                        <option value="{{ $value }}" @selected(in_array($value, $audienceSelected, true))>{{ $label }}</option>
                    @endforeach
                </select>
                <p class="mt-1 text-xs text-textSecondary">Select one or more audience tags.</p>
                @error('audience_keys')<p class="mt-1 text-xs text-rose-700">{{ $message }}</p>@enderror
                @error('audience_keys.*')<p class="mt-1 text-xs text-rose-700">{{ $message }}</p>@enderror
            </div>
            <div>
                <label class="mb-1 block text-xs text-textSecondary">Tone of voice</label>
                <textarea name="tone_of_voice" rows="2" class="w-full rounded border border-border bg-background px-3 py-2 text-sm">{{ old('tone_of_voice', $brief->tone_of_voice) }}</textarea>
            </div>
        </div>

        <div class="grid gap-3 md:grid-cols-3">
            <div>
                <label class="mb-1 block text-xs text-textSecondary">Funnel stage</label>
                <select name="funnel_stage" class="pl-select bg-background">
                    <option value="">-</option>
                    @foreach ($funnelStageOptions as $value => $label)
                        <option value="{{ $value }}" @selected(old('funnel_stage', $brief->funnel_stage) === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="mb-1 block text-xs text-textSecondary">Search intent</label>
                <select name="search_intent" class="pl-select bg-background">
                    <option value="">-</option>
                    @foreach ($searchIntentOptions as $value => $label)
                        <option value="{{ $value }}" @selected(old('search_intent', $brief->search_intent) === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="grid grid-cols-2 gap-2">
                <div>
                    <label class="mb-1 block text-xs text-textSecondary">Min words</label>
                    <input type="number" name="desired_length_min" value="{{ old('desired_length_min', $brief->desired_length_min ?: 900) }}" class="w-full rounded border border-border bg-background px-3 py-2 text-sm" min="300" max="10000">
                </div>
                <div>
                    <label class="mb-1 block text-xs text-textSecondary">Max words</label>
                    <input type="number" name="desired_length_max" value="{{ old('desired_length_max', $brief->desired_length_max ?: 1200) }}" class="w-full rounded border border-border bg-background px-3 py-2 text-sm" min="300" max="10000">
                </div>
            </div>
        </div>
        @error('desired_length_min')<p class="mt-1 text-xs text-rose-700">{{ $message }}</p>@enderror
        @error('desired_length_max')<p class="mt-1 text-xs text-rose-700">{{ $message }}</p>@enderror

        <div>
            <label class="mb-1 block text-xs text-textSecondary">Unique angle</label>
            <textarea name="unique_angle" rows="2" class="w-full rounded border border-border bg-background px-3 py-2 text-sm">{{ old('unique_angle', $brief->unique_angle) }}</textarea>
        </div>

        <div>
            <label class="mb-1 block text-xs text-textSecondary">Key points</label>
            <textarea name="key_points" rows="4" class="w-full rounded border border-border bg-background px-3 py-2 text-sm">{{ old('key_points', implode("\n", (array) ($brief->key_points ?? []))) }}</textarea>
        </div>

        <div>
            <label class="mb-1 block text-xs text-textSecondary">Call to action</label>
            <textarea name="call_to_action" rows="2" class="w-full rounded border border-border bg-background px-3 py-2 text-sm">{{ old('call_to_action', $brief->call_to_action) }}</textarea>
        </div>

        <div>
            <label class="mb-1 block text-xs text-textSecondary">Notes</label>
            <textarea name="notes" rows="4" class="w-full rounded border border-border bg-background px-3 py-2 text-sm">{{ old('notes', $brief->notes) }}</textarea>
        </div>

        @if ($brief->status !== 'archived')
            @php
                $selectedTokens = (int) old('requested_max_output_tokens', $outputTokenOptions['standard'] ?? 8000);
            @endphp
            <div class="rounded border border-border bg-background p-3">
                <label class="mb-1 block text-xs text-textSecondary" for="requested_max_output_tokens_{{ $brief->id }}">Output size (for save and generate)</label>
                <select id="requested_max_output_tokens_{{ $brief->id }}" name="requested_max_output_tokens" class="w-full rounded border border-border bg-background px-3 py-2 text-sm" data-credit-preview-select>
                    <option value="{{ $outputTokenOptions['standard'] }}" @selected($selectedTokens === (int) $outputTokenOptions['standard'])>
                        Standard ({{ number_format((int) $outputTokenOptions['standard']) }} tokens)
                    </option>
                    <option value="{{ $outputTokenOptions['long'] }}" @selected($selectedTokens === (int) $outputTokenOptions['long'])>
                        Long ({{ number_format((int) $outputTokenOptions['long']) }} tokens)
                    </option>
                    @if ((int) $outputTokenOptions['max'] !== (int) $outputTokenOptions['long'])
                        <option value="{{ $outputTokenOptions['max'] }}" @selected($selectedTokens === (int) $outputTokenOptions['max'])>
                            Extended ({{ number_format((int) $outputTokenOptions['max']) }} tokens)
                        </option>
                    @endif
                </select>
                <p class="mt-2 text-xs text-textSecondary">Estimated credits: <strong data-credit-preview-label></strong> (Max {{ (int) $maxCredits }} credits)</p>
            </div>
        @endif

        <div class="flex items-center justify-end gap-2">
            <a href="{{ route('app.content.workspace.brief', $brief) }}" class="rounded border border-border px-3 py-2 text-sm">Cancel</a>
            @if ($brief->status !== 'archived')
                <button type="submit" name="generate_draft" value="1" class="rounded border border-border bg-background px-3 py-2 text-sm">Save and generate draft</button>
            @endif
            <button class="rounded border border-border bg-background px-3 py-2 text-sm">Save changes</button>
        </div>
    </form>

    <script>
        (() => {
            const map = {
                '{{ (int) $outputTokenOptions['standard'] }}': {{ (int) ($estimatedCredits['standard'] ?? 10) }},
                '{{ (int) $outputTokenOptions['long'] }}': {{ (int) ($estimatedCredits['long'] ?? 12) }},
                '{{ (int) $outputTokenOptions['max'] }}': {{ (int) ($estimatedCredits['max'] ?? (int) $maxCredits) }},
            };
            const update = (select) => {
                const label = select.closest('.rounded')?.querySelector('[data-credit-preview-label]');
                if (!label) return;
                label.textContent = String(map[select.value] ?? {{ (int) ($estimatedCredits['standard'] ?? 10) }});
            };
            document.querySelectorAll('[data-credit-preview-select]').forEach((select) => {
                update(select);
                select.addEventListener('change', () => update(select));
            });
        })();
    </script>
@endsection
