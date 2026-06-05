@props([
    'items' => [],
    'active' => null,
])

<div class="space-y-4">
    <div class="md:hidden">
        <label for="legal-page-picker" class="mb-2 block text-xs font-medium uppercase tracking-wide text-textMuted">{{ __('public.nav.legal') }}</label>
        <select
            id="legal-page-picker"
            data-legal-nav-select
            class="w-full rounded-md border border-border bg-surfaceMuted px-3 py-2 text-sm text-textPrimary"
            aria-label="{{ __('public.nav.legal') }}"
        >
            @foreach($items as $item)
                <option value="{{ $item['url'] }}" @selected($active === $item['key'])>{{ $item['label'] }}</option>
            @endforeach
        </select>
    </div>

    <nav class="hidden md:block" aria-label="Legal navigation">
        <ul class="space-y-1 text-sm">
            @foreach($items as $item)
                @php($isActive = $active === $item['key'])
                <li>
                    <a
                        href="{{ $item['url'] }}"
                        class="block border-l-2 px-3 py-2 transition-colors {{ $isActive ? 'border-l-primary bg-surfaceSubtle text-textPrimary' : 'border-l-transparent text-textSecondary hover:bg-surfaceSubtle hover:text-textPrimary' }}"
                        @if($isActive) aria-current="page" @endif
                    >
                        {{ $item['label'] }}
                    </a>
                </li>
            @endforeach
        </ul>
    </nav>
</div>
