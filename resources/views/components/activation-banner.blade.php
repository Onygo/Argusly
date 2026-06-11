@props([
    'activation',
    'compact' => false,
])

@php
    $remaining = (int) data_get($activation, 'remaining_banner_steps', 0);
    $next = data_get($activation, 'next_action');
    $steps = collect(data_get($activation, 'banner_steps', []));
    $score = (int) data_get($activation, 'score', 0);
@endphp

@if (! data_get($activation, 'is_active'))
    <section {{ $attributes->merge(['class' => 'rounded-md border border-primary/25 bg-primarySoftBg/70 p-5']) }}>
        <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
            <div class="min-w-0">
                <p class="text-xs font-semibold uppercase tracking-wide text-primary">First Value Activation</p>
                <h2 class="mt-2 text-lg font-semibold text-textPrimary">
                    @if ($remaining > 0)
                        Nog {{ $remaining }} {{ $remaining === 1 ? 'stap' : 'stappen' }} tot je eerste signal
                    @else
                        Je eerste signal flow is bijna klaar
                    @endif
                </h2>
                <p class="mt-1 max-w-3xl text-sm leading-6 text-textSecondary">
                    Maak eerst AI Visibility-data aan, daarna kan Signal Intelligence detections en opportunity candidates tonen.
                </p>
            </div>
            <div class="flex shrink-0 flex-wrap items-center gap-2">
                @if ($next && data_get($next, 'action_route'))
                    <a href="{{ data_get($next, 'action_route') }}" class="inline-flex h-9 items-center gap-2 rounded-md bg-primary px-3 text-sm font-semibold text-white hover:bg-primaryHover">
                        <i data-lucide="arrow-right" class="h-4 w-4"></i>
                        {{ data_get($next, 'action_label') }}
                    </a>
                @endif
                <a href="{{ route('app.activation.index', ['workspace' => data_get($activation, 'workspace.id')]) }}" class="inline-flex h-9 items-center gap-2 rounded-md border border-border bg-surface px-3 text-sm font-medium text-textPrimary hover:bg-surfaceMuted">
                    <i data-lucide="list-checks" class="h-4 w-4"></i>
                    Open Activation
                </a>
            </div>
        </div>

        <div class="mt-5 grid gap-3 md:grid-cols-5">
            @foreach ($steps as $step)
                <div class="rounded-md border {{ data_get($step, 'completed') ? 'border-success/25 bg-white/80' : 'border-amber-200 bg-white/70' }} px-3 py-3">
                    <div class="flex items-start gap-2">
                        <span class="mt-0.5 inline-flex h-5 w-5 shrink-0 items-center justify-center rounded-full {{ data_get($step, 'completed') ? 'bg-successSoft text-success' : 'bg-amber-50 text-amber-700' }}">
                            <i data-lucide="{{ data_get($step, 'completed') ? 'check' : 'circle-alert' }}" class="h-3.5 w-3.5"></i>
                        </span>
                        <div class="min-w-0">
                            <p class="text-sm font-medium text-textPrimary">{{ data_get($step, 'label') }}</p>
                            @unless ($compact)
                                <p class="mt-1 text-xs leading-5 text-textSecondary">{{ data_get($step, 'completed') ? 'Ready' : data_get($step, 'action_label') }}</p>
                            @endunless
                        </div>
                    </div>
                </div>
            @endforeach
        </div>

        @unless ($compact)
            <div class="mt-4">
                <x-readiness-progress :value="$score" label="First Value Score" />
            </div>
        @endunless
    </section>
@endif
