@props(['mobile' => false])

@php
    $account = current_account();
    $user = auth()->user();
    $moduleAccess = app(\App\Services\Subscriptions\ModuleAccessService::class);
    $permissionService = app(\App\Services\PermissionService::class);
    $activeModuleKeys = $account ? $moduleAccess->activeModuleKeys($account) : [];
    $isPlatformAdmin = $user
        ? $permissionService->userCan($user, 'manage_platform', ['account_id' => null, 'brand_id' => null])
        : false;
    $pendingPilotSignupCount = $isPlatformAdmin && \Illuminate\Support\Facades\Schema::hasTable('pilot_signups')
        ? \Illuminate\Support\Facades\DB::table('pilot_signups')->whereIn('status', ['pending', 'reviewing'])->count()
        : 0;

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

    $groups = collect(config('navigation.app', []))
        ->map(function (array $group) use ($canSee) {
            $items = collect($group['items'] ?? [])
                ->map(function (array $item) use ($canSee) {
                    $item['children'] = collect($item['children'] ?? [])
                        ->filter(fn (array $child) => $canSee($child, $item) && Route::has($child['route']))
                        ->values()
                        ->all();

                    $item['can_access'] = $canSee($item);
                    $item['visible'] = $item['can_access'] || count($item['children']) > 0;

                    return $item;
                })
                ->filter(fn (array $item) => $item['visible'] && (Route::has($item['route']) || count($item['children'] ?? []) > 0))
                ->values()
                ->all();

            return [...$group, 'items' => $items];
        })
        ->filter(fn (array $group) => count($group['items'] ?? []) > 0)
        ->values();

    $isActive = fn (array $item): bool => request()->routeIs(...($item['active'] ?? [$item['route'] ?? '']));
@endphp

<aside @class([
    'app-sidebar border-r border-line bg-white',
    'fixed inset-y-0 left-0 z-50 w-[300px] -translate-x-full transition-transform duration-200 lg:hidden' => $mobile,
    'hidden w-[272px] lg:block' => ! $mobile,
]) @if($mobile) data-mobile-sidebar @else data-sidebar @endif>
    <div class="sticky top-0 flex h-screen flex-col">
        <div class="flex h-16 items-center justify-between px-4">
            <x-brand />
            <div class="flex items-center gap-1">
                @if ($mobile)
                    <button type="button" data-mobile-sidebar-close class="rounded-md border border-line px-2 py-1 text-sm font-semibold text-muted" aria-label="Close navigation">Close</button>
                @endif
            </div>
        </div>

        <nav class="min-h-0 flex-1 overflow-y-auto px-3 py-4">
            @foreach ($groups as $group)
                <section class="nav-group border-t border-line/70 py-3 first:border-t-0 first:pt-0" data-nav-group="{{ str($group['label'])->slug() }}">
                    <div class="space-y-0.5" data-group-panel="{{ str($group['label'])->slug() }}">
                        @foreach ($group['items'] as $item)
                            @php
                                $platformAdminItem = $isPlatformAdmin && ($item['key'] ?? null) === 'administration';
                                $itemActive = $platformAdminItem
                                    ? request()->routeIs('admin.*', 'settings.*', 'app.admin.*', 'app.domain-events')
                                    : ($isActive($item) || collect($item['children'] ?? [])->contains(fn (array $child) => $isActive($child)));
                                $fallbackRoute = $item['children'][0]['route'] ?? null;
                                $hrefRoute = $platformAdminItem
                                    ? 'admin.overview'
                                    : (($item['can_access'] ?? false) ? ($item['route'] ?? null) : $fallbackRoute);
                                $href = $hrefRoute && Route::has($hrefRoute) ? route($hrefRoute) : '#';
                            @endphp
                            <a href="{{ $href }}" data-workspace="{{ $item['key'] }}" @class([
                                'group flex items-center gap-3 rounded-md px-3 py-2 text-sm font-medium transition',
                                'nav-item-active' => $itemActive,
                                'text-muted hover:bg-panel hover:text-ink' => ! $itemActive,
                            ])>
                                <span @class([
                                    'grid size-5 shrink-0 place-items-center',
                                    'text-blue' => $itemActive,
                                    'text-muted group-hover:text-ink' => ! $itemActive,
                                ])>
                                    <x-app.icon :name="$item['icon'] ?? 'circle'" class="size-4" />
                                </span>
                                <span class="nav-label min-w-0 flex-1 truncate">{{ $item['label'] }}</span>
                                @if ($platformAdminItem && $pendingPilotSignupCount > 0)
                                    <span class="inline-flex min-w-5 justify-center rounded-full bg-amber-100 px-1.5 py-0.5 text-xs font-semibold text-amber-800">{{ $pendingPilotSignupCount }}</span>
                                @endif
                            </a>
                        @endforeach
                    </div>
                </section>
            @endforeach
        </nav>
    </div>
</aside>
