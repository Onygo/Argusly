@if ($journey)
    @php
        $steps = $journey['steps'];
        $currentStage = $journey['current_stage'];
        $nextStage = $journey['next_stage'];
        $action = $journey['recommended_action'];
        $statusMeta = [
            'locked' => ['symbol' => '○', 'class' => 'border-border bg-surfaceMuted text-textMuted', 'label' => __('app.runtime.Locked')],
            'available' => ['symbol' => '○', 'class' => 'border-border bg-white text-textSecondary', 'label' => __('app.runtime.Available')],
            'active' => ['symbol' => '◐', 'class' => 'border-primary/30 bg-primary/10 text-primary', 'label' => __('app.runtime.Active')],
            'completed' => ['symbol' => '✓', 'class' => 'border-emerald-200 bg-emerald-50 text-emerald-700', 'label' => __('app.runtime.Completed')],
        ];
    @endphp

    <section class="mb-6 rounded-lg border border-border bg-surface p-4" aria-label="{{ __('app.runtime.Unified Intelligence Journey') }}">
        <div class="flex flex-col gap-4 xl:flex-row xl:items-start xl:justify-between">
            <div class="min-w-0">
                <div class="flex flex-wrap items-center gap-2">
                    <p class="text-xs font-semibold uppercase tracking-wide text-textFaint">{{ __('app.runtime.Unified Intelligence Journey') }}</p>
                    <span class="rounded-full border border-border bg-background px-2 py-0.5 text-xs text-textSecondary">
                        {{ __('app.runtime.Time to value: :time', ['time' => $journey['estimated_time_to_value']]) }}
                    </span>
                </div>
                <div class="mt-2 flex flex-wrap gap-3 text-sm">
                    <div>
                        <span class="text-textMuted">{{ __('app.runtime.Current Stage') }}</span>
                        <span class="ml-1 font-semibold text-textPrimary">{{ $currentStage?->label ?? __('app.runtime.Complete') }}</span>
                    </div>
                    <div>
                        <span class="text-textMuted">{{ __('app.runtime.Next Stage') }}</span>
                        <span class="ml-1 font-semibold text-textPrimary">{{ $nextStage?->label ?? __('app.runtime.Monitor new signals') }}</span>
                    </div>
                </div>
            </div>

            <div class="flex max-w-xl flex-col gap-2 rounded-md border border-border bg-background p-3 sm:flex-row sm:items-center sm:justify-between">
                <div class="min-w-0">
                    <p class="text-sm font-semibold text-textPrimary">{{ $action->title }}</p>
                    <p class="mt-0.5 text-xs text-textSecondary">{{ $action->description }}</p>
                </div>
                @if ($action->route)
                    <a href="{{ $action->route }}" class="inline-flex h-9 shrink-0 items-center justify-center rounded-md bg-primary px-3 text-sm font-semibold text-white transition hover:bg-primary/90">
                        {{ __('app.runtime.Continue') }}
                    </a>
                @endif
            </div>
        </div>

        <div class="mt-4 grid gap-2 md:grid-cols-2 xl:grid-cols-4">
            @foreach ($steps as $step)
                @php($meta = $statusMeta[$step->status] ?? $statusMeta['locked'])
                <a
                    href="{{ $step->route ?: '#' }}"
                    title="{{ $step->tooltip }}"
                    class="group flex min-h-20 gap-3 rounded-md border p-3 transition {{ $meta['class'] }} {{ $step->route ? 'hover:border-primary/40 hover:bg-surfaceSubtle' : 'cursor-default' }}"
                    aria-label="{{ $step->label }}: {{ $meta['label'] }}"
                >
                    <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full border border-current text-sm font-bold">
                        {{ $meta['symbol'] }}
                    </span>
                    <span class="min-w-0">
                        <span class="block text-[11px] font-semibold uppercase tracking-wide opacity-70">{{ __('app.runtime.Step :number', ['number' => $step->number]) }}</span>
                        <span class="block truncate text-sm font-semibold">{{ $step->label }}</span>
                        <span class="mt-1 block text-xs leading-snug opacity-80">
                            {{ $step->blockingMessage ?: $meta['label'] }}
                        </span>
                    </span>
                </a>
            @endforeach
        </div>
    </section>
@endif
