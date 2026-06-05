<aside class="md:sticky md:top-24">
    <div class="md:hidden">
        <label for="legal-page-picker" class="mb-2 block text-xs font-medium uppercase tracking-wide text-textMuted">{{ __('public.nav.legal') }}</label>
        <select
            id="legal-page-picker"
            class="w-full rounded-md border border-border bg-surface px-3 py-2 text-sm text-textPrimary"
            onchange="if (this.value) window.location.href = this.value;"
        >
            @foreach($items as $item)
                <option value="{{ $item['url'] }}" @selected($activeLegal === $item['key'])>{{ $item['label'] }}</option>
            @endforeach
        </select>
    </div>

    <nav class="hidden rounded-md border border-border bg-surface p-3 md:block" aria-label="Legal navigation">
        <ul class="space-y-1 text-sm">
            @foreach($items as $item)
                <li>
                    <a
                        href="{{ $item['url'] }}"
                        class="block rounded px-3 py-2 {{ $activeLegal === $item['key'] ? 'bg-surfaceMuted text-textPrimary' : 'text-textSecondary hover:bg-surfaceSubtle hover:text-textPrimary' }}"
                        @if($activeLegal === $item['key']) aria-current="page" @endif
                    >
                        {{ $item['label'] }}
                    </a>
                </li>
            @endforeach
        </ul>
    </nav>
</aside>
