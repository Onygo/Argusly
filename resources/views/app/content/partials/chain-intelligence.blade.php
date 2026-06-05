@php
    $guidance = $chainGuidance;
    $growthSuggestions = collect($chainSuggestions ?? [])->where('suggestion_kind', \App\Models\ContentChainSuggestion::KIND_GROWTH)->values();
    $inlineSuggestions = collect($chainSuggestions ?? [])->where('suggestion_kind', \App\Models\ContentChainSuggestion::KIND_INLINE_LINK)->values();
    $footerSuggestions = collect($chainSuggestions ?? [])->where('suggestion_kind', \App\Models\ContentChainSuggestion::KIND_FOOTER_LINK)->values();
@endphp

@if ($contentChainEnabled ?? false)
    <div class="mt-4 grid gap-4 xl:grid-cols-[1.2fr,1.8fr]">
        <section class="rounded-lg border border-border bg-surface p-4">
            <div class="mb-3 flex items-start justify-between gap-3">
                <div>
                    <h3 class="text-sm font-semibold text-textPrimary">Chained Content Guidance</h3>
                    <p class="mt-1 text-xs text-textSecondary">Editorial steering for follow-up ideas and contextual inline linking.</p>
                </div>
                <form method="POST" action="{{ route('app.content.chain-suggestions.refresh', $content) }}">
                    @csrf
                    <button class="rounded border border-border px-3 py-2 text-xs font-medium text-textPrimary hover:bg-surfaceSubtle">
                        Refresh suggestions
                    </button>
                </form>
            </div>

            <form method="POST" action="{{ route('app.content.chain-guidance.update', $content) }}" class="space-y-3">
                @csrf
                <label class="flex items-center gap-2 text-sm text-textPrimary">
                    <input type="checkbox" name="is_source_enabled" value="1" @checked($guidance?->is_source_enabled) class="rounded border-border text-primary focus:ring-primary">
                    Mark this article as a source for chained follow-up content
                </label>

                <div class="grid gap-3 md:grid-cols-2">
                    <div>
                        <label class="mb-1 block text-xs text-textSecondary">Preferred angle</label>
                        <input type="text" name="preferred_angle" value="{{ old('preferred_angle', $guidance?->preferred_angle) }}" class="w-full rounded border border-border px-3 py-2 text-sm" placeholder="Operational rollout, editorial governance, buyer evaluation">
                    </div>
                    <div>
                        <label class="mb-1 block text-xs text-textSecondary">Goal type</label>
                        <select name="goal_type" class="w-full rounded border border-border px-3 py-2 text-sm">
                            <option value="">Use strongest detected format</option>
                            @foreach (['deepening' => 'Deepening', 'cluster_support' => 'Cluster support', 'conversion_piece' => 'Conversion piece', 'faq' => 'FAQ', 'comparison' => 'Comparison', 'how_to' => 'How-to', 'use_case' => 'Use case'] as $value => $label)
                                <option value="{{ $value }}" @selected(old('goal_type', $guidance?->goal_type) === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs text-textSecondary">Priority</label>
                        <select name="priority" class="w-full rounded border border-border px-3 py-2 text-sm">
                            @foreach (['low' => 'Low', 'medium' => 'Medium', 'high' => 'High', 'critical' => 'Critical'] as $value => $label)
                                <option value="{{ $value }}" @selected(old('priority', $guidance?->priority ?? 'medium') === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs text-textSecondary">Explicit follow-up topic</label>
                        <input type="text" name="explicit_topic" value="{{ old('explicit_topic', $guidance?->explicit_topic) }}" class="w-full rounded border border-border px-3 py-2 text-sm" placeholder="AI governance playbook for scaleups">
                    </div>
                    <div>
                        <label class="mb-1 block text-xs text-textSecondary">Target keyword</label>
                        <input type="text" name="target_keyword" value="{{ old('target_keyword', $guidance?->target_keyword) }}" class="w-full rounded border border-border px-3 py-2 text-sm" placeholder="ai governance workflow">
                    </div>
                    <div>
                        <label class="mb-1 block text-xs text-textSecondary">Target audience</label>
                        <input type="text" name="target_audience" value="{{ old('target_audience', $guidance?->target_audience) }}" class="w-full rounded border border-border px-3 py-2 text-sm" placeholder="Content ops leads, editorial managers">
                    </div>
                    <div>
                        <label class="mb-1 block text-xs text-textSecondary">Target intent</label>
                        <input type="text" name="target_intent" value="{{ old('target_intent', $guidance?->target_intent) }}" class="w-full rounded border border-border px-3 py-2 text-sm" placeholder="Informational, commercial, problem-aware">
                    </div>
                    <div>
                        <label class="mb-1 block text-xs text-textSecondary">Inline linking mode</label>
                        <select name="inline_link_mode" class="w-full rounded border border-border px-3 py-2 text-sm">
                            @foreach (['automatic' => 'Automatic', 'suggestions_only' => 'Suggestions only', 'review' => 'Manual review', 'off' => 'Off'] as $value => $label)
                                <option value="{{ $value }}" @selected(old('inline_link_mode', $guidance?->inline_link_mode ?? config('content_chain.inline_links.default_mode', 'review')) === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>

                <div class="grid gap-3 md:grid-cols-[auto,140px]">
                    <label class="flex items-center gap-2 text-sm text-textPrimary">
                        <input type="checkbox" name="allow_heading_links" value="1" @checked($guidance?->allow_heading_links) class="rounded border-border text-primary focus:ring-primary">
                        Allow inline link detection in headings
                    </label>
                    <div>
                        <label class="mb-1 block text-xs text-textSecondary">Max inline links</label>
                        <input type="number" min="1" max="10" name="max_inline_links" value="{{ old('max_inline_links', $guidance?->max_inline_links ?? config('content_chain.inline_links.default_max_links', 4)) }}" class="w-full rounded border border-border px-3 py-2 text-sm">
                    </div>
                </div>

                <div>
                    <label class="mb-1 block text-xs text-textSecondary">Editor notes</label>
                    <textarea name="editor_notes" rows="3" class="w-full rounded border border-border px-3 py-2 text-sm" placeholder="Why this article is a strong source, missing subthemes, commercial angle, review notes...">{{ old('editor_notes', $guidance?->editor_notes) }}</textarea>
                </div>

                <button class="rounded border border-border px-3 py-2 text-sm font-medium text-textPrimary hover:bg-surfaceSubtle">
                    Save chained guidance
                </button>
            </form>
        </section>

        <section class="rounded-lg border border-border bg-surface p-4">
            <div class="mb-3 flex items-start justify-between gap-3">
                <div>
                    <h3 class="text-sm font-semibold text-textPrimary">Chained Opportunities</h3>
                    <p class="mt-1 text-xs text-textSecondary">Growth suggestions, contextual inline links and fallback footer links.</p>
                </div>
                @if ($inlineSuggestions->where('status', \App\Models\ContentChainSuggestion::STATUS_APPROVED)->isNotEmpty() || $footerSuggestions->where('status', \App\Models\ContentChainSuggestion::STATUS_APPROVED)->isNotEmpty())
                    <form method="POST" action="{{ route('app.content.chain-suggestions.apply-approved-links', $content) }}">
                        @csrf
                        <button class="rounded border border-border px-3 py-2 text-xs font-medium text-textPrimary hover:bg-surfaceSubtle">
                            Apply approved links
                        </button>
                    </form>
                @endif
            </div>

            <div class="grid gap-3 md:grid-cols-3 mb-4">
                <div class="rounded border border-border bg-background p-3">
                    <p class="text-xs text-textSecondary">Growth suggestions</p>
                    <p class="mt-1 text-lg font-semibold text-textPrimary">{{ $growthSuggestions->count() }}</p>
                </div>
                <div class="rounded border border-border bg-background p-3">
                    <p class="text-xs text-textSecondary">Inline link suggestions</p>
                    <p class="mt-1 text-lg font-semibold text-textPrimary">{{ $inlineSuggestions->count() }}</p>
                </div>
                <div class="rounded border border-border bg-background p-3">
                    <p class="text-xs text-textSecondary">Fallback footer links</p>
                    <p class="mt-1 text-lg font-semibold text-textPrimary">{{ $footerSuggestions->count() }}</p>
                </div>
            </div>

            <div class="space-y-4">
                <div>
                    <h4 class="text-xs font-semibold uppercase tracking-wide text-textSecondary">Growth Suggestions</h4>
                    @if ($growthSuggestions->isEmpty())
                        <p class="mt-2 text-sm text-textSecondary">No growth suggestions yet. Save guidance or refresh to analyze this article as a chain source.</p>
                    @else
                        <div class="mt-2 space-y-3">
                            @foreach ($growthSuggestions as $suggestion)
                                <div class="rounded border border-border bg-background p-3">
                                    <div class="flex flex-wrap items-start justify-between gap-2">
                                        <div>
                                            <p class="text-sm font-medium text-textPrimary">{{ $suggestion->title }}</p>
                                            <p class="mt-1 text-xs text-textSecondary">{{ ucfirst(str_replace('_', ' ', $suggestion->suggestion_type)) }} · {{ strtoupper($suggestion->status) }}</p>
                                        </div>
                                        <div class="text-right text-xs text-textSecondary">
                                            <div>Score <span class="font-medium text-textPrimary">{{ number_format((float) ($suggestion->score ?? 0), 1) }}</span></div>
                                            @if ($suggestion->generatedContent)
                                                <a href="{{ route('app.content.show', $suggestion->generatedContent) }}" class="mt-1 inline-block underline text-link hover:text-linkHover">Open created content</a>
                                            @endif
                                        </div>
                                    </div>
                                    <p class="mt-2 text-sm text-textSecondary">{{ $suggestion->rationale }}</p>
                                    <div class="mt-2 text-xs text-textSecondary">
                                        Goal: <span class="text-textPrimary">{{ $suggestion->goal_type ?: 'auto' }}</span>
                                        @if (!empty(data_get($suggestion->meta, 'target_keyword')))
                                            · Keyword: <span class="text-textPrimary">{{ data_get($suggestion->meta, 'target_keyword') }}</span>
                                        @endif
                                    </div>
                                    <div class="mt-3 flex flex-wrap gap-2">
                                        @if ($suggestion->status === \App\Models\ContentChainSuggestion::STATUS_SUGGESTED)
                                            <form method="POST" action="{{ route('app.content.chain-suggestions.approve', [$content, $suggestion]) }}">
                                                @csrf
                                                <button class="rounded border border-emerald-500/40 bg-emerald-500/10 px-2 py-1 text-xs text-emerald-700">Approve</button>
                                            </form>
                                            <form method="POST" action="{{ route('app.content.chain-suggestions.reject', [$content, $suggestion]) }}">
                                                @csrf
                                                <button class="rounded border border-rose-500/40 bg-rose-500/10 px-2 py-1 text-xs text-rose-700">Reject</button>
                                            </form>
                                        @endif
                                        @if (in_array($suggestion->status, [\App\Models\ContentChainSuggestion::STATUS_SUGGESTED, \App\Models\ContentChainSuggestion::STATUS_APPROVED], true))
                                            <form method="POST" action="{{ route('app.content.chain-suggestions.create', [$content, $suggestion]) }}">
                                                @csrf
                                                <button class="rounded border border-border px-2 py-1 text-xs text-textPrimary hover:bg-surfaceSubtle">Create chained article</button>
                                            </form>
                                        @endif
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>

                <div>
                    <h4 class="text-xs font-semibold uppercase tracking-wide text-textSecondary">Contextual Inline Links</h4>
                    @if ($inlineSuggestions->isEmpty())
                        <p class="mt-2 text-sm text-textSecondary">No contextual inline link suggestions available yet.</p>
                    @else
                        <div class="mt-2 space-y-3">
                            @foreach ($inlineSuggestions as $suggestion)
                                <div class="rounded border border-border bg-background p-3">
                                    <div class="flex flex-wrap items-start justify-between gap-2">
                                        <div>
                                            <p class="text-sm font-medium text-textPrimary">{{ $suggestion->targetContent?->title ?? $suggestion->title }}</p>
                                            <p class="mt-1 text-xs text-textSecondary">Anchor "{{ $suggestion->anchor_text }}" · {{ strtoupper($suggestion->status) }}</p>
                                        </div>
                                        <div class="text-right text-xs text-textSecondary">
                                            <div>Confidence <span class="font-medium text-textPrimary">{{ number_format((float) ($suggestion->confidence_score ?? 0), 2) }}</span></div>
                                            <div>{{ $suggestion->placement_label ?: 'Inline paragraph' }}</div>
                                        </div>
                                    </div>
                                    <p class="mt-2 text-xs text-textSecondary">{{ data_get($suggestion->placement_meta, 'context') }}</p>
                                    <div class="mt-3 flex flex-wrap gap-2">
                                        @if ($suggestion->status === \App\Models\ContentChainSuggestion::STATUS_SUGGESTED)
                                            <form method="POST" action="{{ route('app.content.chain-suggestions.approve', [$content, $suggestion]) }}">
                                                @csrf
                                                <button class="rounded border border-emerald-500/40 bg-emerald-500/10 px-2 py-1 text-xs text-emerald-700">Approve</button>
                                            </form>
                                            <form method="POST" action="{{ route('app.content.chain-suggestions.reject', [$content, $suggestion]) }}">
                                                @csrf
                                                <button class="rounded border border-rose-500/40 bg-rose-500/10 px-2 py-1 text-xs text-rose-700">Reject</button>
                                            </form>
                                        @endif
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>

                <div>
                    <h4 class="text-xs font-semibold uppercase tracking-wide text-textSecondary">Footer Fallback Links</h4>
                    @if ($footerSuggestions->isEmpty())
                        <p class="mt-2 text-sm text-textSecondary">No supplementary footer links needed right now.</p>
                    @else
                        <div class="mt-2 space-y-3">
                            @foreach ($footerSuggestions as $suggestion)
                                <div class="rounded border border-border bg-background p-3">
                                    <div class="flex flex-wrap items-start justify-between gap-2">
                                        <div>
                                            <p class="text-sm font-medium text-textPrimary">{{ $suggestion->targetContent?->title ?? $suggestion->title }}</p>
                                            <p class="mt-1 text-xs text-textSecondary">{{ strtoupper($suggestion->status) }} · {{ $suggestion->placement_label ?: 'Additional reading' }}</p>
                                        </div>
                                        <div class="text-xs text-textSecondary">
                                            Confidence <span class="font-medium text-textPrimary">{{ number_format((float) ($suggestion->confidence_score ?? 0), 2) }}</span>
                                        </div>
                                    </div>
                                    <p class="mt-2 text-xs text-textSecondary">{{ $suggestion->rationale }}</p>
                                    <div class="mt-3 flex flex-wrap gap-2">
                                        @if ($suggestion->status === \App\Models\ContentChainSuggestion::STATUS_SUGGESTED)
                                            <form method="POST" action="{{ route('app.content.chain-suggestions.approve', [$content, $suggestion]) }}">
                                                @csrf
                                                <button class="rounded border border-emerald-500/40 bg-emerald-500/10 px-2 py-1 text-xs text-emerald-700">Approve</button>
                                            </form>
                                            <form method="POST" action="{{ route('app.content.chain-suggestions.reject', [$content, $suggestion]) }}">
                                                @csrf
                                                <button class="rounded border border-rose-500/40 bg-rose-500/10 px-2 py-1 text-xs text-rose-700">Reject</button>
                                            </form>
                                        @endif
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>
            </div>
        </section>
    </div>
@endif
