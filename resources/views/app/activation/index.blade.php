@extends('layouts.app', ['title' => 'Activation'])

@section('content')
    @php
        $rt = function (string $key, array $replace = []): string {
            $line = (__('app.runtime')[$key] ?? $key);

            foreach ($replace as $name => $value) {
                $line = str_replace(':'.$name, (string) $value, $line);
            }

            return $line;
        };
    @endphp

    <div class="space-y-6">
        <div class="flex flex-col gap-4 md:flex-row md:items-start md:justify-between">
            <div>
                <h1 class="text-2xl font-semibold tracking-tight text-textPrimary">First Value Activation</h1>
                <p class="mt-1 max-w-3xl text-sm leading-6 text-textSecondary">
                    Breng deze workspace naar de eerste AI Visibility run, het eerste Signal Event, de eerste Detection en de eerste Opportunity candidate.
                </p>
            </div>
            <form method="GET" action="{{ route('app.activation.index') }}">
                <select name="workspace" class="pl-work-select" onchange="this.form.submit()">
                    @foreach ($workspaces as $option)
                        <option value="{{ $option->id }}" @selected((string) $workspace->id === (string) $option->id)>{{ $option->display_name ?: $option->name }}</option>
                    @endforeach
                </select>
            </form>
        </div>

        <x-activation-banner :activation="[
            'workspace' => $workspace,
            'score' => $score,
            'is_active' => $is_active,
            'banner_steps' => $banner_steps,
            'next_action' => $next_action,
            'remaining_banner_steps' => $remaining_banner_steps,
        ]" />

        @if ($is_active)
            <section class="rounded-md border border-success/25 bg-successSoft p-5">
                <div class="flex items-start gap-3">
                    <span class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-md bg-white text-success">
                        <i data-lucide="check" class="h-4 w-4"></i>
                    </span>
                    <div>
                        <h2 class="text-base font-semibold text-textPrimary">First value is active</h2>
                        <p class="mt-1 text-sm text-textSecondary">Deze workspace heeft de eerste AI Visibility en Signal Intelligence feedback loop bereikt.</p>
                    </div>
                </div>
            </section>
        @endif

        @if ((int) data_get($counts, 'opportunity_candidates') > 0)
            <section class="rounded-md border border-emerald-200 bg-emerald-50/80 p-5">
                <div class="flex flex-col gap-4 md:flex-row md:items-start md:justify-between">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wide text-emerald-700">{{ __('app.runtime.Opportunity Review unlocked') }}</p>
                        <h2 class="mt-2 text-lg font-semibold text-textPrimary">{{ __('app.runtime.First Opportunity Candidate Detected') }}</h2>
                        <p class="mt-1 text-sm text-textSecondary">{{ $rt('Argusly found a potential growth opportunity.') }}</p>
                    </div>
                    <a href="{{ route('app.opportunity-review.index', ['workspace' => $workspace->id]) }}" class="inline-flex h-9 shrink-0 items-center justify-center gap-2 rounded-md bg-primary px-3 text-sm font-semibold text-white hover:bg-primaryHover">
                        <i data-lucide="eye" class="h-4 w-4"></i>
                        {{ __('app.runtime.Review Opportunity') }}
                    </a>
                </div>
            </section>
        @endif

        <section class="grid gap-5 lg:grid-cols-[minmax(0,1fr)_22rem]">
            <div class="rounded-md border border-border bg-surface p-5">
                <div class="flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <h2 class="text-base font-semibold text-textPrimary">First Value checklist</h2>
                        <p class="mt-1 text-sm text-textSecondary">Volg deze stappen op volgorde. Elke stap gebruikt bestaande Argusly-schermen.</p>
                    </div>
                    <div class="text-right">
                        <p class="text-xs font-medium uppercase tracking-wide text-textFaint">First Value Score</p>
                        <p class="mt-1 text-3xl font-semibold text-textPrimary">{{ $score }}%</p>
                    </div>
                </div>

                <div class="mt-5 space-y-3">
                    @foreach ($steps as $step)
                        <article class="rounded-md border border-border bg-background p-4">
                            <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                <div class="flex items-start gap-3">
                                    <span class="mt-0.5 inline-flex h-6 w-6 shrink-0 items-center justify-center rounded-full {{ $step['completed'] ? 'bg-successSoft text-success' : 'bg-amber-50 text-amber-700' }}">
                                        <i data-lucide="{{ $step['completed'] ? 'check' : 'circle-alert' }}" class="h-4 w-4"></i>
                                    </span>
                                    <div>
                                        <h3 class="text-sm font-semibold text-textPrimary">{{ $step['label'] }}</h3>
                                        <p class="mt-1 text-sm leading-6 text-textSecondary">{{ $step['description'] }}</p>
                                    </div>
                                </div>
                                @if (! $step['completed'] && $step['action_route'])
                                    <a href="{{ $step['action_route'] }}" class="inline-flex h-9 shrink-0 items-center gap-2 rounded-md border border-border bg-surface px-3 text-sm font-medium text-textPrimary hover:bg-surfaceMuted">
                                        {{ $step['action_label'] }}
                                        <i data-lucide="arrow-up-right" class="h-3.5 w-3.5"></i>
                                    </a>
                                @endif
                            </div>
                        </article>
                    @endforeach
                </div>
            </div>

            <aside class="space-y-5">
                <div class="rounded-md border border-border bg-surface p-5">
                    <p class="text-xs font-medium uppercase tracking-wide text-textFaint">Volgende beste actie</p>
                    @if ($next_action)
                        <h2 class="mt-2 text-base font-semibold text-textPrimary">{{ data_get($next_action, 'label') }}</h2>
                        <p class="mt-1 text-sm leading-6 text-textSecondary">{{ data_get($next_action, 'description') }}</p>
                        @if (data_get($next_action, 'action_route'))
                            <a href="{{ data_get($next_action, 'action_route') }}" class="mt-4 inline-flex h-9 items-center gap-2 rounded-md bg-primary px-3 text-sm font-semibold text-white hover:bg-primaryHover">
                                {{ data_get($next_action, 'action_label') }}
                                <i data-lucide="arrow-right" class="h-4 w-4"></i>
                            </a>
                        @endif
                    @else
                        <h2 class="mt-2 text-base font-semibold text-textPrimary">Review Signal Intelligence</h2>
                        <p class="mt-1 text-sm text-textSecondary">First value is bereikt. Gebruik detections om opportunities te beoordelen.</p>
                    @endif
                </div>

                <div class="rounded-md border border-border bg-surface p-5">
                    <h2 class="text-base font-semibold text-textPrimary">Quick actions</h2>
                    <div class="mt-4 space-y-2">
                        @foreach ($quick_actions as $action)
                            <a href="{{ $action['route'] }}" class="flex items-center justify-between rounded-md border border-border bg-background px-3 py-2 text-sm font-medium text-textPrimary hover:bg-surfaceMuted">
                                <span>{{ $action['label'] }}</span>
                                <i data-lucide="arrow-up-right" class="h-3.5 w-3.5"></i>
                            </a>
                        @endforeach
                    </div>
                </div>

                <div class="rounded-md border border-border bg-surface p-5">
                    <h2 class="text-base font-semibold text-textPrimary">First value counters</h2>
                    <dl class="mt-4 space-y-3 text-sm">
                        <div class="flex justify-between gap-3"><dt class="text-textSecondary">AI queries</dt><dd class="font-medium text-textPrimary">{{ number_format((int) data_get($counts, 'queries')) }}</dd></div>
                        <div class="flex justify-between gap-3"><dt class="text-textSecondary">AI runs</dt><dd class="font-medium text-textPrimary">{{ number_format((int) data_get($counts, 'runs')) }}</dd></div>
                        <div class="flex justify-between gap-3"><dt class="text-textSecondary">Signal Events</dt><dd class="font-medium text-textPrimary">{{ number_format((int) data_get($counts, 'signal_events')) }}</dd></div>
                        <div class="flex justify-between gap-3"><dt class="text-textSecondary">Detections</dt><dd class="font-medium text-textPrimary">{{ number_format((int) data_get($counts, 'detections')) }}</dd></div>
                        <div class="flex justify-between gap-3"><dt class="text-textSecondary">Opportunity candidates</dt><dd class="font-medium text-textPrimary">{{ number_format((int) data_get($counts, 'opportunity_candidates')) }}</dd></div>
                    </dl>
                </div>
            </aside>
        </section>
    </div>
@endsection
