@php
    $chainSettings = (array) ($chainSettings ?? []);
    $chainValue = function (string $key, mixed $default = '') use ($chainSettings): mixed {
        return old('chain_' . $key, $chainSettings[$key] ?? $default);
    };
    $selectedTypes = collect(old('chain_item_types', $chainSettings['item_types'] ?? []))
        ->map(fn ($value) => (string) $value)
        ->all();
    $chainSettingsVisible = (bool) ($visible ?? false);
    $chainItemTypeOptions = [
        'cornerstone_article' => 'Cornerstone article',
        'supporting_blog' => 'Supporting blog',
        'comparison_article' => 'Comparison article',
        'faq_article' => 'FAQ article',
        'linkedin_post' => 'LinkedIn post',
        'landing_page' => 'Landing page',
        'newsletter' => 'Newsletter',
        'source_analysis' => 'Source analysis',
    ];
@endphp

<div class="source-chain-settings {{ $chainSettingsVisible ? '' : 'hidden' }} rounded-md border border-border bg-surface px-3 py-3">
    <div class="flex flex-wrap items-start justify-between gap-2">
        <div>
            <p class="text-sm font-semibold text-textPrimary">Chain settings</p>
            <p class="mt-1 text-xs text-textSecondary">Use source analysis as context. Manual fields here override generated suggestions.</p>
        </div>
        <span class="rounded-full border border-sky-200 bg-sky-50 px-2 py-1 text-[11px] font-medium text-sky-700">Editable before creation</span>
    </div>

    <div class="mt-3 grid gap-3 md:grid-cols-2">
        <div>
            <label class="mb-1 block text-xs text-textSecondary">Chain title</label>
            <input type="text" name="chain_title" value="{{ $chainValue('title') }}" class="w-full rounded border border-border bg-background px-3 py-2 text-sm" maxlength="255">
        </div>
        <div>
            <label class="mb-1 block text-xs text-textSecondary">Main topic</label>
            <input type="text" name="chain_main_topic" value="{{ $chainValue('main_topic') }}" class="w-full rounded border border-border bg-background px-3 py-2 text-sm" maxlength="255">
        </div>
        <div>
            <label class="mb-1 block text-xs text-textSecondary">Primary keyword</label>
            <input type="text" name="chain_primary_keyword" value="{{ $chainValue('primary_keyword') }}" class="w-full rounded border border-border bg-background px-3 py-2 text-sm" maxlength="255">
        </div>
        <div>
            <label class="mb-1 block text-xs text-textSecondary">Secondary keywords</label>
            <textarea name="chain_secondary_keywords" rows="2" class="w-full rounded border border-border bg-background px-3 py-2 text-sm">{{ is_array($chainValue('secondary_keywords')) ? implode("\n", $chainValue('secondary_keywords')) : $chainValue('secondary_keywords') }}</textarea>
        </div>
        <div>
            <label class="mb-1 block text-xs text-textSecondary">Target audience</label>
            <input type="text" name="chain_target_audience" value="{{ $chainValue('target_audience') }}" class="w-full rounded border border-border bg-background px-3 py-2 text-sm" maxlength="255">
        </div>
        <div>
            <label class="mb-1 block text-xs text-textSecondary">Funnel stage</label>
            <select name="chain_funnel_stage" class="pl-select bg-background">
                <option value="">Select stage</option>
                @foreach(['awareness', 'consideration', 'decision', 'retention'] as $stage)
                    <option value="{{ $stage }}" @selected($chainValue('funnel_stage') === $stage)>{{ ucfirst($stage) }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="mb-1 block text-xs text-textSecondary">Search intent</label>
            <input type="text" name="chain_search_intent" value="{{ $chainValue('search_intent') }}" class="w-full rounded border border-border bg-background px-3 py-2 text-sm" maxlength="255">
        </div>
        <div>
            <label class="mb-1 block text-xs text-textSecondary">Tone of voice</label>
            <input type="text" name="chain_tone_of_voice" value="{{ $chainValue('tone_of_voice') }}" class="w-full rounded border border-border bg-background px-3 py-2 text-sm" maxlength="255">
        </div>
        <div>
            <label class="mb-1 block text-xs text-textSecondary">Number of chain items</label>
            <input type="number" name="chain_items_count" min="1" max="20" value="{{ $chainValue('items_count', 5) }}" class="w-full rounded border border-border bg-background px-3 py-2 text-sm">
        </div>
        <div>
            <label class="mb-1 block text-xs text-textSecondary">Language</label>
            <select name="chain_language" class="pl-select bg-background">
                <option value="">Use detected language</option>
                <option value="en" @selected($chainValue('language') === 'en')>English (EN)</option>
                <option value="nl" @selected($chainValue('language') === 'nl')>Dutch (NL)</option>
            </select>
        </div>
    </div>

    <div class="mt-3">
        <label class="mb-1 block text-xs text-textSecondary">Preferred chain item types</label>
        <div class="grid gap-2 sm:grid-cols-2">
            @foreach($chainItemTypeOptions as $value => $label)
                <label class="flex items-center gap-2 rounded border border-border bg-background px-3 py-2 text-xs text-textPrimary">
                    <input type="checkbox" name="chain_item_types[]" value="{{ $value }}" @checked(in_array($value, $selectedTypes, true))>
                    <span>{{ $label }}</span>
                </label>
            @endforeach
        </div>
    </div>

    <div class="mt-3 grid gap-3 md:grid-cols-2">
        <div>
            <label class="mb-1 block text-xs text-textSecondary">Chain goal</label>
            <textarea name="chain_goal" rows="3" class="w-full rounded border border-border bg-background px-3 py-2 text-sm">{{ $chainValue('goal') }}</textarea>
        </div>
        <div>
            <label class="mb-1 block text-xs text-textSecondary">Unique angle</label>
            <textarea name="chain_unique_angle" rows="3" class="w-full rounded border border-border bg-background px-3 py-2 text-sm">{{ $chainValue('unique_angle') }}</textarea>
        </div>
        <div>
            <label class="mb-1 block text-xs text-textSecondary">CTA</label>
            <textarea name="chain_cta" rows="2" class="w-full rounded border border-border bg-background px-3 py-2 text-sm">{{ $chainValue('cta') }}</textarea>
        </div>
        <div>
            <label class="mb-1 block text-xs text-textSecondary">Internal link targets</label>
            <textarea name="chain_internal_link_targets" rows="2" class="w-full rounded border border-border bg-background px-3 py-2 text-sm">{{ is_array($chainValue('internal_link_targets')) ? implode("\n", $chainValue('internal_link_targets')) : $chainValue('internal_link_targets') }}</textarea>
        </div>
        <div>
            <label class="mb-1 block text-xs text-textSecondary">Destination site</label>
            <input type="text" name="chain_destination_site" value="{{ $chainValue('destination_site') }}" class="w-full rounded border border-border bg-background px-3 py-2 text-sm" maxlength="255">
        </div>
        <div>
            <label class="mb-1 block text-xs text-textSecondary">CMS destination</label>
            <input type="text" name="chain_cms_destination" value="{{ $chainValue('cms_destination') }}" class="w-full rounded border border-border bg-background px-3 py-2 text-sm" maxlength="255">
            <p class="mt-1 text-xs text-textSecondary">CMS destination is required before publishing, but not before saving as chain.</p>
        </div>
    </div>

    <div class="mt-3">
        <label class="mb-1 block text-xs text-textSecondary">Notes</label>
        <textarea name="chain_notes" rows="3" class="w-full rounded border border-border bg-background px-3 py-2 text-sm">{{ $chainValue('notes') }}</textarea>
    </div>
</div>
