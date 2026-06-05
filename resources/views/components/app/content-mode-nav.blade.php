@props(['active' => 'sites'])

@php
    $modes = [
        [
            'id' => 'sites',
            'label' => 'Sites',
            'url' => route('app.content.index'),
        ],
        [
            'id' => 'automations',
            'label' => 'Automations',
            'url' => route('app.content.automations.index'),
        ],
        [
            'id' => 'chains',
            'label' => 'Chains',
            'url' => route('app.content.series.index'),
        ],
    ];
@endphp

<nav class="inline-flex items-center gap-1 rounded-lg bg-surfaceSubtle p-1" aria-label="Content modes">
    @foreach ($modes as $mode)
        @php
            $isActive = $active === $mode['id'];
        @endphp
        <a
            href="{{ $mode['url'] }}"
            class="rounded-md px-4 py-2 text-sm font-semibold transition {{ $isActive ? 'bg-surface text-textPrimary shadow-sm ring-1 ring-border/80' : 'text-textSecondary hover:bg-surface/70 hover:text-textPrimary' }}"
            @if ($isActive) aria-current="page" @endif
        >
            {{ $mode['label'] }}
        </a>
    @endforeach
</nav>
