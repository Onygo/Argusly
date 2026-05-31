@php
    $account = current_account();
    $user = auth()->user();
    $moduleAccess = app(\App\Services\Subscriptions\ModuleAccessService::class);
    $items = [
        ['label' => 'settings.account', 'route' => 'settings.account'],
        ['label' => 'settings.brands', 'route' => 'settings.brands'],
        ['label' => 'settings.team', 'route' => 'settings.team'],
        ['label' => 'settings.modules', 'route' => 'settings.modules'],
        ['label' => 'settings.integrations', 'route' => 'settings.integrations'],
        ['label' => 'settings.social_profiles', 'route' => 'settings.social-profiles'],
        ['label' => 'settings.email_providers', 'route' => 'settings.email-providers'],
        ['label' => 'settings.connectors', 'route' => 'settings.connectors', 'modules' => ['connectors']],
        ['label' => 'settings.knowledge_graph', 'route' => 'settings.knowledge-graph'],
        ['label' => 'settings.properties', 'route' => 'settings.properties', 'modules' => ['content']],
        ['label' => 'settings.channels', 'route' => 'settings.channels', 'modules' => ['content']],
    ];

    $items = collect($items)->filter(fn (array $item) => ! isset($item['modules']) || ($account && $user && $moduleAccess->accountHasAnyModule($account, $item['modules'])));
@endphp

<div class="flex gap-2 overflow-x-auto border-b border-line pb-3">
    @foreach ($items as $item)
        <a href="{{ route($item['route']) }}" class="shrink-0 rounded-full px-3 py-2 text-sm font-semibold {{ request()->routeIs($item['route']) ? 'bg-ink text-white' : 'text-muted hover:bg-white hover:text-ink' }}">
            {{ __($item['label']) }}
        </a>
    @endforeach
</div>
