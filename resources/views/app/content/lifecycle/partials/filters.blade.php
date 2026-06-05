<form method="GET" class="space-y-4">
    <details class="rounded-lg bg-surfaceSubtle p-4">
        <summary class="flex cursor-pointer list-none items-center justify-between gap-4 text-sm font-medium text-textPrimary [&::-webkit-details-marker]:hidden">
            <span class="inline-flex items-center gap-2">
                <i data-lucide="sliders-horizontal" class="h-4 w-4 text-textSecondary"></i>
                Filters
                @php
                    $activeFilterCount = collect($filters)->filter(fn($v) => filled($v))->count();
                @endphp
                @if ($activeFilterCount > 0)
                    <span class="rounded-full bg-primary px-2 py-0.5 text-[11px] font-semibold text-textInverse">{{ $activeFilterCount }} active</span>
                @endif
            </span>
            <i data-lucide="chevron-down" class="h-4 w-4 text-textSecondary"></i>
        </summary>
        <div class="mt-4 grid gap-4 lg:grid-cols-4">
            {{-- Search --}}
            <div class="lg:col-span-2">
                <label class="mb-1 block text-xs text-textSecondary" for="lifecycle-filter-search">Search</label>
                <div class="relative">
                    <i data-lucide="search" class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-textFaint"></i>
                    <input
                        id="lifecycle-filter-search"
                        type="text"
                        name="q"
                        value="{{ $filters['q'] }}"
                        class="pl-search w-full"
                        placeholder="Search by title or keyword..."
                    >
                </div>
            </div>

            {{-- Stage --}}
            <div>
                <label class="mb-1 block text-xs text-textSecondary" for="lifecycle-filter-stage">Stage</label>
                <select id="lifecycle-filter-stage" class="pl-select w-full bg-surface" name="stage">
                    <option value="">All stages</option>
                    @foreach ($stages as $stage)
                        <option value="{{ $stage->value }}" @selected($filters['stage'] === $stage->value)>{{ $stage->label() }}</option>
                    @endforeach
                </select>
            </div>

            {{-- Site --}}
            <div>
                <label class="mb-1 block text-xs text-textSecondary" for="lifecycle-filter-site">Site</label>
                <select id="lifecycle-filter-site" class="pl-select w-full bg-surface" name="site">
                    <option value="">All sites</option>
                    @foreach ($sites as $site)
                        <option value="{{ $site->id }}" @selected($filters['site'] === (string) $site->id)>{{ $site->name }}</option>
                    @endforeach
                </select>
            </div>

            {{-- Locale --}}
            <div>
                <label class="mb-1 block text-xs text-textSecondary" for="lifecycle-filter-locale">Locale</label>
                <select id="lifecycle-filter-locale" class="pl-select w-full bg-surface" name="locale">
                    <option value="">All locales</option>
                    @foreach ($localeOptions as $locale)
                        <option value="{{ $locale->value }}" @selected($filters['locale'] === $locale->value)>{{ strtoupper($locale->value) }} - {{ $locale->englishLabel() }}</option>
                    @endforeach
                </select>
            </div>

            {{-- Series --}}
            <div>
                <label class="mb-1 block text-xs text-textSecondary" for="lifecycle-filter-series">Chain</label>
                <select id="lifecycle-filter-series" class="pl-select w-full bg-surface" name="series">
                    <option value="">All chains</option>
                    @foreach ($seriesList as $series)
                        <option value="{{ $series->id }}" @selected($filters['series'] === (string) $series->id)>{{ $series->name }}</option>
                    @endforeach
                </select>
            </div>

            {{-- Automation --}}
            <div>
                <label class="mb-1 block text-xs text-textSecondary" for="lifecycle-filter-automation">Automation</label>
                <select id="lifecycle-filter-automation" class="pl-select w-full bg-surface" name="automation">
                    <option value="">All automations</option>
                    @foreach ($automations as $automation)
                        <option value="{{ $automation->id }}" @selected($filters['automation'] === (string) $automation->id)>{{ $automation->name }}</option>
                    @endforeach
                </select>
            </div>

            {{-- Assigned To --}}
            <div>
                <label class="mb-1 block text-xs text-textSecondary" for="lifecycle-filter-assigned">Assigned To</label>
                <select id="lifecycle-filter-assigned" class="pl-select w-full bg-surface" name="assigned">
                    <option value="">Anyone</option>
                    @foreach ($users as $user)
                        <option value="{{ $user->id }}" @selected($filters['assigned'] === (string) $user->id)>{{ $user->name }}</option>
                    @endforeach
                </select>
            </div>

            {{-- Reviewer --}}
            <div>
                <label class="mb-1 block text-xs text-textSecondary" for="lifecycle-filter-reviewer">Reviewer</label>
                <select id="lifecycle-filter-reviewer" class="pl-select w-full bg-surface" name="reviewer">
                    <option value="">Anyone</option>
                    @foreach ($users as $user)
                        <option value="{{ $user->id }}" @selected($filters['reviewer'] === (string) $user->id)>{{ $user->name }}</option>
                    @endforeach
                </select>
            </div>

            {{-- Due Date Filter --}}
            <div>
                <label class="mb-1 block text-xs text-textSecondary" for="lifecycle-filter-due">Due Date</label>
                <select id="lifecycle-filter-due" class="pl-select w-full bg-surface" name="due_filter">
                    <option value="">All</option>
                    <option value="overdue" @selected($filters['due_filter'] === 'overdue')>Overdue</option>
                    <option value="due_soon" @selected($filters['due_filter'] === 'due_soon')>Due within 7 days</option>
                    <option value="no_due_date" @selected($filters['due_filter'] === 'no_due_date')>No due date</option>
                </select>
            </div>

            {{-- Publish Status --}}
            <div>
                <label class="mb-1 block text-xs text-textSecondary" for="lifecycle-filter-publish">Publish Status</label>
                <select id="lifecycle-filter-publish" class="pl-select w-full bg-surface" name="publish_status">
                    <option value="">All</option>
                    @foreach (['draft', 'scheduled', 'publishing', 'published', 'failed'] as $status)
                        <option value="{{ $status }}" @selected($filters['publish_status'] === $status)>{{ ucfirst($status) }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label class="mb-1 block text-xs text-textSecondary" for="lifecycle-filter-health">Health Score</label>
                <select id="lifecycle-filter-health" class="pl-select w-full bg-surface" name="health_range">
                    <option value="">All health bands</option>
                    <option value="low" @selected(($filters['health_range'] ?? '') === 'low')>0-39</option>
                    <option value="medium" @selected(($filters['health_range'] ?? '') === 'medium')>40-69</option>
                    <option value="high" @selected(($filters['health_range'] ?? '') === 'high')>70-100</option>
                </select>
            </div>

            <div>
                <label class="mb-1 block text-xs text-textSecondary" for="lifecycle-filter-ai-visibility">AI Visibility</label>
                <select id="lifecycle-filter-ai-visibility" class="pl-select w-full bg-surface" name="ai_visibility_range">
                    <option value="">All visibility bands</option>
                    <option value="low" @selected(($filters['ai_visibility_range'] ?? '') === 'low')>0-39</option>
                    <option value="medium" @selected(($filters['ai_visibility_range'] ?? '') === 'medium')>40-69</option>
                    <option value="high" @selected(($filters['ai_visibility_range'] ?? '') === 'high')>70-100</option>
                </select>
            </div>

            <div>
                <label class="mb-1 block text-xs text-textSecondary" for="lifecycle-filter-decay">Decay Risk</label>
                <select id="lifecycle-filter-decay" class="pl-select w-full bg-surface" name="decay_risk">
                    <option value="">All risk levels</option>
                    <option value="low" @selected(($filters['decay_risk'] ?? '') === 'low')>Low</option>
                    <option value="medium" @selected(($filters['decay_risk'] ?? '') === 'medium')>Medium</option>
                    <option value="high" @selected(($filters['decay_risk'] ?? '') === 'high')>High</option>
                    <option value="critical" @selected(($filters['decay_risk'] ?? '') === 'critical')>Critical</option>
                </select>
            </div>

            <div>
                <label class="mb-1 block text-xs text-textSecondary" for="lifecycle-filter-semantic">Semantic Coverage</label>
                <select id="lifecycle-filter-semantic" class="pl-select w-full bg-surface" name="semantic_coverage">
                    <option value="">All coverage</option>
                    <option value="weak" @selected(($filters['semantic_coverage'] ?? '') === 'weak')>Weak</option>
                    <option value="strong" @selected(($filters['semantic_coverage'] ?? '') === 'strong')>Strong</option>
                </select>
            </div>

            <div>
                <label class="mb-1 block text-xs text-textSecondary" for="lifecycle-filter-ai-optimized">AI Optimized</label>
                <select id="lifecycle-filter-ai-optimized" class="pl-select w-full bg-surface" name="ai_optimized">
                    <option value="">Any</option>
                    <option value="yes" @selected(($filters['ai_optimized'] ?? '') === 'yes')>Yes</option>
                    <option value="no" @selected(($filters['ai_optimized'] ?? '') === 'no')>No</option>
                </select>
            </div>

            <div class="lg:col-span-3">
                <label class="mb-1 block text-xs text-textSecondary">Operational flags</label>
                <div class="flex flex-wrap gap-2">
                    <label class="inline-flex items-center gap-2 rounded-md border border-border bg-surface px-3 py-2 text-sm text-textPrimary">
                        <input type="checkbox" name="missing_answer_blocks" value="1" @checked($filters['missing_answer_blocks'] ?? false)>
                        <span>Missing answer blocks</span>
                    </label>
                    <label class="inline-flex items-center gap-2 rounded-md border border-border bg-surface px-3 py-2 text-sm text-textPrimary">
                        <input type="checkbox" name="stale_content" value="1" @checked($filters['stale_content'] ?? false)>
                        <span>Stale content</span>
                    </label>
                    <label class="inline-flex items-center gap-2 rounded-md border border-border bg-surface px-3 py-2 text-sm text-textPrimary">
                        <input type="checkbox" name="weak_internal_links" value="1" @checked($filters['weak_internal_links'] ?? false)>
                        <span>Weak internal links</span>
                    </label>
                    <label class="inline-flex items-center gap-2 rounded-md border border-border bg-surface px-3 py-2 text-sm text-textPrimary">
                        <input type="checkbox" name="translation_incomplete" value="1" @checked($filters['translation_incomplete'] ?? false)>
                        <span>Translation incomplete</span>
                    </label>
                    <label class="inline-flex items-center gap-2 rounded-md border border-border bg-surface px-3 py-2 text-sm text-textPrimary">
                        <input type="checkbox" name="needs_optimization" value="1" @checked($filters['needs_optimization'] ?? false)>
                        <span>Needs optimization</span>
                    </label>
                </div>
            </div>
        </div>
        <div class="mt-4 flex flex-wrap items-center justify-end gap-2">
            <a href="{{ route('app.content.lifecycle.index') }}" class="pl-btn-ghost">Reset</a>
            <button class="pl-btn-secondary">Apply Filters</button>
        </div>
    </details>
</form>
