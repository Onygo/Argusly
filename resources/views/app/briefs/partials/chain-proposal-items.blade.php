@php
    $proposalItems = collect((array) data_get($generatedChain ?? [], 'proposal_items', []));
    if ($proposalItems->isEmpty()) {
        $proposalItems = collect([
            [
                'title' => (string) data_get($generatedChain ?? [], 'pillar_topic', data_get($generatedBrief ?? [], 'working_title', 'Pillar article')),
                'content_type' => 'cornerstone_article',
                'primary_keyword' => (string) data_get($generatedBrief ?? [], 'primary_keyword', ''),
                'secondary_keywords' => (array) data_get($generatedBrief ?? [], 'secondary_keywords', []),
                'search_intent' => (string) data_get($generatedBrief ?? [], 'search_intent', ''),
                'funnel_stage' => '',
                'target_audience' => (string) data_get($generatedBrief ?? [], 'target_audience', ''),
                'angle' => (string) data_get($generatedBrief ?? [], 'summary', ''),
                'key_points' => (array) data_get($generatedBrief ?? [], 'key_talking_points', []),
                'cta' => (string) data_get($generatedBrief ?? [], 'cta_recommendation', ''),
                'suggested_internal_links' => [],
                'status' => 'proposed',
            ],
        ])->merge(collect((array) data_get($generatedChain ?? [], 'supporting_subtopics', []))->map(fn ($row) => [
            'title' => (string) data_get($row, 'title', ''),
            'content_type' => (string) data_get($row, 'content_type', 'supporting_blog'),
            'primary_keyword' => (string) data_get($row, 'primary_keyword', data_get($row, 'title', '')),
            'secondary_keywords' => (array) data_get($row, 'secondary_keywords', []),
            'search_intent' => (string) data_get($row, 'search_intent', data_get($generatedBrief ?? [], 'search_intent', '')),
            'funnel_stage' => (string) data_get($row, 'funnel_stage', ''),
            'target_audience' => (string) data_get($row, 'target_audience', data_get($generatedBrief ?? [], 'target_audience', '')),
            'angle' => (string) data_get($row, 'angle', data_get($row, 'internal_link_to', '')),
            'key_points' => (array) data_get($row, 'key_points', []),
            'cta' => (string) data_get($row, 'cta', data_get($generatedBrief ?? [], 'cta_recommendation', '')),
            'suggested_internal_links' => (array) data_get($row, 'suggested_internal_links', [data_get($row, 'internal_link_to', '')]),
            'status' => 'proposed',
        ]));
    }

    $contentTypeLabels = [
        'cornerstone_article' => 'Cornerstone article',
        'supporting_blog' => 'Supporting blog',
        'comparison_article' => 'Comparison article',
        'faq_article' => 'FAQ article',
        'linkedin_post' => 'LinkedIn post',
        'landing_page' => 'Landing page',
        'newsletter' => 'Newsletter',
        'source_analysis' => 'Source analysis',
    ];
    $proposalItems = $proposalItems->values()->push([
        'title' => '',
        'content_type' => 'supporting_blog',
        'primary_keyword' => '',
        'secondary_keywords' => [],
        'search_intent' => '',
        'funnel_stage' => '',
        'target_audience' => '',
        'angle' => '',
        'key_points' => [],
        'cta' => '',
        'suggested_internal_links' => [],
        'status' => 'skipped',
    ]);
@endphp

<div class="rounded-md border border-border bg-background p-3">
    <div>
        <p class="text-sm font-semibold text-textPrimary">Review proposed chain items</p>
        <p class="mt-1 text-xs text-textSecondary">Review and approve the proposed chain items before creating them. Reorder with the order field, set unwanted rows to skipped, or edit fields directly.</p>
    </div>

    <div class="mt-3 space-y-3">
        @foreach($proposalItems->values() as $index => $item)
            @php
                $prefix = 'chain_items.' . $index . '.';
                $secondary = old($prefix . 'secondary_keywords', implode("\n", (array) data_get($item, 'secondary_keywords', [])));
                $keyPoints = old($prefix . 'key_points', implode("\n", (array) data_get($item, 'key_points', [])));
                $links = old($prefix . 'suggested_internal_links', implode("\n", array_filter((array) data_get($item, 'suggested_internal_links', []))));
                $status = old($prefix . 'status', data_get($item, 'status') === 'skipped' ? 'skipped' : 'approved');
            @endphp
            <div class="rounded border border-border bg-surface p-3">
                <div class="grid gap-2 md:grid-cols-[4rem_1fr]">
                    <div>
                        <label class="mb-1 block text-[11px] text-textSecondary">Order</label>
                        <input type="number" name="chain_items[{{ $index }}][order]" value="{{ old($prefix . 'order', $index + 1) }}" min="1" max="100" class="w-full rounded border border-border bg-background px-2 py-1.5 text-sm">
                    </div>
                    <div>
                        <label class="mb-1 block text-[11px] text-textSecondary">Title</label>
                        <input type="text" name="chain_items[{{ $index }}][title]" value="{{ old($prefix . 'title', data_get($item, 'title')) }}" class="w-full rounded border border-border bg-background px-2 py-1.5 text-sm" maxlength="255">
                    </div>
                </div>

                <div class="mt-2 grid gap-2 md:grid-cols-2">
                    <div>
                        <label class="mb-1 block text-[11px] text-textSecondary">Content type</label>
                        <select name="chain_items[{{ $index }}][content_type]" class="pl-select bg-background text-sm">
                            @foreach($contentTypeLabels as $value => $label)
                                <option value="{{ $value }}" @selected(old($prefix . 'content_type', data_get($item, 'content_type', 'supporting_blog')) === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="mb-1 block text-[11px] text-textSecondary">Status</label>
                        <select name="chain_items[{{ $index }}][status]" class="pl-select bg-background text-sm">
                            <option value="proposed" @selected($status === 'proposed')>Proposed</option>
                            <option value="approved" @selected($status === 'approved')>Approved</option>
                            <option value="skipped" @selected($status === 'skipped')>Skipped</option>
                        </select>
                    </div>
                    <div>
                        <label class="mb-1 block text-[11px] text-textSecondary">Primary keyword</label>
                        <input type="text" name="chain_items[{{ $index }}][primary_keyword]" value="{{ old($prefix . 'primary_keyword', data_get($item, 'primary_keyword')) }}" class="w-full rounded border border-border bg-background px-2 py-1.5 text-sm" maxlength="255">
                    </div>
                    <div>
                        <label class="mb-1 block text-[11px] text-textSecondary">Search intent</label>
                        <input type="text" name="chain_items[{{ $index }}][search_intent]" value="{{ old($prefix . 'search_intent', data_get($item, 'search_intent')) }}" class="w-full rounded border border-border bg-background px-2 py-1.5 text-sm" maxlength="255">
                    </div>
                    <div>
                        <label class="mb-1 block text-[11px] text-textSecondary">Funnel stage</label>
                        <input type="text" name="chain_items[{{ $index }}][funnel_stage]" value="{{ old($prefix . 'funnel_stage', data_get($item, 'funnel_stage')) }}" class="w-full rounded border border-border bg-background px-2 py-1.5 text-sm" maxlength="64">
                    </div>
                    <div>
                        <label class="mb-1 block text-[11px] text-textSecondary">Target audience</label>
                        <input type="text" name="chain_items[{{ $index }}][target_audience]" value="{{ old($prefix . 'target_audience', data_get($item, 'target_audience')) }}" class="w-full rounded border border-border bg-background px-2 py-1.5 text-sm" maxlength="255">
                    </div>
                </div>

                <div class="mt-2 grid gap-2">
                    <div>
                        <label class="mb-1 block text-[11px] text-textSecondary">Secondary keywords</label>
                        <textarea name="chain_items[{{ $index }}][secondary_keywords]" rows="2" class="w-full rounded border border-border bg-background px-2 py-1.5 text-sm">{{ $secondary }}</textarea>
                    </div>
                    <div>
                        <label class="mb-1 block text-[11px] text-textSecondary">Angle</label>
                        <textarea name="chain_items[{{ $index }}][angle]" rows="2" class="w-full rounded border border-border bg-background px-2 py-1.5 text-sm">{{ old($prefix . 'angle', data_get($item, 'angle')) }}</textarea>
                    </div>
                    <div>
                        <label class="mb-1 block text-[11px] text-textSecondary">Key points</label>
                        <textarea name="chain_items[{{ $index }}][key_points]" rows="2" class="w-full rounded border border-border bg-background px-2 py-1.5 text-sm">{{ $keyPoints }}</textarea>
                    </div>
                    <div>
                        <label class="mb-1 block text-[11px] text-textSecondary">CTA</label>
                        <textarea name="chain_items[{{ $index }}][cta]" rows="2" class="w-full rounded border border-border bg-background px-2 py-1.5 text-sm">{{ old($prefix . 'cta', data_get($item, 'cta')) }}</textarea>
                    </div>
                    <div>
                        <label class="mb-1 block text-[11px] text-textSecondary">Suggested internal links</label>
                        <textarea name="chain_items[{{ $index }}][suggested_internal_links]" rows="2" class="w-full rounded border border-border bg-background px-2 py-1.5 text-sm">{{ $links }}</textarea>
                    </div>
                </div>
            </div>
        @endforeach
    </div>

    @error('chain_items')<p class="mt-2 text-xs text-rose-700">{{ $message }}</p>@enderror
</div>
