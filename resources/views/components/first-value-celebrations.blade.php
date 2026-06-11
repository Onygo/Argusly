@props([
    'items' => collect(),
])

@php($celebrations = collect($items)->values())

@if ($celebrations->isNotEmpty())
    <section {{ $attributes->merge(['class' => 'rounded-lg border border-border bg-surface p-4']) }} aria-label="{{ __('app.runtime.First value milestones') }}">
        <div class="flex flex-wrap gap-3">
            @foreach ($celebrations as $item)
                <div class="flex min-w-64 flex-1 items-start gap-3 rounded-md border border-emerald-200 bg-emerald-50/70 px-3 py-3">
                    <span class="mt-0.5 inline-flex h-6 w-6 shrink-0 items-center justify-center rounded-full border border-emerald-300 bg-white text-sm font-semibold text-emerald-700">✓</span>
                    <span>
                        <span class="block text-sm font-semibold text-textPrimary">{{ $item['title'] ?? __('app.runtime.First value reached') }}</span>
                        <span class="mt-0.5 block text-xs leading-5 text-textSecondary">{{ $item['description'] ?? '' }}</span>
                    </span>
                </div>
            @endforeach
        </div>
    </section>
@endif
