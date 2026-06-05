@props([
    'sites' => collect(),
    'selected' => null,
    'baseFilters' => [],
])

@php
    $allActive = $selected === null || $selected === '';
    $filterParams = collect($baseFilters)->except(['site', 'page'])->filter(fn ($v) => $v !== null && $v !== '')->all();
@endphp

<div
    class="inline-flex max-w-full items-center gap-1 overflow-x-auto rounded-lg bg-surfaceSubtle p-1"
    data-site-tabs
    aria-label="Site navigation"
>
    <a
        href="{{ route('app.content.index', $filterParams) }}"
        class="shrink-0 rounded-md px-3 py-1.5 text-sm font-medium transition {{ $allActive ? 'bg-surface text-textPrimary shadow-sm ring-1 ring-border/70' : 'text-textSecondary hover:bg-surface/70 hover:text-textPrimary' }}"
        data-site-tab="all"
        @if ($allActive) aria-current="true" @endif
    >
        All
    </a>
    @foreach ($sites as $site)
        @php
            $isActive = (string) $selected === (string) $site->id;
            $tabParams = array_merge($filterParams, ['site' => $site->id]);
        @endphp
        <a
            href="{{ route('app.content.index', $tabParams) }}"
            class="shrink-0 rounded-md px-3 py-1.5 text-sm font-medium transition {{ $isActive ? 'bg-surface text-textPrimary shadow-sm ring-1 ring-border/70' : 'text-textSecondary hover:bg-surface/70 hover:text-textPrimary' }}"
            data-site-tab="{{ $site->id }}"
            @if ($isActive) aria-current="true" @endif
        >
            {{ $site->name }}
        </a>
    @endforeach
</div>
