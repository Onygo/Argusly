@props([
    'card',
])

@if (! empty($card))
    @php
        $action = $card['action'] ?? null;
        $facts = collect($card['facts'] ?? [])->filter(fn ($value): bool => filled($value));
    @endphp

    <section {{ $attributes->merge(['class' => 'rounded-lg border border-emerald-200 bg-emerald-50/70 p-5']) }}>
        <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
            <div class="max-w-3xl">
                <p class="text-xs font-semibold uppercase tracking-wide text-emerald-700">{{ $card['type'] ?? __('app.runtime.First value') }}</p>
                <h2 class="mt-2 text-xl font-semibold text-textPrimary">{{ $card['title'] ?? __('app.runtime.First value found') }}</h2>
            </div>
            @if ($action?->route)
                <a href="{{ $action->route }}" class="inline-flex h-9 shrink-0 items-center justify-center rounded-md bg-primary px-3 text-sm font-semibold text-white hover:bg-primaryHover">
                    {{ $action->title }}
                </a>
            @endif
        </div>

        <div class="mt-5 grid gap-3 lg:grid-cols-4">
            <div class="rounded-md border border-emerald-200 bg-white/80 p-4">
                <h3 class="text-sm font-semibold text-textPrimary">{{ __('app.runtime.What happened?') }}</h3>
                <p class="mt-1 text-xs leading-5 text-textSecondary">{{ $card['what_happened'] ?? '' }}</p>
            </div>
            <div class="rounded-md border border-emerald-200 bg-white/80 p-4">
                <h3 class="text-sm font-semibold text-textPrimary">{{ __('app.runtime.Why was this detected?') }}</h3>
                <p class="mt-1 text-xs leading-5 text-textSecondary">{{ $card['why_detected'] ?? '' }}</p>
            </div>
            <div class="rounded-md border border-emerald-200 bg-white/80 p-4">
                <h3 class="text-sm font-semibold text-textPrimary">{{ __('app.runtime.What should I do next?') }}</h3>
                <p class="mt-1 text-xs leading-5 text-textSecondary">{{ $card['next_step'] ?? '' }}</p>
            </div>
            <div class="rounded-md border border-emerald-200 bg-white/80 p-4">
                <h3 class="text-sm font-semibold text-textPrimary">{{ __('app.runtime.Expected value') }}</h3>
                <p class="mt-1 text-xs leading-5 text-textSecondary">{{ $card['expected_value'] ?? '' }}</p>
            </div>
        </div>

        @if ($facts->isNotEmpty())
            <dl class="mt-4 grid gap-2 md:grid-cols-4">
                @foreach ($facts as $label => $value)
                    <div class="rounded-md border border-emerald-200 bg-white/70 px-3 py-2">
                        <dt class="text-[11px] font-semibold uppercase tracking-wide text-textMuted">{{ $label }}</dt>
                        <dd class="mt-1 truncate text-sm font-medium text-textPrimary">{{ $value }}</dd>
                    </div>
                @endforeach
            </dl>
        @endif
    </section>
@endif
