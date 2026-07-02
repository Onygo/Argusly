@extends('layouts.app', ['title' => 'Site Analytics'])

@section('pageHeader')
    <x-page-header title="Analytics">
        <x-slot:description>Configure tracking, verify the domain, and review recent site performance.</x-slot:description>
    </x-page-header>
@endsection

@section('primaryActions')
    <a href="{{ route('app.insights.index') }}" class="pl-btn-secondary">All sites</a>
    <a href="{{ route('app.sites.show', $site) }}" class="pl-btn-secondary">Site setup</a>
@endsection

@section('content')
    <div class="space-y-6">
        <x-app.insights-header
            :site="$site"
            title="Analytics"
            description="Configure tracking, verify the domain, and review recent site performance."
            active="analytics"
            :show-heading="false"
        />

        @if (session('status'))
            <x-alert>{{ session('status') }}</x-alert>
        @endif

        @if (session('error'))
            <div class="rounded-lg border border-rose-500/30 bg-rose-500/10 px-4 py-3 text-sm text-rose-800">
                <p>{{ session('error') }}</p>
                <div class="mt-3 flex flex-wrap gap-2">
                    @if (session('analytics_error_action') === 'retry_verification' && $analyticsSite?->is_enabled && ! $analyticsSite?->verified_at)
                        <form method="POST" action="{{ route('app.sites.analytics.verify', $site) }}">
                            @csrf
                            <button class="rounded border border-rose-300 bg-white/70 px-3 py-1.5 text-xs font-medium text-rose-800 hover:bg-white">Retry verification</button>
                        </form>
                    @endif

                    @if (session('analytics_error_action') === 'enable_analytics')
                        <form method="POST" action="{{ route('app.sites.analytics.enable', $site) }}">
                            @csrf
                            <button class="rounded border border-rose-300 bg-white/70 px-3 py-1.5 text-xs font-medium text-rose-800 hover:bg-white">Enable analytics</button>
                        </form>
                    @endif

                    @if (session('analytics_error_action') === 'regenerate_token' && $analyticsSite)
                        <form method="POST" action="{{ route('app.sites.analytics.regenerate-token', $site) }}">
                            @csrf
                            <button class="rounded border border-rose-300 bg-white/70 px-3 py-1.5 text-xs font-medium text-rose-800 hover:bg-white">Regenerate token</button>
                        </form>
                    @endif

                    @if (session('analytics_error_action') === 'review_site_settings')
                        <a href="{{ route('app.sites.show', $site) }}" class="rounded border border-rose-300 bg-white/70 px-3 py-1.5 text-xs font-medium text-rose-800 hover:bg-white">Review site setup</a>
                    @endif
                </div>

                @if (session('error_details'))
                    <details class="mt-2 text-xs text-rose-700">
                        <summary class="cursor-pointer">Technical details</summary>
                        <pre class="mt-2 overflow-x-auto rounded border border-rose-300/50 bg-white/60 px-2 py-2 font-mono text-[11px] text-rose-700">{{ session('error_details') }}</pre>
                    </details>
                @endif
            </div>
        @endif

        <div class="grid gap-6">
            <div class="rounded-lg border border-border bg-surface p-6">
            <h2 class="text-sm font-semibold text-textPrimary">Analytics Status</h2>
            <dl class="mt-3 space-y-2 text-sm">
                <div class="flex justify-between">
                    <dt class="text-textSecondary">Status</dt>
                    <dd class="font-medium {{ $analyticsSite?->is_enabled ? 'text-success' : 'text-textSecondary' }}">
                        {{ $analyticsSite?->is_enabled ? 'Enabled' : 'Disabled' }}
                    </dd>
                </div>
                <div class="flex justify-between">
                    <dt class="text-textSecondary">Verification</dt>
                    <dd class="font-medium {{ $analyticsSite?->verified_at ? 'text-success' : 'text-amber-600' }}">
                        @if ($analyticsSite?->isInternallyVerified())
                            <span class="inline-flex items-center gap-1">
                                <span>Verified via first-party domain</span>
                                <span class="rounded-full bg-primary/10 px-2 py-0.5 text-[10px] font-medium text-primary">Internal</span>
                            </span>
                        @elseif ($analyticsSite?->verified_at)
                            Verified {{ $analyticsSite->verified_at->diffForHumans() }}
                        @else
                            Not verified
                        @endif
                    </dd>
                </div>
                @if ($analyticsSite)
                    <div class="flex justify-between">
                        <dt class="text-textSecondary">Public Key</dt>
                        <dd class="font-mono text-xs text-textPrimary">{{ $analyticsSite->public_key }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-textSecondary">Respect DNT</dt>
                        <dd class="text-textPrimary">{{ $analyticsSite->respect_dnt ? 'Yes' : 'No' }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-textSecondary">Sampling Rate</dt>
                        <dd class="text-textPrimary">{{ $analyticsSite->sampling_rate }}%</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-textSecondary">Data Retention</dt>
                        <dd class="text-textPrimary">{{ $analyticsSite->retention_days }} days</dd>
                    </div>
                @endif
            </dl>

            <div class="mt-6 flex flex-wrap gap-2">
                @if ($analyticsSite?->is_enabled)
                    <form method="POST" action="{{ route('app.sites.analytics.disable', $site) }}">
                        @csrf
                        <button class="rounded border border-border px-3 py-1.5 text-xs">Disable Analytics</button>
                    </form>
                    @if (!$analyticsSite->verified_at)
                        <form method="POST" action="{{ route('app.sites.analytics.verify', $site) }}">
                            @csrf
                            <button class="rounded border border-primary bg-primary px-3 py-1.5 text-xs text-white">{{ $isFirstPartyDomain ? 'Verify First-Party Domain' : 'Verify Domain' }}</button>
                        </form>
                    @endif
                    <form method="POST" action="{{ route('app.sites.analytics.regenerate-token', $site) }}">
                        @csrf
                        <button class="rounded border border-border px-3 py-1.5 text-xs">Regenerate Token</button>
                    </form>
                @else
                    <form method="POST" action="{{ route('app.sites.analytics.enable', $site) }}">
                        @csrf
                        <button class="rounded border border-primary bg-primary px-3 py-1.5 text-xs text-white">Enable Analytics</button>
                    </form>
                @endif
            </div>
            </div>

            @if ($analyticsSite)
                @if ($analyticsSite->isInternallyVerified())
                    <div class="rounded-lg border border-primary/30 bg-primary/5 p-6">
                        <h2 class="text-sm font-semibold text-primary">First-Party Domain Verified</h2>
                        <p class="mt-2 text-sm text-textSecondary">
                            This site is verified as an Argusly first-party domain. Analytics tracking is injected automatically on the marketing site.
                        </p>
                        <p class="mt-2 text-xs text-textSecondary">
                            Internal domain: <span class="font-mono">{{ $analyticsSite->flags['internal_domain'] ?? 'Unknown' }}</span>
                        </p>
                    </div>
                @elseif ($isFirstPartyDomain)
                    <div class="rounded-lg border border-primary/30 bg-primary/5 p-6">
                        <h2 class="text-sm font-semibold text-primary">First-Party Domain Ready</h2>
                        <p class="mt-2 text-sm text-textSecondary">
                            This is an Argusly-owned marketing domain. Verify it here and Argusly will inject tracking from this app automatically.
                        </p>
                        <form method="POST" action="{{ route('app.sites.analytics.verify', $site) }}" class="mt-4">
                            @csrf
                            <button class="rounded border border-primary bg-primary px-3 py-1.5 text-xs font-medium text-white">Verify first-party domain</button>
                        </form>
                    </div>
                @elseif (!$analyticsSite->verified_at)
                    <div class="rounded-lg border border-amber-500/30 bg-amber-500/10 p-6">
                    <h2 class="text-sm font-semibold text-amber-800">Domain Verification Required</h2>
                    <p class="mt-2 text-sm text-amber-700">
                        Add the following meta tag to your site's <code class="rounded bg-amber-200/50 px-1">&lt;head&gt;</code> section:
                    </p>
                    <pre class="mt-3 overflow-x-auto rounded border border-amber-300 bg-white px-3 py-2 font-mono text-xs text-textPrimary" id="verification-meta">&lt;meta name="argusly-site-verification" content="{{ $analyticsSite->verification_token }}"&gt;</pre>
                    <button type="button" class="mt-2 rounded border border-amber-300 px-3 py-1 text-xs text-amber-800" onclick="navigator.clipboard.writeText(document.getElementById('verification-meta').innerText)">
                        Copy meta tag
                    </button>
                    <p class="mt-3 text-xs text-amber-600">Tracking starts after verification.</p>
                    </div>
                @endif

                <div class="rounded-lg border border-border bg-surface p-6">
                <h2 class="text-sm font-semibold text-textPrimary">Install Argusly Tracking</h2>
                @if ($analyticsSite->isInternallyVerified())
                    <p class="mt-2 text-sm text-textSecondary">
                        Tracking is automatically injected on the marketing site. No manual installation required.
                    </p>
                    <details class="mt-4">
                        <summary class="cursor-pointer text-sm text-textSecondary hover:text-textPrimary">View tracking snippet</summary>
                        <div class="mt-3">
                            <p class="text-xs text-textSecondary">
                                Script host: <span class="font-mono text-xs">{{ $trackingBaseUrl }}/argusly.js?v={{ $scriptVersion }}</span>
                            </p>
                            <pre id="pl-tracking-snippet" class="mt-2 overflow-x-auto rounded border border-border bg-background px-3 py-2 font-mono text-xs text-textPrimary"><code>{{ $trackingSnippet }}</code></pre>
                        </div>
                    </details>
                @elseif ($isFirstPartyDomain)
                    <p class="mt-2 text-sm text-textSecondary">
                        No code snippet is needed for Argusly.com. After first-party verification, the marketing layout in this app injects the tracking script automatically.
                    </p>
                    <p class="mt-3 text-xs text-textSecondary">
                        Script host: <span class="font-mono text-xs">{{ $trackingBaseUrl }}/argusly.js?v={{ $scriptVersion }}</span>
                    </p>
                @else
                    <p class="mt-2 text-sm text-textSecondary">
                        Add this script to your website to track Argusly article performance.
                    </p>
                    <p class="mt-1 text-xs text-textSecondary">
                        Script host: <span class="font-mono text-xs">{{ $trackingBaseUrl }}/argusly.js?v={{ $scriptVersion }}</span>
                    </p>

                    <div class="mt-4">
                        <pre id="pl-tracking-snippet" class="overflow-x-auto rounded border border-border bg-background px-3 py-2 font-mono text-xs text-textPrimary"><code>{{ $trackingSnippet }}</code></pre>
                        <button
                            type="button"
                            class="mt-2 rounded border border-border px-3 py-1 text-xs"
                            onclick="navigator.clipboard.writeText(document.getElementById('pl-tracking-snippet').innerText).then(() => { const originalText = this.innerText; this.innerText = 'Copied'; setTimeout(() => { this.innerText = originalText; }, 1500); });"
                        >
                            Copy snippet
                        </button>
                    </div>

                    <div class="mt-6 border-t border-border pt-6">
                        <h3 class="text-sm font-medium text-textPrimary">Installation options</h3>
                        <div class="mt-3 space-y-4 text-xs text-textSecondary">
                            <div>
                                <p class="text-sm font-medium text-textPrimary">Option A: Add to &lt;head&gt; on all pages (Recommended)</p>
                                <ul class="mt-1 list-disc space-y-1 pl-4">
                                    <li>WordPress: use theme header or a header script plugin.</li>
                                    <li>Laravel: add to your main layout Blade file.</li>
                                </ul>
                            </div>
                            <div>
                                <p class="text-sm font-medium text-textPrimary">Option B: Use Google Tag Manager</p>
                                <ul class="mt-1 list-disc space-y-1 pl-4">
                                    <li>Create a Custom HTML tag.</li>
                                    <li>Paste the same snippet above.</li>
                                    <li>Set the trigger to All Pages.</li>
                                </ul>
                            </div>
                        </div>
                    </div>
                @endif
                </div>

                <div class="rounded-lg border border-border bg-surface p-6">
                <h2 class="text-sm font-semibold text-textPrimary">Quick Stats</h2>
                <form method="GET" class="mt-3">
                    <select name="scope" onchange="this.form.submit()" class="rounded border border-border bg-background px-3 py-2 text-sm">
                        <option value="all" {{ $scope === 'all' ? 'selected' : '' }}>All pages</option>
                        <option value="argusly_content" {{ $scope === 'argusly_content' ? 'selected' : '' }}>Argusly content</option>
                        <option value="other_page" {{ $scope === 'other_page' ? 'selected' : '' }}>Other site pages</option>
                    </select>
                </form>
                    <div class="mt-4 grid gap-4 sm:grid-cols-2">
                        <div class="rounded-lg border border-border bg-background p-4">
                        <p class="text-xs text-textSecondary">Pageviews (7 days)</p>
                        <p class="mt-1 text-xl font-semibold text-textPrimary">{{ number_format($stats['pageviews_7d']) }}</p>
                    </div>
                        <div class="rounded-lg border border-border bg-background p-4">
                        <p class="text-xs text-textSecondary">Pageviews (30 days)</p>
                        <p class="mt-1 text-xl font-semibold text-textPrimary">{{ number_format($stats['pageviews_30d']) }}</p>
                    </div>
                    </div>

                <h3 class="mt-4 text-sm font-medium text-textPrimary">Article Pageviews Per Day (last 30 days)</h3>
                @if ($stats['article_daily']->isEmpty())
                    <p class="mt-2 text-sm text-textSecondary">No article pageviews yet.</p>
                @else
                    <div class="mt-2 overflow-x-auto">
                        <table class="min-w-full text-left text-xs">
                            <thead>
                                <tr class="border-b border-border text-textSecondary">
                                    <th class="px-2 py-2">Date</th>
                                    <th class="px-2 py-2">Canonical URL</th>
                                    <th class="px-2 py-2">Pageviews</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($stats['article_daily'] as $row)
                                    <tr class="border-b border-border/60">
                                        <td class="px-2 py-2 text-textPrimary">{{ $row->day }}</td>
                                        <td class="px-2 py-2 font-mono text-[11px] text-textPrimary">{{ $row->canonical_url }}</td>
                                        <td class="px-2 py-2 text-textPrimary">{{ number_format((int) $row->pageviews) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif

                <p class="mt-3 text-xs text-textSecondary">
                    View detailed analytics on the <a href="{{ route('app.sites.learnings.index', ['site' => $site, 'scope' => $scope]) }}" class="text-primary underline">Learnings page</a>.
                </p>
                </div>
            @endif
        </div>
    </div>
@endsection
