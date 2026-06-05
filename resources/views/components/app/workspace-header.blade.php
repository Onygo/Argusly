@php
    $active = null;
    $activeGroup = null;

    $account = current_account();
    $user = auth()->user();
    $moduleAccess = app(\App\Services\Subscriptions\ModuleAccessService::class);
    $activeModuleKeys = $account ? $moduleAccess->activeModuleKeys($account) : [];

    $canSee = function (array $item, ?array $parent = null) use ($account, $user, $activeModuleKeys): bool {
        if (! $account || ! $user) {
            return false;
        }

        $modules = collect($item['modules'] ?? $parent['modules'] ?? ['core'])
            ->flatMap(fn (string $module) => preg_split('/[|,]/', $module) ?: [])
            ->map(fn (string $module) => trim($module))
            ->filter()
            ->all();
        $permission = $item['permission'] ?? $parent['permission'] ?? 'view_dashboard';

        return count(array_intersect($activeModuleKeys, $modules)) > 0
            && \Illuminate\Support\Facades\Gate::forUser($user)->allows($permission, ['account_id' => $account->id]);
    };

    $isActive = fn (array $item): bool => request()->routeIs(...($item['active'] ?? [$item['route'] ?? '']));

    foreach (config('navigation.app', []) as $group) {
        foreach ($group['items'] ?? [] as $item) {
            if ($isActive($item)) {
                $active = $item;
                $activeGroup = $group;
            }

            foreach ($item['children'] ?? [] as $child) {
                if ($isActive($child)) {
                    $active = $item;
                    $activeGroup = $group;
                }
            }
        }
    }

    $actions = [
        'dashboard' => [
            'primary' => ['label' => 'Open Intelligence', 'route' => 'app.intelligence'],
            'secondary' => [['label' => 'View Visibility', 'route' => 'app.visibility']],
        ],
        'intelligence' => [
            'primary' => ['label' => 'Open Feed', 'route' => 'app.intelligence'],
            'secondary' => [['label' => 'Notifications', 'route' => 'app.notifications']],
        ],
        'visibility' => [
            'primary' => ['label' => 'Run Visibility Check', 'route' => 'app.visibility'],
            'secondary' => [['label' => 'Search Performance', 'route' => 'app.search-performance']],
        ],
        'research' => [
            'primary' => ['label' => 'Create Topic', 'route' => 'app.topics.create'],
            'secondary' => [['label' => 'View Sources', 'route' => 'app.sources.index']],
        ],
        'content' => [
            'primary' => ['label' => 'Create Content', 'route' => 'app.content.create'],
            'secondary' => [['label' => 'Run Audit', 'route' => 'app.content.audits']],
        ],
        'marketing' => [
            'primary' => ['label' => 'Create Campaign', 'route' => 'app.campaigns'],
            'secondary' => [['label' => 'Open Calendar', 'route' => 'app.calendar']],
        ],
        'agents' => [
            'primary' => ['label' => 'Run Agent', 'route' => 'app.agents'],
            'secondary' => [['label' => 'View Runs', 'route' => 'app.agents.runs']],
        ],
        'relationships' => [
            'primary' => ['label' => 'View Contacts', 'route' => 'app.relationships.contacts'],
            'secondary' => [['label' => 'Organizations', 'route' => 'app.relationships.organizations']],
        ],
        'reporting' => [
            'primary' => ['label' => 'Open Reports', 'route' => 'app.reports'],
            'secondary' => [['label' => 'Analytics', 'route' => 'app.analytics']],
        ],
        'administration' => [
            'primary' => ['label' => 'Manage Team', 'route' => 'settings.team'],
            'secondary' => [['label' => 'Integrations', 'route' => 'settings.integrations']],
        ],
    ];

    $actionSet = $active ? ($actions[$active['key']] ?? []) : [];
    $primaryAction = $actionSet['primary'] ?? null;
    $secondaryActions = collect($actionSet['secondary'] ?? [])->filter(fn ($action) => Route::has($action['route']));
    $tabs = $active
        ? collect($active['children'] ?? [])
            ->filter(fn (array $child) => $canSee($child, $active) && Route::has($child['route']))
            ->values()
        : collect();
@endphp

@if ($active)
    <section class="border-b border-line bg-white px-4 py-2.5 sm:px-6 lg:px-8">
        <div class="flex w-full flex-col gap-2 lg:flex-row lg:items-center lg:justify-between">
            <div class="flex min-w-0 items-center gap-2 overflow-x-auto">
                @if ($activeGroup)
                    <span class="shrink-0 text-xs font-semibold uppercase tracking-[0.12em] text-muted/75">{{ $activeGroup['label'] }}</span>
                    <span class="shrink-0 text-muted/40">/</span>
                @endif

                @if ($tabs->isNotEmpty())
                    <nav class="flex min-w-0 gap-1" aria-label="{{ $active['label'] }} navigation">
                        @foreach ($tabs as $tab)
                            @php $tabActive = $isActive($tab); @endphp
                            <a href="{{ route($tab['route']) }}" @class([
                                'whitespace-nowrap rounded-md px-3 py-1.5 text-sm font-semibold transition',
                                'bg-blue/10 text-blue' => $tabActive,
                                'text-muted hover:bg-panel hover:text-ink' => ! $tabActive,
                            ])>
                                {{ $tab['label'] }}
                            </a>
                        @endforeach
                    </nav>
                @else
                    <span class="truncate text-sm font-semibold text-ink">{{ $active['label'] }}</span>
                @endif
            </div>
            <div class="flex shrink-0 flex-wrap items-center gap-2">
                @foreach ($secondaryActions as $secondaryAction)
                    <x-ui.button :href="route($secondaryAction['route'])" variant="secondary" size="sm">
                        {{ $secondaryAction['label'] }}
                    </x-ui.button>
                @endforeach
                @if ($primaryAction && Route::has($primaryAction['route']))
                    <x-ui.button :href="route($primaryAction['route'])" size="sm">
                        {{ $primaryAction['label'] }}
                    </x-ui.button>
                @endif
            </div>
        </div>
    </section>
@endif
