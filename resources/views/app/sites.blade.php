@extends('layouts.app', ['title' => 'Sites'])

@section('content')
    <div class="mb-6 flex items-start justify-between gap-4">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight text-textPrimary">Sites</h1>
            <p class="mt-1 text-textSecondary">Connect WordPress or Laravel sites to start generating briefs and drafts.</p>
        </div>
    </div>

    @if (session('status'))
        <x-alert class="mb-4">{{ session('status') }}</x-alert>
    @endif

    @if ($errors->has('sites'))
        <div class="mb-4 rounded-md border border-rose-500/30 bg-rose-500/10 px-3 py-2 text-sm text-rose-800">{{ $errors->first('sites') }}</div>
    @endif

    @php
        $generatedSite = $sites->firstWhere('id', (string) $generatedSiteId);
        $generatedSiteType = \App\Models\ClientSite::normalizeType((string) ($generatedSite?->type ?? old('type', 'wordpress')));
    @endphp
    @if ($generatedKey)
        <div class="mb-6 rounded-lg border border-primary/40 bg-primarySoftBg p-4">
            <p class="text-sm font-semibold text-textPrimary">New site key generated</p>
            <p class="mt-1 text-xs text-textSecondary">Copy this key now. It is shown only once.</p>
            <div class="mt-3 rounded border border-border bg-surface px-3 py-2 font-mono text-sm text-textPrimary" id="site-key-value">{{ $generatedKey }}</div>
            <button type="button" class="mt-3 rounded border border-border px-3 py-1.5 text-xs" onclick="navigator.clipboard.writeText(document.getElementById('site-key-value').innerText)">Copy key</button>
            @include('app.sites.partials.setup-instructions', ['siteType' => $generatedSiteType])
        </div>
    @endif

    <div class="mb-6 grid gap-3 md:grid-cols-3">
        <div class="rounded-lg border border-border bg-surface p-4">
            <p class="text-xs text-textSecondary">Max sites</p>
            <p class="mt-1 text-xl font-semibold text-textPrimary">{{ ($limits['max_sites'] ?? -1) < 0 ? 'Unlimited' : $limits['max_sites'] }}</p>
            <p class="mt-1 text-xs text-textSecondary">
                Used: {{ $siteUsage['sites_used'] ?? 0 }}
                @if (($siteUsage['sites_remaining'] ?? -1) >= 0)
                    , Remaining: {{ $siteUsage['sites_remaining'] }}
                @endif
            </p>
        </div>
        <div class="rounded-lg border border-border bg-surface p-4">
            <p class="text-xs text-textSecondary">Briefs this month</p>
            <p class="mt-1 text-xl font-semibold text-textPrimary">{{ $usage['briefs_count'] ?? 0 }} / {{ ($limits['briefs_per_month'] ?? -1) < 0 ? 'Unlimited' : $limits['briefs_per_month'] }}</p>
        </div>
        <div class="rounded-lg border border-border bg-surface p-4">
            <p class="text-xs text-textSecondary">Drafts this month</p>
            <p class="mt-1 text-xl font-semibold text-textPrimary">{{ $usage['drafts_count'] ?? 0 }} / {{ ($limits['drafts_per_month'] ?? -1) < 0 ? 'Unlimited' : $limits['drafts_per_month'] }}</p>
        </div>
    </div>

    <div class="mb-6 rounded-lg border border-border bg-surface p-4">
        <p class="mb-3 text-sm font-semibold text-textPrimary">Add site</p>
        @if (($siteUsage['site_limit_reached'] ?? false))
            <x-alert class="mb-3 text-xs" :icon="true">
                Site limit reached for your current package.
                <a href="{{ route('app.billing.index', ['tab' => 'subscriptions']) }}" class="underline">Upgrade package</a>
            </x-alert>
        @endif
        <form method="POST" action="{{ route('app.sites.store') }}" class="grid gap-3 md:grid-cols-4">
            @csrf
            <div>
                <label class="mb-1 block text-xs text-textSecondary">Workspace</label>
                <select name="workspace_id" class="w-full rounded border border-border bg-background px-2 py-2 text-sm" required>
                    @foreach ($workspaces as $ws)
                        <option value="{{ $ws->id }}" @selected((string) $workspace->id === (string) $ws->id)>{{ $ws->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="mb-1 block text-xs text-textSecondary">Site type</label>
                <select id="site-type-select" name="type" class="w-full rounded border border-border bg-background px-2 py-2 text-sm" required>
                    <option value="wordpress" @selected(old('type', 'wordpress') === 'wordpress')>WordPress</option>
                    <option value="laravel" @selected(old('type') === 'laravel')>Laravel</option>
                </select>
            </div>
            <div>
                <label class="mb-1 block text-xs text-textSecondary">Site name</label>
                <input type="text" name="name" class="w-full rounded border border-border bg-background px-2 py-2 text-sm" placeholder="Main Website" required>
            </div>
            <div>
                <label class="mb-1 block text-xs text-textSecondary">Site URL</label>
                <input type="text" name="site_url" class="w-full rounded border border-border bg-background px-2 py-2 text-sm" placeholder="https://example.com" required>
            </div>
            <div class="md:col-span-4 rounded border border-border bg-background px-3 py-2 text-xs text-textSecondary">
                <div id="site-type-instructions-wordpress">
                    WordPress setup: download and install the Argusly WP plugin, paste key, connect, then run WP connection test.
                    <div class="mt-2">
                        <a href="{{ route('app.sites.wordpress-plugin.download') }}" class="inline-flex rounded border border-border px-2 py-1 text-xs text-textPrimary hover:bg-surfaceMuted">Download WP plugin (.zip)</a>
                    </div>
                </div>
                <div id="site-type-instructions-laravel" class="hidden">
                    Laravel setup: install <code>onygo/argusly-laravel-connector</code>, configure connector token/API URL/site ID, then run the Laravel connector activity check.
                </div>
            </div>
            <div class="md:col-span-4">
                <button id="site-submit-button" class="rounded border border-border px-3 py-2 text-sm disabled:cursor-not-allowed disabled:opacity-50" @disabled(($siteUsage['site_limit_reached'] ?? false))>Add site and generate key</button>
            </div>
        </form>
    </div>

    <div class="rounded-lg border border-border bg-surface p-4">
        <table class="w-full text-sm">
            <thead>
                <tr class="text-left text-textSecondary">
                    <th class="pb-2 font-medium">Site</th>
                    <th class="pb-2 font-medium">Type</th>
                    <th class="pb-2 font-medium">URL</th>
                    <th class="pb-2 font-medium">Status</th>
                    <th class="pb-2 font-medium">Last seen</th>
                    <th class="pb-2 font-medium">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-border">
                @forelse ($sites as $site)
                    <tr>
                        <td class="py-3">
                            <div class="font-medium text-textPrimary">{{ $site->name }}</div>
                            <div class="text-xs text-textSecondary">{{ $site->workspace?->name }}</div>
                        </td>
                        <td class="py-3">
                            @php
                                $type = \App\Models\ClientSite::normalizeType((string) $site->type);
                            @endphp
                            <span class="inline-flex rounded px-2 py-1 text-xs {{ $type === 'laravel' ? 'bg-sky-100 text-sky-800' : 'bg-violet-100 text-violet-800' }}">
                                {{ strtoupper($type) }}
                            </span>
                        </td>
                        <td class="py-3">{{ $site->base_url ?: $site->site_url }}</td>
                        <td class="py-3">
                            @php
                                $badge = match($site->status) {
                                    'connected' => 'bg-emerald-100 text-emerald-800',
                                    'error' => 'bg-rose-100 text-rose-800',
                                    'disabled' => 'bg-amber-100 text-amber-800',
                                    default => 'bg-slate-100 text-slate-700',
                                };
                            @endphp
                            <span class="inline-flex rounded px-2 py-1 text-xs {{ $badge }}">{{ $site->status }}</span>
                        </td>
                        <td class="py-3">{{ optional($site->last_seen_at)->diffForHumans() ?? 'Never' }}</td>
                        <td class="py-3">
                            @include('app.sites.partials.row-actions', ['site' => $site])
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td class="py-6 text-center text-textSecondary" colspan="6">No sites connected yet.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if (method_exists($sites, 'links'))
        <div class="mt-4">
            {{ $sites->links() }}
        </div>
    @endif

    <script>
        (function () {
            const typeSelect = document.getElementById('site-type-select');
            const wpInstructions = document.getElementById('site-type-instructions-wordpress');
            const laravelInstructions = document.getElementById('site-type-instructions-laravel');
            const submitButton = document.getElementById('site-submit-button');

            if (!typeSelect || !wpInstructions || !laravelInstructions || !submitButton) {
                return;
            }

            const update = () => {
                const isLaravel = typeSelect.value === 'laravel';
                wpInstructions.classList.toggle('hidden', isLaravel);
                laravelInstructions.classList.toggle('hidden', !isLaravel);
                submitButton.textContent = isLaravel ? 'Add Laravel site and generate key' : 'Add WordPress site and generate key';
            };

            typeSelect.addEventListener('change', update);
            update();
        })();
    </script>
@endsection
