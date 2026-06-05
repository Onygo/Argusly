<div class="grid gap-6 xl:grid-cols-[minmax(0,1.45fr),minmax(320px,0.8fr)]">
    <div class="space-y-4">
        <x-workspace-intelligence.card
            title="Buyer personas"
            description="Use approved personas as reusable planning context. Expand a card to inspect goals, frustrations, jobs to be done, and content needs."
            icon="users"
        >
            <div class="space-y-3">
                @forelse (($hub['personas']['cards'] ?? []) as $persona)
                    <details class="group rounded-lg border border-border bg-background p-4">
                        <summary class="flex cursor-pointer list-none items-start justify-between gap-3">
                            <div class="min-w-0">
                                <div class="flex flex-wrap items-center gap-2">
                                    <h3 class="text-base font-semibold text-textPrimary">{{ $persona['name'] }}</h3>
                                    <x-workspace-intelligence.status-badge :label="$persona['status']['label']" :tone="$persona['status']['tone']" :icon="$persona['status']['icon']" />
                                </div>
                                <p class="mt-1 text-sm text-textSecondary">{{ $persona['role'] }}</p>
                                @if (($persona['summary'] ?? '') !== '')
                                    <p class="mt-3 max-w-3xl text-sm leading-6 text-textSecondary">{{ $persona['summary'] }}</p>
                                @endif
                            </div>
                            <i data-lucide="chevron-down" class="mt-1 h-4 w-4 shrink-0 text-textMuted transition group-open:rotate-180"></i>
                        </summary>

                        <div class="mt-4 border-t border-border pt-4">
                            <x-workspace-intelligence.list :groups="$persona['sections'] ?? []" empty="No persona details captured yet." />
                        </div>
                    </details>
                @empty
                    <div class="rounded-lg border border-dashed border-border bg-background px-4 py-10 text-sm text-textMuted">
                        No approved personas yet. Start a persona enrichment run or add one manually.
                    </div>
                @endforelse
            </div>
        </x-workspace-intelligence.card>
    </div>

    <div class="space-y-6">
        <x-workspace-intelligence.card
            eyebrow="Actions"
            title="Grow persona coverage"
            description="Add a manual persona in Brand, or rerun enrichment from clearer source material."
            icon="user-plus"
        >
            <div class="flex flex-wrap gap-2">
                <a href="{{ route('app.brand.personas') }}" class="inline-flex items-center justify-center rounded-md border border-border px-3 py-2 text-sm font-medium text-textPrimary transition hover:bg-surfaceSubtle">
                    + Add persona
                </a>
                <a href="{{ route('app.brand.personas') }}" class="inline-flex items-center justify-center rounded-md border border-border px-3 py-2 text-sm font-medium text-textPrimary transition hover:bg-surfaceSubtle">
                    Edit approved personas
                </a>
            </div>

            <form method="POST" action="{{ route('app.workspace-intelligence.personas.store') }}" class="mt-4 space-y-3">
                @csrf
                <div>
                    <label class="text-sm text-textSecondary" for="wi_persona_source_type">Source type</label>
                    <select id="wi_persona_source_type" name="source_type" class="mt-1 w-full rounded-md border border-border bg-background px-3 py-2 text-sm">
                        <option value="website_url">Website URL</option>
                        <option value="company_name_and_industry">Company name and industry</option>
                        <option value="manual_text">Manual text</option>
                    </select>
                </div>
                <div>
                    <label class="text-sm text-textSecondary" for="wi_persona_website_url">Website URL</label>
                    <input id="wi_persona_website_url" name="website_url" value="{{ old('website_url') }}" class="mt-1 w-full rounded-md border border-border bg-background px-3 py-2 text-sm" placeholder="https://example.com">
                </div>
                <div class="grid gap-3 md:grid-cols-2">
                    <div>
                        <label class="text-sm text-textSecondary" for="wi_persona_company_name">Company name</label>
                        <input id="wi_persona_company_name" name="company_name" value="{{ old('company_name', $organization->name) }}" class="mt-1 w-full rounded-md border border-border bg-background px-3 py-2 text-sm" placeholder="Company name">
                    </div>
                    <div>
                        <label class="text-sm text-textSecondary" for="wi_persona_industry">Industry</label>
                        <input id="wi_persona_industry" name="industry" value="{{ old('industry', $organization->industry) }}" class="mt-1 w-full rounded-md border border-border bg-background px-3 py-2 text-sm" placeholder="Industry">
                    </div>
                </div>
                <div>
                    <label class="text-sm text-textSecondary" for="wi_persona_manual_text">Manual notes</label>
                    <textarea id="wi_persona_manual_text" name="manual_text" rows="6" class="mt-1 w-full rounded-md border border-border bg-background px-3 py-2 text-sm" placeholder="Describe roles, pains, objections, jobs to be done, and content needs.">{{ old('manual_text') }}</textarea>
                </div>
                <button class="inline-flex items-center justify-center rounded-md bg-primary px-4 py-2 text-sm font-medium text-textInverse">
                    Run enrichment
                </button>
            </form>
        </x-workspace-intelligence.card>
    </div>
</div>
