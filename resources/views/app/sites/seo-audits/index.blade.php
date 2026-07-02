@extends('layouts.app', ['title' => 'SEO Audits'])

@section('pageHeader')
    <x-page-header>
        <x-slot:title>Recent audits</x-slot:title>
        <x-slot:description>{{ $audit->error_message }}</x-slot:description>
    </x-page-header>
@endsection

@section('content')
    <div class="space-y-6">
        <x-app.insights-header
            :site="$site"
            title="Audits"
            description="Run SEO audits, review crawl history, and inspect issue counts for this site."
            active="audits"
        >
            <a href="{{ route('app.insights.index') }}" class="rounded-md border border-border px-3 py-2 text-sm text-textPrimary hover:bg-surfaceSubtle">All sites</a>
            <a href="{{ route('app.sites.show', $site) }}" class="rounded-md border border-border px-3 py-2 text-sm text-textPrimary hover:bg-surfaceSubtle">Site setup</a>
        </x-app.insights-header>

        @if (session('status'))
            <x-alert>{{ session('status') }}</x-alert>
        @endif

        <div class="grid gap-6 md:grid-cols-3">
            <div class="rounded-lg border border-border bg-surface p-6">
            <p class="text-xs text-textSecondary">Monthly page cap</p>
            <p class="mt-1 text-xl font-semibold text-textPrimary">{{ $auditPageLimit < 0 ? 'Unlimited' : $auditPageLimit }}</p>
            <p class="mt-1 text-xs text-textSecondary">Used this month: {{ $auditPagesUsed }}</p>
        </div>
            <div class="rounded-lg border border-border bg-surface p-6 md:col-span-2">
            <p class="text-xs text-textSecondary">Last run</p>
            @if ($lastAudit)
                <p class="mt-1 text-sm text-textPrimary">
                    {{ optional($lastAudit->started_at)->toDateTimeString() }} · {{ $lastAudit->status }} · {{ $lastAudit->pages_crawled }} pages
                </p>
                <p class="mt-1 text-xs text-textSecondary">
                    Issues: E {{ data_get($lastAudit, 'overview_issue_counts.error', data_get($lastAudit->issue_counts, 'error', 0)) }}, W {{ data_get($lastAudit, 'overview_issue_counts.warning', data_get($lastAudit->issue_counts, 'warning', 0)) }}, I {{ data_get($lastAudit, 'overview_issue_counts.info', data_get($lastAudit->issue_counts, 'info', 0)) }}
                </p>
                @if ($lastAudit->error_message)
                    <p class="mt-2 text-xs text-amber-700">{{ $lastAudit->error_message }}</p>
                @endif
            @else
                <p class="mt-1 text-sm text-textSecondary">No runs yet.</p>
            @endif
            </div>
        </div>

        <div class="rounded-lg border border-border bg-surface p-6">
            <form method="POST" action="{{ route('app.sites.seo-audits.run', $site) }}" class="flex flex-wrap items-end gap-4">
            @csrf
            <div>
                <label class="mb-1 block text-xs text-textSecondary">Max pages this run</label>
                <input type="number" name="max_pages" min="1" max="500" value="50" class="w-28 rounded border border-border bg-background px-2 py-2 text-sm">
            </div>
            <button class="rounded border border-border px-3 py-2 text-sm">Run SEO audit</button>
            </form>
        </div>

        <x-data-table label="SEO audit runs" description="Recent SEO audit runs with status, pages crawled, issue counts, and detail links.">
            <x-data-table.header>
                <x-data-table.row>
                    <x-data-table.cell heading>Started</x-data-table.cell>
                    <x-data-table.cell heading>Status</x-data-table.cell>
                    <x-data-table.cell heading>Pages</x-data-table.cell>
                    <x-data-table.cell heading>Issues</x-data-table.cell>
                    <x-data-table.cell heading>Action</x-data-table.cell>
                </x-data-table.row>
            </x-data-table.header>
            <tbody>
                @forelse ($audits as $audit)
                    @php
                        $seoAuditResourceKey = 'seo_audit:'.$audit->getKey();
                        $seoAuditResource = $interactionResourcesByKey[$seoAuditResourceKey] ?? null;
                        $seoAuditOpenAction = $interactionActionsByKey[$seoAuditResourceKey]['app.seo-audit.open'] ?? null;
                        $seoAuditShowHref = route('app.sites.seo-audits.show', [$site, $audit]);
                        $seoAuditDrawerDescriptor = null;

                        if (is_array($seoAuditResource) && is_array($seoAuditOpenAction)) {
                            $seoAuditDrawerResource = [
                                'key' => $seoAuditResourceKey,
                                'type' => \App\Support\Interaction\ResourceType::SEO_AUDIT,
                                'id' => $audit->getKey(),
                                'available_actions' => ['app.seo-audit.open'],
                                'permissions' => $seoAuditResource['permissions'] ?? [],
                            ];
                            $seoAuditDrawerAction = [
                                'key' => 'app.seo-audit.open',
                                'execution_mode' => 'link',
                                'method' => 'GET',
                                'url' => $seoAuditOpenAction['url'] ?? $seoAuditShowHref,
                                'route' => $seoAuditOpenAction['route'] ?? null,
                                'resource' => [
                                    'type' => \App\Support\Interaction\ResourceType::SEO_AUDIT,
                                    'id' => $audit->getKey(),
                                ],
                                'authorized' => (bool) ($seoAuditOpenAction['authorized'] ?? true),
                                'visible' => (bool) ($seoAuditOpenAction['visible'] ?? true),
                                'disabled' => (bool) ($seoAuditOpenAction['disabled'] ?? false),
                            ];

                            $seoAuditDrawerDescriptor = \App\Support\Interaction\DrawerMetadataBuilder::make()->build(
                                \App\Support\Interaction\DrawerTarget::make(
                                    'seo-audit.inspect',
                                    \App\Support\Interaction\DrawerState::MODE_INSPECT,
                                    'md',
                                )
                                    ->forResource(\App\Support\Interaction\ResourceType::SEO_AUDIT, $audit->getKey(), $seoAuditResourceKey)
                                    ->forAction('app.seo-audit.open')
                                    ->withHref($seoAuditShowHref),
                                [
                                    'resource' => $seoAuditDrawerResource,
                                    'action' => $seoAuditDrawerAction,
                                    'title' => 'Inspect',
                                    'subtitle' => 'Detail',
                                    'tabs' => [],
                                    'sections' => [],
                                    'footer_actions' => [],
                                    'preview' => [],
                                    'ai' => [],
                                    'relationships' => [],
                                    'loading' => [
                                        'title' => 'Loading detail',
                                        'description' => 'Loading drawer metadata.',
                                    ],
                                    'empty' => [
                                        'title' => 'No detail selected',
                                        'description' => 'Open the full page for complete details.',
                                    ],
                                    'errors' => [
                                        'title' => 'Detail unavailable',
                                        'description' => 'The drawer metadata could not be prepared.',
                                    ],
                                    'metadata' => [
                                        'adoption' => 'app.sites.seo.index:additive-inspect-drawer',
                                        'renders_production_content' => false,
                                    ],
                                ],
                            );
                        }
                    @endphp
                    <x-data-table.row>
                        <x-data-table.cell label="Started">{{ optional($audit->started_at)->toDateTimeString() }}</x-data-table.cell>
                        <x-data-table.cell label="Status">
                            <x-data-table.badge :tone="$audit->error_message ? 'warning' : 'neutral'" :label="$audit->status" />
                            @if ($audit->error_message)
                                <p class="mt-1 text-xs text-amber-700">{{ $audit->error_message }}</p>
                            @endif
                        </x-data-table.cell>
                        <x-data-table.cell label="Pages">{{ $audit->pages_crawled }}</x-data-table.cell>
                        <x-data-table.cell label="Issues">E {{ data_get($audit, 'overview_issue_counts.error', data_get($audit->issue_counts, 'error', 0)) }} / W {{ data_get($audit, 'overview_issue_counts.warning', data_get($audit->issue_counts, 'warning', 0)) }} / I {{ data_get($audit, 'overview_issue_counts.info', data_get($audit->issue_counts, 'info', 0)) }}</x-data-table.cell>
                        <x-data-table.cell label="Action">
                            <x-data-table.actions align="start">
                                <a href="{{ $seoAuditShowHref }}" class="rounded border border-border px-2 py-1 text-xs">Open</a>

                                @if ($seoAuditDrawerDescriptor)
                                    <x-drawer-button
                                        :descriptor="$seoAuditDrawerDescriptor"
                                        :href="$seoAuditShowHref"
                                        class="px-2 py-1 text-xs"
                                        aria-label="Inspect SEO audit {{ $audit->getKey() }}"
                                    >
                                        Inspect
                                    </x-drawer-button>
                                @endif
                            </x-data-table.actions>
                        </x-data-table.cell>
                    </x-data-table.row>
                @empty
                    <x-data-table.empty colspan="5" title="No audit runs yet" />
                @endforelse
            </tbody>
        </x-data-table>
    </div>
@endsection

@section('detailDrawer')
    <x-drawer.drawer
        :open="false"
        :drawer="[
            'key' => 'seo-audit.inspect',
            'mode' => 'inspect',
            'modal' => false,
            'width' => 'md',
            'title' => 'SEO audit inspect',
            'subtitle' => 'SEO audit',
            'description' => 'Select Inspect on an audit row to open drawer metadata when drawer JavaScript is available.',
            'tabs' => [],
            'sections' => [],
            'footer_actions' => [],
            'empty_state' => [
                'title' => 'No SEO audit selected',
                'description' => 'SEO audit detail pages remain the canonical destination.',
            ],
            'state' => [
                'mode' => 'inspect',
                'open' => false,
                'loading' => false,
                'empty' => true,
                'error' => false,
                'message' => null,
                'interactive' => false,
                'can_edit' => false,
                'metadata' => [
                    'renders_production_content' => false,
                ],
            ],
        ]"
    />
@endsection
