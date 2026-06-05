@php
    $indexation = $indexationDiagnostics ?? [];
    $issueRows = collect((array) data_get($indexation, 'issues_json', []))->take(6);
    $canonicalUrl = (string) data_get($indexation, 'canonical_url', '');
    $googleCanonical = (string) data_get($indexation, 'google_selected_canonical', '');
    $sitemapStatus = (string) data_get($indexation, 'sitemap_status', 'unknown');
    $indexed = data_get($indexation, 'indexed');
    $canonicalAccepted = data_get($indexation, 'canonical_accepted');
    $healthScore = (int) data_get($indexation, 'health_score', 0);
    $healthTone = $healthScore >= 70
        ? 'border-emerald-200 bg-emerald-50 text-emerald-800'
        : ($healthScore >= 40 ? 'border-amber-200 bg-amber-50 text-amber-800' : 'border-rose-200 bg-rose-50 text-rose-800');
@endphp

<section class="mb-5 rounded-2xl border border-border/80 bg-white p-5">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <div class="text-xs font-medium uppercase tracking-[0.18em] text-textSecondary">Zone 3A</div>
            <h3 class="mt-1 text-lg font-semibold text-textPrimary">Search Visibility Diagnostics</h3>
            <p class="mt-1 text-sm text-textSecondary">Canonical, hreflang, sitemap, and indexation health for this public content route.</p>
        </div>
        <span class="inline-flex rounded-full border px-3 py-1 text-xs font-medium {{ $healthTone }}">
            Search health {{ $healthScore }}
        </span>
    </div>

    <div class="mt-4 grid gap-3 md:grid-cols-4">
        <div class="rounded-2xl bg-slate-50 p-4">
            <div class="text-xs uppercase tracking-wide text-textSecondary">Indexed</div>
            <div class="mt-2 text-sm font-medium text-textPrimary">{{ $indexed === null ? 'Unknown' : ($indexed ? 'Yes' : 'No') }}</div>
        </div>
        <div class="rounded-2xl bg-slate-50 p-4">
            <div class="text-xs uppercase tracking-wide text-textSecondary">Canonical</div>
            <div class="mt-2 text-sm font-medium text-textPrimary">{{ $canonicalAccepted === null ? 'Pending' : ($canonicalAccepted ? 'Accepted' : 'Mismatch') }}</div>
        </div>
        <div class="rounded-2xl bg-slate-50 p-4">
            <div class="text-xs uppercase tracking-wide text-textSecondary">Sitemap</div>
            <div class="mt-2 text-sm font-medium text-textPrimary">{{ str_replace('_', ' ', ucfirst($sitemapStatus)) }}</div>
        </div>
        <div class="rounded-2xl bg-slate-50 p-4">
            <div class="text-xs uppercase tracking-wide text-textSecondary">Published locales</div>
            <div class="mt-2 text-sm font-medium text-textPrimary">{{ collect((array) data_get($indexation, 'published_locales', []))->map(fn ($locale) => strtoupper((string) $locale))->implode(' · ') ?: 'n/a' }}</div>
        </div>
    </div>

    <dl class="mt-5 grid gap-4 text-sm md:grid-cols-2">
        <div class="rounded-2xl bg-slate-50 p-4">
            <dt class="text-xs uppercase tracking-wide text-textSecondary">Canonical URL</dt>
            <dd class="mt-2 break-all text-textPrimary">{{ $canonicalUrl !== '' ? $canonicalUrl : 'Not resolved' }}</dd>
        </div>
        <div class="rounded-2xl bg-slate-50 p-4">
            <dt class="text-xs uppercase tracking-wide text-textSecondary">Google selected canonical</dt>
            <dd class="mt-2 break-all text-textPrimary">{{ $googleCanonical !== '' ? $googleCanonical : 'Not synced yet' }}</dd>
        </div>
    </dl>

    <div class="mt-5 grid gap-5 xl:grid-cols-[minmax(0,1fr)_320px]">
        <div class="rounded-2xl bg-slate-50 p-4">
            <div class="text-sm font-medium text-textPrimary">Hreflang matrix</div>
            <div class="mt-3 space-y-2 text-sm">
                @forelse ((array) data_get($indexation, 'hreflang_urls', []) as $hreflang => $href)
                    <div class="flex items-start justify-between gap-3 rounded-xl border border-border/70 bg-white px-3 py-2">
                        <span class="font-medium text-textPrimary">{{ strtoupper((string) $hreflang) }}</span>
                        <span class="break-all text-right text-textSecondary">{{ $href }}</span>
                    </div>
                @empty
                    <div class="text-textSecondary">No alternate locale URLs are available yet.</div>
                @endforelse
            </div>
        </div>

        <div class="rounded-2xl bg-slate-50 p-4">
            <div class="text-sm font-medium text-textPrimary">Redirect chain</div>
            <div class="mt-3 space-y-2 text-sm">
                @forelse ((array) data_get($indexation, 'redirect_chain', []) as $redirect)
                    <div class="rounded-xl border border-border/70 bg-white px-3 py-2">
                        <div class="font-medium text-textPrimary">{{ $redirect['source_path'] }}</div>
                        <div class="mt-1 text-textSecondary">→ {{ $redirect['target_path'] }}</div>
                        <div class="mt-1 text-xs text-textSecondary">{{ $redirect['redirect_kind'] }}</div>
                    </div>
                @empty
                    <div class="text-textSecondary">No redirect chain detected on the canonical route.</div>
                @endforelse
            </div>
        </div>
    </div>

    <div class="mt-5 rounded-2xl bg-slate-50 p-4">
        <div class="text-sm font-medium text-textPrimary">Operational issues</div>
        <div class="mt-3 space-y-2 text-sm">
            @forelse ($issueRows as $issue)
                <div class="rounded-xl border border-border/70 bg-white px-3 py-2">
                    <div class="font-medium text-textPrimary">{{ str_replace('_', ' ', ucfirst((string) ($issue['code'] ?? 'issue'))) }}</div>
                    <div class="mt-1 text-textSecondary">{{ $issue['message'] ?? 'Issue detected.' }}</div>
                </div>
            @empty
                <div class="text-textSecondary">No canonical or indexation conflicts are currently stored for this content item.</div>
            @endforelse
        </div>
    </div>
</section>
