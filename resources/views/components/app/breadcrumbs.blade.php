@php
    $trail = [];
    $activeItem = null;
    $activeChild = null;

    foreach (config('navigation.app', []) as $group) {
        foreach ($group['items'] ?? [] as $item) {
            $patterns = $item['active'] ?? [$item['route'] ?? ''];
            if (request()->routeIs(...$patterns)) {
                $activeItem = $item;
            }

            foreach ($item['children'] ?? [] as $child) {
                if (request()->routeIs(...($child['active'] ?? [$child['route'] ?? '']))) {
                    $activeItem = $item;
                    $activeChild = $child;
                }
            }
        }
    }

    if ($activeItem) {
        $trail[] = ['label' => $activeItem['label'], 'route' => $activeItem['route'] ?? null];
    }

    if ($activeChild && ($activeChild['label'] ?? null) !== ($activeItem['label'] ?? null)) {
        $trail[] = ['label' => $activeChild['label'], 'route' => $activeChild['route'] ?? null];
    }
@endphp

@if ($trail)
    <nav aria-label="Breadcrumb" class="flex flex-wrap items-center gap-2 text-xs font-semibold text-muted">
        @foreach ($trail as $crumb)
            @if (! $loop->first)
                <span class="text-slate-300">/</span>
            @endif
            @if (($crumb['route'] ?? null) && Route::has($crumb['route']) && ! $loop->last)
                <a href="{{ route($crumb['route']) }}" class="hover:text-ink">{{ $crumb['label'] }}</a>
            @else
                <span class="{{ $loop->last ? 'text-ink' : '' }}">{{ $crumb['label'] }}</span>
            @endif
        @endforeach
    </nav>
@endif
