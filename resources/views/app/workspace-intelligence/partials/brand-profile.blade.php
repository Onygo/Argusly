<div class="grid gap-6 xl:grid-cols-[minmax(0,1.45fr),minmax(320px,0.8fr)]">
    <div class="space-y-6">
        <div class="grid gap-4 md:grid-cols-2">
            @foreach (($hub['brand_profile']['cards'] ?? []) as $card)
                <x-workspace-intelligence.card :title="$card['title'] ?? null" :icon="$card['icon'] ?? null">
                    @if (($card['text'] ?? '') !== '')
                        <p class="text-sm leading-6 text-textSecondary">{{ $card['text'] }}</p>
                    @endif

                    @if (! empty($card['items'] ?? []))
                        <div @class(['mt-3' => ($card['text'] ?? '') !== ''])>
                            <x-workspace-intelligence.list :items="$card['items'] ?? []" :empty="$card['empty'] ?? 'No details yet.'" />
                        </div>
                    @elseif (! empty($card['groups'] ?? []))
                        <div @class(['mt-3' => ($card['text'] ?? '') !== ''])>
                            <x-workspace-intelligence.list :groups="$card['groups'] ?? []" :empty="$card['empty'] ?? 'No details yet.'" />
                        </div>
                    @elseif (($card['text'] ?? '') === '')
                        <p class="text-sm leading-6 text-textMuted">{{ $card['empty'] ?? 'No details yet.' }}</p>
                    @endif
                </x-workspace-intelligence.card>
            @endforeach
        </div>
    </div>

    <div class="space-y-6" id="workspace-intelligence-actions">
        <x-workspace-intelligence.card
            eyebrow="AI Value"
            title="This profile is used to generate"
            description="Approved context is reused in generation, optimization, and discovery workflows."
            icon="sparkles"
        >
            <div class="grid gap-3">
                @foreach (($hub['usage'] ?? []) as $usage)
                    <div class="rounded-lg border border-border bg-background px-4 py-3">
                        <div class="flex items-center gap-3">
                            <span class="inline-flex h-9 w-9 items-center justify-center rounded-full bg-primarySoftBg text-primary">
                                <i data-lucide="{{ $usage['icon'] ?? 'sparkles' }}" class="h-4 w-4"></i>
                            </span>
                            <div>
                                <p class="text-sm font-medium text-textPrimary">{{ $usage['label'] ?? '' }}</p>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        </x-workspace-intelligence.card>

        <x-workspace-intelligence.card
            eyebrow="Actions"
            title="Refine brand context"
            description="Use the existing company profile flow for direct edits, or rerun enrichment with better source material."
            icon="pen-tool"
        >
            <div class="flex flex-wrap gap-2">
                <a href="{{ route('app.brand.company-profile') }}" class="inline-flex items-center justify-center rounded-md border border-border px-3 py-2 text-sm font-medium text-textPrimary transition hover:bg-surfaceSubtle">
                    Edit profile
                </a>
                <a href="{{ route('app.brand.voices') }}" class="inline-flex items-center justify-center rounded-md border border-border px-3 py-2 text-sm font-medium text-textPrimary transition hover:bg-surfaceSubtle">
                    Edit voices
                </a>
            </div>

            <form method="POST" action="{{ route('app.workspace-intelligence.organization.store') }}" class="mt-4 space-y-3">
                @csrf
                <div>
                    <label class="text-sm text-textSecondary" for="wi_org_source_type">Source type</label>
                    <select id="wi_org_source_type" name="source_type" class="mt-1 w-full rounded-md border border-border bg-background px-3 py-2 text-sm">
                        <option value="website_url">Website URL</option>
                        <option value="company_name_and_industry">Company name and industry</option>
                        <option value="manual_text">Manual text</option>
                        <option value="linkedin_reference_url">LinkedIn reference URL</option>
                    </select>
                </div>
                <div>
                    <label class="text-sm text-textSecondary" for="wi_org_website_url">Website URL</label>
                    <input id="wi_org_website_url" name="website_url" value="{{ old('website_url') }}" class="mt-1 w-full rounded-md border border-border bg-background px-3 py-2 text-sm" placeholder="https://example.com">
                </div>
                <div class="grid gap-3 md:grid-cols-2">
                    <div>
                        <label class="text-sm text-textSecondary" for="wi_org_company_name">Company name</label>
                        <input id="wi_org_company_name" name="company_name" value="{{ old('company_name', $organization->name) }}" class="mt-1 w-full rounded-md border border-border bg-background px-3 py-2 text-sm" placeholder="Company name">
                    </div>
                    <div>
                        <label class="text-sm text-textSecondary" for="wi_org_industry">Industry</label>
                        <input id="wi_org_industry" name="industry" value="{{ old('industry', $organization->industry) }}" class="mt-1 w-full rounded-md border border-border bg-background px-3 py-2 text-sm" placeholder="Industry">
                    </div>
                </div>
                <div>
                    <label class="text-sm text-textSecondary" for="wi_org_manual_text">Manual notes</label>
                    <textarea id="wi_org_manual_text" name="manual_text" rows="5" class="mt-1 w-full rounded-md border border-border bg-background px-3 py-2 text-sm" placeholder="Paste positioning, audience notes, product angles, or SEO priorities.">{{ old('manual_text') }}</textarea>
                </div>
                <div>
                    <label class="text-sm text-textSecondary" for="wi_org_linkedin_reference_url">LinkedIn reference URL</label>
                    <input id="wi_org_linkedin_reference_url" name="linkedin_reference_url" value="{{ old('linkedin_reference_url') }}" class="mt-1 w-full rounded-md border border-border bg-background px-3 py-2 text-sm" placeholder="https://www.linkedin.com/company/...">
                </div>
                <button class="inline-flex items-center justify-center rounded-md bg-primary px-4 py-2 text-sm font-medium text-textInverse">
                    Run enrichment
                </button>
            </form>
        </x-workspace-intelligence.card>
    </div>
</div>
