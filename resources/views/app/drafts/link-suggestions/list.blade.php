@if ($linkSuggestions->isEmpty())
    <div class="rounded-md border border-dashed border-border p-4 text-sm text-textSecondary">
        No suggestions yet.
    </div>
@else
    <div class="space-y-3">
        @foreach ($linkSuggestions as $suggestion)
            <div class="rounded-md border border-border p-4">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <div>
                        <div class="font-medium text-textPrimary">{{ $suggestion->targetArticle?->title ?? 'Unknown target' }}</div>
                        <div class="text-xs text-textSecondary">{{ $suggestion->targetArticle?->clientSite?->site_url }}</div>
                    </div>
                    <div class="text-xs px-2 py-1 rounded bg-surfaceSubtle text-textPrimary">{{ $suggestion->status }}</div>
                </div>

                <div class="mt-3 grid gap-2 text-xs text-textSecondary md:grid-cols-4">
                    <div>Similarity: <span class="text-textPrimary">{{ number_format($suggestion->similarity_score, 2) }}</span></div>
                    <div>Intent: <span class="text-textPrimary">{{ number_format($suggestion->intent_match_score, 2) }}</span></div>
                    <div>Audience: <span class="text-textPrimary">{{ number_format($suggestion->audience_overlap_score, 2) }}</span></div>
                    <div>Placement: <span class="text-textPrimary">{{ $suggestion->suggested_placement }}</span></div>
                </div>

                @if (!empty($suggestion->shared_entities))
                    <div class="mt-2 text-xs text-textSecondary">
                        Shared entities: <span class="text-textPrimary">{{ implode(', ', $suggestion->shared_entities) }}</span>
                    </div>
                @endif

                @if (!empty($suggestion->suggested_anchor_variants))
                    <div class="mt-2 text-xs text-textSecondary">
                        Anchors: <span class="text-textPrimary">{{ implode(' | ', $suggestion->suggested_anchor_variants) }}</span>
                    </div>
                @endif

                <div class="mt-4 flex flex-wrap gap-2">
                    @if (in_array($suggestion->status, ['suggested', 'draft'], true))
                        <form method="POST" action="{{ route('app.drafts.link-suggestions.approve', [$draft, $suggestion]) }}" class="flex flex-wrap items-center gap-2">
                            @csrf
                            <input type="text" name="anchor_text" placeholder="Custom anchor" class="rounded-md border border-border px-2 py-1 text-xs" />
                            <select name="suggested_placement" class="rounded-md border border-border px-2 py-1 text-xs">
                                <option value="inline" @selected($suggestion->suggested_placement === 'inline')>Inline</option>
                                <option value="footnote" @selected($suggestion->suggested_placement === 'footnote')>Footnote</option>
                            </select>
                            <label class="text-xs text-textSecondary inline-flex items-center gap-1">
                                <input type="checkbox" name="apply_now" value="1" /> Apply now
                            </label>
                            <button class="rounded-md border border-emerald-500/40 bg-emerald-500/10 px-2 py-1 text-xs text-emerald-700">Approve</button>
                        </form>

                        <form method="POST" action="{{ route('app.drafts.link-suggestions.reject', [$draft, $suggestion]) }}">
                            @csrf
                            <button class="rounded-md border border-rose-500/40 bg-rose-500/10 px-2 py-1 text-xs text-rose-700">Reject</button>
                        </form>
                    @endif

                    @if ($suggestion->status === 'approved')
                        <form method="POST" action="{{ route('app.drafts.link-suggestions.apply', [$draft, $suggestion]) }}" class="flex flex-wrap items-center gap-2">
                            @csrf
                            <input type="text" name="anchor_text" placeholder="Anchor override" class="rounded-md border border-border px-2 py-1 text-xs" />
                            <select name="placement" class="rounded-md border border-border px-2 py-1 text-xs">
                                <option value="inline" @selected($suggestion->suggested_placement === 'inline')>Inline</option>
                                <option value="footnote" @selected($suggestion->suggested_placement === 'footnote')>Footnote</option>
                            </select>
                            <button class="rounded-md border border-indigo-500/40 bg-indigo-500/10 px-2 py-1 text-xs text-indigo-700">Apply</button>
                        </form>
                    @endif

                    @if ($suggestion->status === 'rejected')
                        <form method="POST" action="{{ route('app.drafts.link-suggestions.delete', [$draft, $suggestion]) }}">
                            @csrf
                            <button class="rounded-md border border-border px-2 py-1 text-xs text-textPrimary hover:bg-surfaceSubtle">Remove</button>
                        </form>
                    @endif
                </div>
            </div>
        @endforeach
    </div>
@endif
