@extends('layouts.app', ['title' => 'Content planning'])

@section('content')
    @php
        $showWeekNumbers = (bool) ($showWeekNumbers ?? false);
        $isDayView = $mode === 'day';
        $isWeekView = $mode === 'week';
        $isMonthView = $mode === 'month';
        $activeDate = $selectedDate ?? $anchor;

        // Build navigation URLs
        $prevDate = $mode === 'day'
            ? $activeDate->copy()->subDay()->format('Y-m-d')
            : ($mode === 'week'
                ? $anchor->copy()->subWeek()->format('Y-m-d')
                : $anchor->copy()->subMonth()->format('Y-m-d'));
        $nextDate = $mode === 'day'
            ? $activeDate->copy()->addDay()->format('Y-m-d')
            : ($mode === 'week'
                ? $anchor->copy()->addWeek()->format('Y-m-d')
                : $anchor->copy()->addMonth()->format('Y-m-d'));
        $todayDate = now()->format('Y-m-d');

        $baseParams = $selectedSiteId ? ['site' => $selectedSiteId] : [];
        if ($showWeekNumbers) {
            $baseParams['week_numbers'] = 1;
        }
        $modeTargetDate = $activeDate->format('Y-m-d');
        $selectedDateParam = $selectedDate?->format('Y-m-d');

        $prevUrl = route('app.content.calendar', array_merge($baseParams, ['mode' => $mode, 'date' => $prevDate], $selectedDateParam ? ['selected_date' => $prevDate] : []));
        $nextUrl = route('app.content.calendar', array_merge($baseParams, ['mode' => $mode, 'date' => $nextDate], $selectedDateParam ? ['selected_date' => $nextDate] : []));
        $todayUrl = route('app.content.calendar', array_merge($baseParams, ['mode' => $mode, 'date' => $todayDate], $selectedDateParam !== null || $isDayView ? ['selected_date' => $todayDate] : []));
        $monthUrl = route('app.content.calendar', array_merge($baseParams, ['mode' => 'month', 'date' => $modeTargetDate], $selectedDateParam ? ['selected_date' => $selectedDateParam] : []));
        $weekUrl = route('app.content.calendar', array_merge($baseParams, ['mode' => 'week', 'date' => $modeTargetDate], $selectedDateParam ? ['selected_date' => $selectedDateParam] : []));
        $dayUrl = route('app.content.calendar', array_merge($baseParams, ['mode' => 'day', 'date' => $modeTargetDate], ['selected_date' => $selectedDateParam ?? $modeTargetDate]));
        $weekNumberToggleBaseParams = $selectedSiteId ? ['site' => $selectedSiteId] : [];
        $weekNumberToggleUrl = route('app.content.calendar', array_merge(
            $weekNumberToggleBaseParams,
            ['mode' => 'month', 'date' => $anchor->format('Y-m-d')],
            $selectedDateParam ? ['selected_date' => $selectedDateParam] : [],
            $showWeekNumbers ? [] : ['week_numbers' => 1],
        ));

        // Current period label
        $periodLabel = $mode === 'day'
            ? $activeDate->translatedFormat('l j F Y')
            : ($mode === 'week'
                ? $rangeStart->format('j M') . ' – ' . $rangeEnd->format('j M Y')
                : $anchor->translatedFormat('F Y'));
    @endphp

    {{-- Page Header --}}
    <div class="mb-6">
        <h1 class="text-2xl font-semibold tracking-tight text-textPrimary">Content planning</h1>
        <p class="mt-1 text-textSecondary">Plan, beheer en publiceer je content. Alles overzichtelijk op één plek.</p>
    </div>

    {{-- Content Load Stats --}}
    @include('app.content.calendar.partials.stats')

    {{-- Main Grid: Calendar (70%) + Sidebar (30%) --}}
    <div
        class="grid gap-6 lg:grid-cols-[minmax(0,0.7fr)_minmax(0,0.3fr)]"
        data-calendar-page
        data-calendar-current-mode="{{ $mode }}"
        data-calendar-current-date="{{ $anchor->format('Y-m-d') }}"
        data-calendar-selected-date="{{ $selectedDate?->format('Y-m-d') }}"
        data-calendar-week-numbers="{{ $showWeekNumbers ? 'true' : 'false' }}"
        data-calendar-csrf="{{ csrf_token() }}"
    >
        {{-- Left: Calendar Area --}}
        <div class="min-w-0">
            {{-- Toolbar --}}
            <div class="mb-4">
                @include('app.content.calendar.partials.toolbar')
            </div>

            {{-- Calendar Grid --}}
            @if ($isDayView)
                @include('app.content.calendar.partials.day')
            @else
                @include('app.content.calendar.partials.grid')
            @endif
        </div>

        {{-- Right: Quick Planning Sidebar (hidden on mobile, shown on lg+) --}}
        <div class="hidden lg:block">
            @include('app.content.calendar.partials.sidebar')
        </div>
    </div>

    {{-- Mobile FAB --}}
    <button
        type="button"
        id="mobile-sidebar-fab"
        class="fixed bottom-6 right-6 z-30 flex h-14 w-14 items-center justify-center rounded-full bg-primary text-textInverse shadow-lg transition-transform hover:scale-105 active:scale-95 lg:hidden"
        aria-label="Plan content"
    >
        <i data-lucide="plus" class="h-6 w-6"></i>
    </button>

    {{-- Mobile Sidebar (Bottom Sheet) --}}
    <div
        id="mobile-sidebar-backdrop"
        class="pointer-events-none fixed inset-0 z-40 bg-black/40 opacity-0 backdrop-blur-[2px] transition-opacity lg:hidden"
        aria-hidden="true"
    ></div>
    <div
        id="mobile-sidebar-sheet"
        data-calendar-mobile-sheet
        class="fixed inset-x-0 bottom-0 z-50 max-h-[85vh] translate-y-full overflow-y-auto rounded-t-2xl bg-surface shadow-2xl transition-transform duration-300 ease-out lg:hidden"
    >
        {{-- Drag Handle --}}
        <div class="sticky top-0 z-10 flex justify-center bg-surface py-3">
            <div class="h-1 w-12 rounded-full bg-border"></div>
        </div>
        {{-- Close Button --}}
        <button
            type="button"
            id="mobile-sidebar-close"
            class="absolute right-4 top-3 flex h-8 w-8 items-center justify-center rounded-full text-textMuted hover:bg-surfaceMuted hover:text-textPrimary"
            aria-label="Close"
        >
            <i data-lucide="x" class="h-5 w-5"></i>
        </button>
        {{-- Sidebar Content --}}
        <div class="px-5 pb-8">
            <div class="mb-4">
                <h2 class="text-lg font-semibold text-textPrimary">Snel plannen</h2>
                <p class="mt-1 text-sm text-textSecondary">Plan nieuwe content direct vanuit de kalender.</p>
            </div>
            <form
                method="POST"
                action="{{ route('app.content.calendar.quick-plan') }}"
                class="space-y-4"
                id="calendar-quick-plan-form-mobile"
            >
                @csrf

                {{-- Title --}}
                <div>
                    <label class="mb-1 block text-xs text-textSecondary" for="qp-title-mobile">Titel</label>
                    <input
                        id="qp-title-mobile"
                        data-calendar-sidebar-title-input
                        type="text"
                        name="title"
                        class="w-full rounded-lg border border-border bg-background px-3 py-2 text-sm text-textPrimary placeholder-textMuted focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                        placeholder="Geef je content een titel"
                        required
                        maxlength="255"
                        value="{{ old('title') }}"
                    >
                </div>

                {{-- Serie --}}
                <div>
                    <label class="mb-1 block text-xs text-textSecondary" for="qp-series-mobile">Serie</label>
                    <select
                        id="qp-series-mobile"
                        name="series_id"
                        class="w-full rounded-lg border border-border bg-background px-3 py-2 text-sm text-textPrimary focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                    >
                        <option value="">Geen serie</option>
                        @foreach ($series as $s)
                            <option value="{{ $s->id }}" @selected(old('series_id') === (string) $s->id)>{{ $s->name }}</option>
                        @endforeach
                    </select>
                </div>

                {{-- Content type & Status --}}
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="mb-1 block text-xs text-textSecondary" for="qp-type-mobile">Content type</label>
                        <select
                            id="qp-type-mobile"
                            name="type"
                            class="w-full rounded-lg border border-border bg-background px-3 py-2 text-sm text-textPrimary"
                        >
                            @foreach ($contentTypes as $typeKey => $typeLabel)
                                <option value="{{ $typeKey }}" @selected(old('type', 'article') === $typeKey)>{{ $typeLabel }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs text-textSecondary" for="qp-status-mobile">Status</label>
                        <select
                            id="qp-status-mobile"
                            name="status"
                            class="w-full rounded-lg border border-border bg-background px-3 py-2 text-sm text-textPrimary"
                        >
                            <option value="brief" @selected(old('status') === 'brief')>Brief</option>
                            <option value="draft" @selected(old('status') === 'draft')>Draft</option>
                            <option value="scheduled" @selected(old('status', 'scheduled') === 'scheduled')>Gepland</option>
                        </select>
                    </div>
                </div>

                {{-- Datum & Tijd --}}
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="mb-1 block text-xs text-textSecondary" for="qp-date-mobile">Datum</label>
                        <input
                            id="qp-date-mobile"
                            data-calendar-sidebar-date-input
                            type="date"
                            name="scheduled_date"
                            class="w-full rounded-lg border border-border bg-background px-3 py-2 text-sm text-textPrimary"
                            value="{{ old('scheduled_date', $selectedDate?->format('Y-m-d') ?? now()->format('Y-m-d')) }}"
                        >
                    </div>
                    <div>
                        <label class="mb-1 block text-xs text-textSecondary" for="qp-time-mobile">Tijd</label>
                        <input
                            id="qp-time-mobile"
                            type="time"
                            name="scheduled_time"
                            class="w-full rounded-lg border border-border bg-background px-3 py-2 text-sm text-textPrimary"
                            value="{{ old('scheduled_time', '09:00') }}"
                        >
                    </div>
                </div>

                {{-- Site --}}
                @if ($sites->count() > 0)
                    <div>
                        <label class="mb-1 block text-xs text-textSecondary" for="qp-site-mobile">Site</label>
                        <select
                            id="qp-site-mobile"
                            name="site_id"
                            class="w-full rounded-lg border border-border bg-background px-3 py-2 text-sm text-textPrimary"
                        >
                            @if ($sites->count() > 1)
                                <option value="">Selecteer site</option>
                            @endif
                            @foreach ($sites as $site)
                                <option value="{{ $site->id }}" @selected($selectedSiteId === (string) $site->id || ($sites->count() === 1))>
                                    {{ $site->name }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                @endif

                {{-- Submit --}}
                <button
                    type="submit"
                    class="flex w-full items-center justify-center gap-2 rounded-lg bg-primary px-4 py-3 text-sm font-medium text-textInverse"
                >
                    <i data-lucide="calendar-plus" class="h-4 w-4"></i>
                    Plan content
                </button>
            </form>
        </div>
    </div>

    <div
        data-calendar-overflow-backdrop
        class="fixed inset-0 z-40 hidden bg-black/30 lg:hidden"
        aria-hidden="true"
    ></div>
    <section
        data-calendar-overflow-panel
        class="fixed z-50 hidden max-h-[min(70vh,32rem)] overflow-hidden rounded-lg border border-border bg-surface shadow-2xl"
        aria-hidden="true"
    >
        <div class="flex items-center justify-between border-b border-border px-4 py-3">
            <div>
                <h2 data-calendar-overflow-title class="text-sm font-semibold text-textPrimary">Dagdetails</h2>
                <p class="text-xs text-textMuted">Alle geplande items voor deze dag.</p>
            </div>
            <button
                type="button"
                data-calendar-overflow-close
                class="flex h-8 w-8 items-center justify-center rounded-lg text-textMuted transition-colors hover:bg-surfaceMuted hover:text-textPrimary"
                aria-label="Sluit dagdetails"
            >
                <i data-lucide="x" class="h-4 w-4"></i>
            </button>
        </div>
        <div data-calendar-overflow-body class="overflow-y-auto p-4"></div>
    </section>

    {{-- Selected Day Detail Sidebar (modal) --}}
    @if ($selectedDay && !$isDayView)
        <div class="pointer-events-none fixed inset-0 z-40 bg-black/40 backdrop-blur-[2px]" aria-hidden="true"></div>

        <aside
            class="fixed right-0 top-0 z-50 flex h-full w-full max-w-md flex-col border-l border-border bg-surface shadow-2xl"
            role="dialog"
            aria-modal="true"
            aria-labelledby="calendar-day-details-title"
        >
            {{-- Header --}}
            <div class="flex items-start justify-between gap-4 border-b border-border px-5 py-4">
                <div>
                    @if ($selectedDay['is_today'])
                        <span class="mb-1.5 inline-block rounded-full bg-primary/10 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wider text-primary">Vandaag</span>
                    @elseif ($selectedDay['is_past'])
                        <span class="mb-1.5 inline-block rounded-full bg-surfaceMuted px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wider text-textMuted">Verleden</span>
                    @endif
                    <h2 id="calendar-day-details-title" class="text-lg font-semibold text-textPrimary">{{ $selectedDay['full_label'] }}</h2>
                    <p class="mt-0.5 text-sm text-textMuted">
                        {{ $selectedDay['item_count'] === 0 ? 'Geen content gepland' : $selectedDay['item_count'] . ' ' . ($selectedDay['item_count'] === 1 ? 'item' : 'items') }}
                    </p>
                </div>
                <a
                    href="{{ $closeSelectedDayUrl }}"
                    class="flex h-8 w-8 items-center justify-center rounded-lg text-textMuted transition-colors hover:bg-surfaceMuted hover:text-textPrimary"
                    aria-label="Close"
                >
                    <i data-lucide="x" class="h-4 w-4"></i>
                </a>
            </div>

            {{-- Create CTA (future days only) --}}
            @if (!$selectedDay['is_past'] && $selectedDay['create_url'])
                <div class="flex items-center justify-between gap-3 border-b border-border bg-surfaceSubtle px-5 py-3">
                    <p class="text-sm text-textSecondary">{{ $selectedDay['is_today'] ? 'Plan content voor vandaag' : 'Plan content' }}</p>
                    <a
                        href="{{ $selectedDay['create_url'] }}"
                        class="inline-flex items-center gap-1.5 rounded-lg bg-primary px-3 py-1.5 text-sm font-medium text-textInverse hover:bg-primaryHover"
                    >
                        <i data-lucide="plus" class="h-3.5 w-3.5"></i>
                        <span>Nieuw</span>
                    </a>
                </div>
            @endif

            {{-- Content List --}}
            <div class="flex-1 space-y-3 overflow-y-auto px-5 py-4">
                @forelse ($selectedDay['items'] as $item)
                    <article class="rounded-lg border border-border bg-surfaceSubtle p-4">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0 flex-1">
                                <div class="flex items-center gap-2">
                                    <h3 class="truncate text-sm font-semibold text-textPrimary">{{ $item['title'] }}</h3>
                                    <x-status-badge
                                        :label="$item['status']['label']"
                                        :color="$item['status']['color']"
                                        :dot="true"
                                        size="xs"
                                    />
                                </div>
                                <p class="mt-1.5 text-xs text-textMuted">
                                    {{ $item['scheduled_at_label'] ?: 'Niet gepland' }}
                                    <span class="mx-1 text-textFaint">·</span>
                                    {{ $item['channel_label'] }}
                                    <span class="mx-1 text-textFaint">·</span>
                                    {{ $item['site_name'] }}
                                </p>
                            </div>
                        </div>

                        <div class="mt-3 flex flex-wrap items-center gap-2">
                            @foreach ($item['detail_actions'] as $action)
                                @if ($action['type'] === 'post')
                                    <form method="POST" action="{{ $action['url'] }}">
                                        @csrf
                                        <button type="submit" class="rounded-lg border border-border bg-surface px-2.5 py-1.5 text-xs font-medium text-textPrimary hover:bg-surfaceMuted">{{ $action['label'] }}</button>
                                    </form>
                                @elseif ($action['type'] === 'external')
                                    <a href="{{ $action['url'] }}" target="_blank" rel="noopener" class="inline-flex items-center gap-1 rounded-lg border border-border bg-surface px-2.5 py-1.5 text-xs font-medium text-textPrimary hover:bg-surfaceMuted">
                                        {{ $action['label'] }}
                                        <i data-lucide="external-link" class="h-3 w-3 text-textMuted"></i>
                                    </a>
                                @else
                                    <a href="{{ $action['url'] }}" class="rounded-lg border border-border bg-surface px-2.5 py-1.5 text-xs font-medium text-textPrimary hover:bg-surfaceMuted">{{ $action['label'] }}</a>
                                @endif
                            @endforeach
                        </div>

                        @if ($item['schedule_action'])
                            <form method="POST" action="{{ $item['schedule_action']['url'] }}" class="mt-3 flex flex-col gap-2 rounded-lg border border-border bg-surface p-3 sm:flex-row sm:items-end">
                                @csrf
                                <div class="min-w-0 flex-1">
                                    <label class="mb-1 block text-[10px] font-semibold uppercase tracking-wider text-textMuted" for="calendar-schedule-{{ $item['id'] }}">{{ $item['schedule_action']['label'] }}</label>
                                    <input
                                        id="calendar-schedule-{{ $item['id'] }}"
                                        type="datetime-local"
                                        name="scheduled_publish_at"
                                        value="{{ $item['scheduled_at_value'] }}"
                                        class="w-full rounded-lg border border-border bg-background px-3 py-1.5 text-sm"
                                    >
                                </div>
                                <button type="submit" class="rounded-lg bg-primary px-3 py-1.5 text-xs font-medium text-textInverse hover:bg-primaryHover">Opslaan</button>
                            </form>
                        @endif
                    </article>
                @empty
                    <div class="flex flex-1 flex-col items-center justify-center py-12 text-center">
                        <div class="flex h-12 w-12 items-center justify-center rounded-full bg-surfaceMuted text-textMuted">
                            <i data-lucide="{{ $selectedDay['is_past'] ? 'calendar-x' : 'calendar' }}" class="h-5 w-5"></i>
                        </div>
                        @if ($selectedDay['is_past'])
                            <p class="mt-4 text-sm font-medium text-textSecondary">Geen content op deze dag</p>
                            <p class="mt-1 max-w-xs text-xs text-textMuted">Er was niets gepland of gepubliceerd.</p>
                        @else
                            <p class="mt-4 text-sm font-medium text-textSecondary">Geen content gepland</p>
                            <p class="mt-1 max-w-xs text-xs text-textMuted">Begin met het plannen van content voor deze dag.</p>
                            @if ($selectedDay['create_url'])
                                <a href="{{ $selectedDay['create_url'] }}" class="mt-4 inline-flex items-center gap-1.5 rounded-lg bg-primary px-4 py-2 text-sm font-medium text-textInverse hover:bg-primaryHover">
                                    <i data-lucide="plus" class="h-3.5 w-3.5"></i>
                                    <span>Plan content</span>
                                </a>
                            @endif
                        @endif
                    </div>
                @endforelse
            </div>
        </aside>
    @endif
@endsection
