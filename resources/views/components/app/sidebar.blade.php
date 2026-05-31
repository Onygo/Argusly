@php
    $account = current_account();
    $brand = current_brand();
    $user = auth()->user();
    $moduleAccess = app(\App\Services\Subscriptions\ModuleAccessService::class);
    $items = collect(config('navigation.app', []))
        ->filter(fn (array $item) => $account && $user && $moduleAccess->userCanAccessAnyModule($user, $account, $item['modules'], $item['permission']));
@endphp

<aside class="hidden border-r border-line bg-white lg:block">
    <div class="sticky top-0 flex h-screen flex-col p-5">
        <x-brand class="text-sm" />
        <nav class="mt-8 space-y-1">
            @foreach ($items as $item)
                <a href="{{ route($item['route']) }}" class="flex items-center justify-between rounded-lg px-3 py-2 text-sm font-medium {{ request()->routeIs($item['route']) ? 'bg-blue text-white' : 'text-muted hover:bg-panel hover:text-ink' }}">
                    <span>{{ __($item['label']) }}</span>
                    @if (isset($item['badge']))
                        <span class="rounded-full bg-blue/10 px-2 py-0.5 text-[10px] font-bold text-blue">{{ __('navigation.soon') }}</span>
                    @endif
                </a>
            @endforeach
        </nav>
    </div>
</aside>
