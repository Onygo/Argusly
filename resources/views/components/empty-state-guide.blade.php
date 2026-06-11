@props([
    'state' => [],
    'setupUrl' => null,
])

@php
    $result = $state['result'] ?? null;
    $missing = $state['missing_requirements'] ?? [];
    $actions = $state['actions'] ?? [];
@endphp

@if ($result)
    <section {{ $attributes->merge(['class' => 'rounded-md border border-border bg-surface p-5']) }}>
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div class="min-w-0">
                <div class="inline-flex h-9 w-9 items-center justify-center rounded-md bg-primarySoftBg text-primary">
                    <i data-lucide="route" class="h-4 w-4"></i>
                </div>
                <h2 class="mt-3 text-base font-semibold text-textPrimary">{{ $state['title'] ?? ($result->label.' needs setup') }}</h2>
                <p class="mt-1 max-w-3xl text-sm leading-6 text-textSecondary">{{ $state['message'] ?? 'Complete setup before this module can produce useful results.' }}</p>
            </div>
            <x-readiness-progress class="w-full sm:w-56" :value="$result->progress" />
        </div>

        @if (! empty($missing))
            <div class="mt-5">
                <x-setup-checklist :requirements="$missing" />
            </div>
        @endif

        <div class="mt-5 flex flex-wrap gap-2">
            @if ($setupUrl)
                <a href="{{ $setupUrl }}" class="inline-flex h-9 items-center gap-2 rounded-md bg-primary px-3 text-sm font-semibold text-white hover:bg-primaryHover">
                    <i data-lucide="list-checks" class="h-4 w-4"></i>
                    Open Setup
                </a>
            @endif
            @foreach ($actions as $action)
                @if ($action->route)
                    <a href="{{ $action->route }}" class="inline-flex h-9 items-center rounded-md border border-border bg-surface px-3 text-sm font-medium text-textPrimary hover:bg-surfaceMuted">{{ $action->label }}</a>
                @endif
            @endforeach
        </div>
    </section>
@endif
