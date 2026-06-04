@php
    $account = current_account();
    $user = auth()->user();
    $moduleAccess = app(\App\Services\Subscriptions\ModuleAccessService::class);
    $permissionService = app(\App\Services\PermissionService::class);
    $isPlatformAdmin = $user
        ? $permissionService->userCan($user, 'manage_platform', ['account_id' => null, 'brand_id' => null])
        : false;

    $tenantGroups = [
        'Personal' => [
            ['label' => 'Profile', 'route' => 'settings.profile', 'permission' => 'view_dashboard', 'personal' => true],
        ],
        'Administration' => [
            ['label' => 'Brands', 'route' => 'settings.brands', 'permission' => 'manage_account'],
            ['label' => 'Team', 'route' => 'settings.team', 'permission' => 'manage_users'],
            ['label' => 'Billing', 'route' => 'settings.modules', 'permission' => 'manage_billing'],
            ['label' => 'LLM', 'route' => 'settings.llm', 'permission' => 'manage_account'],
            ['label' => 'Settings', 'route' => 'settings.account', 'permission' => 'manage_account'],
        ],
        'Connections' => [
            ['label' => 'Integrations', 'route' => 'settings.integrations', 'permission' => 'manage_account'],
            ['label' => 'Social Profiles', 'route' => 'settings.social-profiles', 'permission' => 'manage_account'],
            ['label' => 'Email Providers', 'route' => 'settings.email-providers', 'permission' => 'manage_account'],
        ],
        'Publishing Infrastructure' => [
            ['label' => 'Connectors', 'route' => 'settings.connectors', 'permission' => 'manage_account', 'modules' => ['connectors']],
            ['label' => 'Properties', 'route' => 'settings.properties', 'permission' => 'manage_account', 'modules' => ['content']],
            ['label' => 'Channels', 'route' => 'settings.channels', 'permission' => 'manage_account', 'modules' => ['content']],
        ],
        'Brand Setup' => [
            ['label' => 'Knowledge Center', 'route' => 'settings.knowledge-center', 'permission' => 'manage_account'],
            ['label' => 'Knowledge Graph', 'route' => 'settings.knowledge-graph', 'permission' => 'manage_account'],
        ],
        'Developer Tools' => [
            ['label' => 'Domain Events', 'route' => 'app.domain-events', 'permission' => 'manage_account'],
            ['label' => 'Source Syncs', 'route' => 'app.admin.developer-tools.source-syncs', 'permission' => 'manage_account'],
            ['label' => 'Connector Logs', 'route' => 'app.admin.developer-tools.connector-logs', 'permission' => 'manage_account'],
            ['label' => 'Outbox Messages', 'route' => 'app.admin.developer-tools.outbox', 'permission' => 'manage_account'],
            ['label' => 'Activity Logs', 'route' => 'app.admin.developer-tools.activity', 'permission' => 'manage_account'],
            ['label' => 'Queue Monitoring', 'route' => 'app.admin.developer-tools.queue', 'permission' => 'manage_account'],
            ['label' => 'System Health', 'route' => 'app.admin.developer-tools.system-health', 'permission' => 'manage_account'],
            ['label' => 'Diagnostics', 'route' => 'app.admin.developer-tools.diagnostics', 'permission' => 'manage_account'],
        ],
    ];

    $platformGroups = [
        'Personal' => [
            ['label' => 'Profile', 'route' => 'settings.profile', 'permission' => 'view_dashboard', 'personal' => true],
        ],
        'Platform' => [
            ['label' => 'Admin Control Center', 'route' => 'admin.overview', 'permission' => 'manage_platform', 'platform' => true],
            ['label' => 'Pilot Requests', 'route' => 'admin.pilot-signups', 'permission' => 'manage_platform', 'platform' => true],
            ['label' => 'Contact Requests', 'route' => 'admin.contact-requests', 'permission' => 'manage_platform', 'platform' => true],
        ],
    ];

    $groups = $isPlatformAdmin ? $platformGroups : $tenantGroups;

    $canSee = function (array $item) use ($account, $user, $moduleAccess, $isPlatformAdmin): bool {
        if (! $user || ! Route::has($item['route'])) {
            return false;
        }

        if (($item['platform'] ?? false) && $isPlatformAdmin) {
            return true;
        }

        if (($item['personal'] ?? false) && ($account || $isPlatformAdmin)) {
            return true;
        }

        if (! $account) {
            return false;
        }

        if (isset($item['modules']) && ! $moduleAccess->accountHasAnyModule($account, $item['modules'])) {
            return false;
        }

        return \Illuminate\Support\Facades\Gate::forUser($user)->allows($item['permission'] ?? 'manage_account', ['account_id' => $account->id]);
    };
@endphp

<nav class="rounded-md border border-line bg-white p-3 lg:sticky lg:top-24">
    <div class="space-y-4">
        @foreach ($groups as $label => $items)
            @php $visible = collect($items)->filter($canSee); @endphp
            @if ($visible->isNotEmpty())
                <section>
                    <p class="px-2 text-[10px] font-bold uppercase tracking-[0.14em] text-muted">{{ $label }}</p>
                    <div class="mt-2 space-y-1">
                        @foreach ($visible as $item)
                            <a href="{{ route($item['route']) }}" class="block rounded-md px-3 py-2 text-sm font-semibold {{ request()->routeIs($item['route']) ? 'bg-ink text-white' : 'text-muted hover:bg-panel hover:text-ink' }}">
                                {{ $item['label'] }}
                            </a>
                        @endforeach
                    </div>
                </section>
            @endif
        @endforeach
    </div>
</nav>
