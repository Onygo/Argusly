@props(['items' => []])

<nav class="rounded-lg border border-border bg-surface p-3" data-section-nav>
    <div class="flex flex-wrap items-center gap-3">
        @foreach ($items as $item)
            @php
                $active = (bool) ($item['active'] ?? false);
                $id = (string) ($item['id'] ?? '');
            @endphp
            <a
                href="{{ $item['url'] }}"
                class="rounded-md px-3 py-1.5 text-sm transition {{ $active ? 'bg-primary text-textInverse' : 'text-textSecondary hover:bg-surfaceSubtle hover:text-textPrimary' }}"
                data-section-nav-item="{{ $id }}"
                @if ($active) aria-current="page" @endif
            >
                {{ $item['label'] }}
            </a>
        @endforeach
    </div>
</nav>
