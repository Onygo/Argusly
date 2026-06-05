{{-- Calendar Toolbar --}}
@php
    $showWeekNumbers = (bool) ($showWeekNumbers ?? false);
@endphp
<div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
    {{-- Left: Navigation and Period --}}
    <div class="flex flex-wrap items-center gap-3">
        {{-- View Toggle: Maand | Week | Dag --}}
        <div class="inline-flex rounded-lg border border-border bg-surface p-0.5" data-calendar-mode-switch>
            <a
                href="{{ $monthUrl }}"
                data-calendar-mode-link
                data-calendar-mode-value="month"
                @class([
                    'rounded-md px-3 py-1.5 text-sm font-medium transition-colors',
                    'bg-primary text-textInverse' => $isMonthView,
                    'text-textSecondary hover:text-textPrimary hover:bg-surfaceSubtle' => !$isMonthView,
                ])
            >
                Maand
            </a>
            <a
                href="{{ $weekUrl }}"
                data-calendar-mode-link
                data-calendar-mode-value="week"
                @class([
                    'rounded-md px-3 py-1.5 text-sm font-medium transition-colors',
                    'bg-primary text-textInverse' => $isWeekView,
                    'text-textSecondary hover:text-textPrimary hover:bg-surfaceSubtle' => !$isWeekView,
                ])
            >
                Week
            </a>
            <a
                href="{{ $dayUrl }}"
                data-calendar-mode-link
                data-calendar-mode-value="day"
                @class([
                    'rounded-md px-3 py-1.5 text-sm font-medium transition-colors',
                    'bg-primary text-textInverse' => $isDayView,
                    'text-textSecondary hover:text-textPrimary hover:bg-surfaceSubtle' => !$isDayView,
                ])
            >
                Dag
            </a>
        </div>

        {{-- Period Navigation --}}
        <div class="flex items-center gap-1">
            <a
                href="{{ $prevUrl }}"
                class="flex h-8 w-8 items-center justify-center rounded-lg border border-border bg-surface text-textSecondary transition-colors hover:bg-surfaceSubtle hover:text-textPrimary"
                aria-label="Previous {{ $mode }}"
            >
                <i data-lucide="chevron-left" class="h-4 w-4"></i>
            </a>
            <a
                href="{{ $todayUrl }}"
                class="rounded-lg border border-border bg-surface px-3 py-1.5 text-sm font-medium text-textSecondary transition-colors hover:bg-surfaceSubtle hover:text-textPrimary"
            >
                Vandaag
            </a>
            <a
                href="{{ $nextUrl }}"
                class="flex h-8 w-8 items-center justify-center rounded-lg border border-border bg-surface text-textSecondary transition-colors hover:bg-surfaceSubtle hover:text-textPrimary"
                aria-label="Next {{ $mode }}"
            >
                <i data-lucide="chevron-right" class="h-4 w-4"></i>
            </a>
        </div>

        {{-- Period Label / Mini Picker --}}
        <div class="relative">
            <button
                type="button"
                data-calendar-mini-picker-toggle
                class="inline-flex items-center gap-2 rounded-lg border border-transparent px-1.5 py-1 text-left text-lg font-semibold text-textPrimary transition-colors hover:border-border hover:bg-surfaceSubtle focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary/30"
                aria-haspopup="dialog"
                aria-expanded="false"
            >
                <i data-lucide="calendar-days" class="h-4 w-4 text-textMuted"></i>
                <span>{{ $periodLabel }}</span>
            </button>

            <div
                data-calendar-mini-picker
                data-calendar-mini-picker-anchor="{{ $anchor->format('Y-m-d') }}"
                class="absolute left-0 top-[calc(100%+0.5rem)] z-40 hidden w-[19rem] rounded-lg border border-border bg-surface p-4 shadow-2xl"
                aria-hidden="true"
            >
                <div class="mb-3 flex items-center justify-between gap-2">
                    <button
                        type="button"
                        data-calendar-mini-picker-prev
                        class="flex h-8 w-8 items-center justify-center rounded-lg border border-border bg-surfaceSubtle text-textSecondary transition-colors hover:bg-surfaceMuted hover:text-textPrimary"
                        aria-label="Vorige maand"
                    >
                        <i data-lucide="chevron-left" class="h-4 w-4"></i>
                    </button>
                    <div data-calendar-mini-picker-label class="text-sm font-semibold text-textPrimary"></div>
                    <button
                        type="button"
                        data-calendar-mini-picker-next
                        class="flex h-8 w-8 items-center justify-center rounded-lg border border-border bg-surfaceSubtle text-textSecondary transition-colors hover:bg-surfaceMuted hover:text-textPrimary"
                        aria-label="Volgende maand"
                    >
                        <i data-lucide="chevron-right" class="h-4 w-4"></i>
                    </button>
                </div>
                <div class="mb-2 grid grid-cols-7 gap-1 text-center text-[10px] font-semibold uppercase tracking-wider text-textMuted">
                    @foreach (['Ma', 'Di', 'Wo', 'Do', 'Vr', 'Za', 'Zo'] as $weekday)
                        <span>{{ $weekday }}</span>
                    @endforeach
                </div>
                <div data-calendar-mini-picker-grid class="grid grid-cols-7 gap-1"></div>
            </div>
        </div>
    </div>

    {{-- Right: Site Filter + Actions --}}
    <div class="flex flex-wrap items-center gap-3">
        {{-- Site Filter --}}
        @if ($sites->count() > 1)
            <form method="GET" class="flex items-center gap-2">
                <input type="hidden" name="mode" value="{{ $mode }}">
                <input type="hidden" name="date" value="{{ $anchor->format('Y-m-d') }}">
                @if ($selectedDate)
                    <input type="hidden" name="selected_date" value="{{ $selectedDate->format('Y-m-d') }}">
                @endif
                @if ($showWeekNumbers)
                    <input type="hidden" name="week_numbers" value="1">
                @endif
                <select
                    name="site"
                    onchange="this.form.submit()"
                    class="h-8 rounded-lg border border-border bg-surface px-3 pr-8 text-sm text-textSecondary"
                >
                    <option value="">Alle sites</option>
                    @foreach ($sites as $site)
                        <option value="{{ $site->id }}" @selected($selectedSiteId === (string) $site->id)>{{ $site->name }}</option>
                    @endforeach
                </select>
            </form>
        @endif

        @if ($isMonthView)
            <a
                href="{{ $weekNumberToggleUrl }}"
                class="flex h-8 items-center gap-1.5 rounded-lg border border-border bg-surface px-3 text-sm text-textSecondary transition-colors hover:bg-surfaceSubtle hover:text-textPrimary"
            >
                <i data-lucide="hash" class="h-3.5 w-3.5"></i>
                <span class="hidden sm:inline">{{ $showWeekNumbers ? 'Weeknummers uit' : 'Weeknummers aan' }}</span>
                <span class="sm:hidden">Wk</span>
            </a>
        @endif

        {{-- Actions --}}
        <a
            href="{{ route('app.content.index') }}"
            class="flex h-8 items-center gap-1.5 rounded-lg border border-border bg-surface px-3 text-sm text-textSecondary transition-colors hover:bg-surfaceSubtle hover:text-textPrimary"
        >
            <i data-lucide="list" class="h-3.5 w-3.5"></i>
            <span class="hidden sm:inline">Lijst</span>
        </a>
    </div>
</div>
