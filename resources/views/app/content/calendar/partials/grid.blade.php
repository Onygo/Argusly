{{-- Calendar Grid --}}
@php
    $showWeekNumbers = (bool) ($showWeekNumbers ?? false);
    $weekdayLabels = ['Ma', 'Di', 'Wo', 'Do', 'Vr', 'Za', 'Zo'];
    $totalItemCount = collect($days)->sum('item_count');
@endphp

{{-- Empty State for Entire Period --}}
@if ($totalItemCount === 0 && !$isWeekView)
    <div class="rounded-lg border-2 border-dashed border-border bg-surfaceSubtle p-8 text-center">
        <div class="mx-auto mb-4 flex h-16 w-16 items-center justify-center rounded-full bg-surfaceMuted text-textMuted">
            <i data-lucide="calendar-x-2" class="h-8 w-8"></i>
        </div>
        <h3 class="text-lg font-semibold text-textPrimary">Nog geen content gepland</h3>
        <p class="mx-auto mt-2 max-w-md text-sm text-textSecondary">
            Begin met plannen via de sidebar aan de rechterkant, of klik op een dag in de kalender.
        </p>
    </div>
@else
    @if (!$isWeekView)
        <div class="overflow-hidden rounded-lg border border-border bg-surface" data-calendar-month>
            <div
                @class([
                    'border-b border-border bg-surfaceSubtle',
                    'grid grid-cols-[2.75rem_repeat(7,minmax(0,1fr))]' => $showWeekNumbers,
                    'grid grid-cols-7' => !$showWeekNumbers,
                ])
                data-calendar-weekdays
                role="row"
            >
                @if ($showWeekNumbers)
                    <div class="border-r border-border px-1 py-2.5 text-center text-[10px] font-semibold uppercase tracking-wider text-textFaint">
                        Wk
                    </div>
                @endif
                @foreach ($weekdayLabels as $weekday)
                    <div class="border-r border-border px-2 py-2.5 text-center text-[11px] font-semibold uppercase tracking-wider text-textMuted last:border-r-0">
                        {{ $weekday }}
                    </div>
                @endforeach
            </div>

            <div class="flex flex-col" data-calendar-day-grid role="grid" aria-label="Maandkalender">
                @foreach ($weekRows as $row)
                    @php
                        $rowIndex = (int) $row['index'];
                        $isLastRow = $loop->last;
                    @endphp
                    <div
                        @class([
                            'grid',
                            'grid-cols-[2.75rem_repeat(7,minmax(0,1fr))]' => $showWeekNumbers,
                            'grid-cols-7' => !$showWeekNumbers,
                        ])
                        role="row"
                    >
                        @if ($showWeekNumbers)
                            <div @class([
                                'flex items-start justify-center border-r border-border bg-surfaceSubtle/60 px-1 py-3 text-[11px] font-semibold text-textFaint',
                                'border-b border-border' => !$isLastRow,
                            ])>
                                {{ $row['week_number'] }}
                            </div>
                        @endif

                        @foreach ($row['days'] as $colIndex => $day)
                            @php
                                $gridIndex = ($rowIndex * 7) + $colIndex;
                                $isLastColumn = $colIndex === 6;
                                $isFocusable = $day['is_selected'] || (!$selectedDate && $gridIndex === 0);
                            @endphp
                            <article
                                data-calendar-day-card
                                data-calendar-dropzone
                                data-day-key="{{ $day['key'] }}"
                                data-day-label="{{ $day['full_label'] }}"
                                data-calendar-day-view="month"
                                data-calendar-row="{{ $rowIndex }}"
                                data-calendar-col="{{ $colIndex }}"
                                data-calendar-index="{{ $gridIndex }}"
                                data-day-is-today="{{ $day['is_today'] ? 'true' : 'false' }}"
                                data-day-is-past="{{ $day['is_past'] ? 'true' : 'false' }}"
                                data-day-in-anchor-month="{{ $day['is_in_anchor_month'] ? 'true' : 'false' }}"
                                tabindex="{{ $isFocusable ? 0 : -1 }}"
                                role="gridcell"
                                aria-selected="{{ $day['is_selected'] ? 'true' : 'false' }}"
                                aria-label="{{ $day['full_label'] }}"
                                @if ($day['is_selected']) data-calendar-selected="true" @endif
                                @class([
                                    'group relative flex min-h-[9.5rem] cursor-pointer flex-col p-2 transition-colors focus:outline-none focus:ring-2 focus:ring-inset focus:ring-primary/30',
                                    'border-r border-border' => !$isLastColumn,
                                    'border-b border-border' => !$isLastRow,
                                    'bg-primary/[0.03] ring-1 ring-inset ring-primary/20' => $day['is_today'],
                                    'bg-primary/[0.06] ring-2 ring-inset ring-primary/30' => $day['is_selected'] && !$day['is_today'],
                                    'bg-surfaceSubtle/70' => !$day['is_in_anchor_month'] && !$day['is_selected'] && !$day['is_today'],
                                    'bg-surfaceSubtle/40' => $day['is_past'] && $day['is_in_anchor_month'] && !$day['is_selected'] && !$day['is_today'],
                                    'bg-surface hover:bg-surfaceSubtle/50' => !$day['is_past'] && $day['is_in_anchor_month'] && !$day['is_selected'] && !$day['is_today'],
                                ])
                            >
                                <div class="mb-1.5 flex items-center justify-between">
                                    <span
                                        data-calendar-day-number
                                        @class([
                                            'flex h-7 w-7 items-center justify-center rounded-full text-xs font-semibold transition-colors',
                                            'bg-primary text-textInverse shadow-sm' => $day['is_today'],
                                            'bg-primary/15 font-bold text-primary' => $day['is_selected'] && !$day['is_today'],
                                            'text-textPrimary' => !$day['is_today'] && !$day['is_selected'] && !$day['is_past'] && $day['is_in_anchor_month'],
                                            'text-textMuted' => $day['is_past'] && !$day['is_today'] && $day['is_in_anchor_month'],
                                            'text-textFaint' => !$day['is_in_anchor_month'],
                                        ])
                                    >
                                        {{ $day['day_number'] }}
                                    </span>

                                    @if ($day['create_url'] && !$day['is_past'] && $day['is_in_anchor_month'])
                                        <button
                                            type="button"
                                            data-calendar-day-add
                                            class="flex h-6 w-6 items-center justify-center rounded-md text-textMuted opacity-0 transition-all hover:bg-primary/10 hover:text-primary group-hover:opacity-100 group-focus-within:opacity-100 focus-visible:opacity-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary/30"
                                            aria-label="Create content for {{ $day['full_label'] }}"
                                        >
                                            <i data-lucide="plus" class="h-3.5 w-3.5"></i>
                                        </button>
                                    @endif
                                </div>

                                <div class="flex flex-1 flex-col gap-1">
                                    @foreach ($day['preview_items'] as $item)
                                        @if (!empty($item['open_url']))
                                            <a
                                                href="{{ $item['open_url'] }}"
                                                data-calendar-item-id="{{ $item['id'] }}"
                                                data-calendar-item-draggable="{{ $item['can_drag'] ? 'true' : 'false' }}"
                                                data-calendar-item-schedule-url="{{ $item['schedule_url'] }}"
                                                data-calendar-item-datetime="{{ $item['scheduled_at_value'] }}"
                                                data-calendar-item-day-key="{{ $day['key'] }}"
                                                draggable="{{ $item['can_drag'] ? 'true' : 'false' }}"
                                                class="{{ $day['is_in_anchor_month'] && !$day['is_past'] ? 'bg-surfaceMuted/80 hover:bg-surfaceMuted' : 'bg-surfaceMuted/50' }} group/chip flex items-center gap-1.5 rounded-md px-2 py-1 transition-colors"
                                            >
                                                <span @class([
                                                    'h-2 w-2 shrink-0 rounded-full',
                                                    'bg-emerald-500' => in_array($item['status']['color'], ['green', 'emerald']),
                                                    'bg-sky-500' => $item['status']['color'] === 'sky',
                                                    'bg-amber-500' => $item['status']['color'] === 'amber',
                                                    'bg-slate-400' => !in_array($item['status']['color'], ['green', 'emerald', 'sky', 'amber']),
                                                ])></span>
                                                <div class="min-w-0 flex-1">
                                                    <p @class([
                                                        'truncate text-[11px] font-medium leading-tight',
                                                        'text-textPrimary' => $day['is_in_anchor_month'] && !$day['is_past'],
                                                        'text-textMuted' => !$day['is_in_anchor_month'] || $day['is_past'],
                                                    ])>{{ $item['title'] }}</p>
                                                    @if ($item['scheduled_at_label'])
                                                        <p class="truncate text-[9px] text-textMuted">
                                                            {{ $item['scheduled_at_label'] }}
                                                            <span class="text-textFaint">·</span>
                                                            {{ $item['status']['label'] }}
                                                        </p>
                                                    @endif
                                                </div>
                                            </a>
                                        @else
                                            <button
                                                type="button"
                                                data-calendar-day-item-fallback
                                                data-day-key="{{ $day['key'] }}"
                                                data-calendar-item-id="{{ $item['id'] }}"
                                                data-calendar-item-draggable="{{ $item['can_drag'] ? 'true' : 'false' }}"
                                                data-calendar-item-schedule-url="{{ $item['schedule_url'] }}"
                                                data-calendar-item-datetime="{{ $item['scheduled_at_value'] }}"
                                                data-calendar-item-day-key="{{ $day['key'] }}"
                                                draggable="{{ $item['can_drag'] ? 'true' : 'false' }}"
                                                class="{{ $day['is_in_anchor_month'] && !$day['is_past'] ? 'bg-surfaceMuted/80 hover:bg-surfaceMuted' : 'bg-surfaceMuted/50' }} group/chip flex w-full items-center gap-1.5 rounded-md px-2 py-1 text-left transition-colors"
                                            >
                                                <span @class([
                                                    'h-2 w-2 shrink-0 rounded-full',
                                                    'bg-emerald-500' => in_array($item['status']['color'], ['green', 'emerald']),
                                                    'bg-sky-500' => $item['status']['color'] === 'sky',
                                                    'bg-amber-500' => $item['status']['color'] === 'amber',
                                                    'bg-slate-400' => !in_array($item['status']['color'], ['green', 'emerald', 'sky', 'amber']),
                                                ])></span>
                                                <div class="min-w-0 flex-1">
                                                    <p @class([
                                                        'truncate text-[11px] font-medium leading-tight',
                                                        'text-textPrimary' => $day['is_in_anchor_month'] && !$day['is_past'],
                                                        'text-textMuted' => !$day['is_in_anchor_month'] || $day['is_past'],
                                                    ])>{{ $item['title'] }}</p>
                                                    @if ($item['scheduled_at_label'])
                                                        <p class="truncate text-[9px] text-textMuted">
                                                            {{ $item['scheduled_at_label'] }}
                                                            <span class="text-textFaint">·</span>
                                                            {{ $item['status']['label'] }}
                                                        </p>
                                                    @endif
                                                </div>
                                            </button>
                                        @endif
                                    @endforeach

                                    @if ($day['overflow_item_count'] > 0)
                                        <button
                                            type="button"
                                            data-calendar-day-overflow
                                            data-calendar-overflow-toggle
                                            class="mt-0.5 rounded px-1.5 py-0.5 text-left text-[10px] font-medium text-textMuted transition-colors hover:bg-surfaceMuted hover:text-textPrimary"
                                        >
                                            +{{ $day['overflow_item_count'] }} meer
                                        </button>
                                    @endif

                                    @if ($day['item_count'] === 0 && !$day['is_past'] && $day['is_in_anchor_month'])
                                        <div class="flex flex-1 items-center justify-center">
                                            <div class="flex flex-col items-center gap-1 text-center opacity-0 transition-opacity group-hover:opacity-100 group-focus-within:opacity-100">
                                                <span class="flex h-7 w-7 items-center justify-center rounded-full border border-dashed border-textFaint text-textFaint transition-colors group-hover:border-primary/40 group-hover:text-primary/60">
                                                    <i data-lucide="plus" class="h-3.5 w-3.5"></i>
                                                </span>
                                                <span class="text-[9px] text-textFaint">plan content</span>
                                            </div>
                                        </div>
                                    @endif
                                </div>

                                @if ($day['item_count'] > 0)
                                    <template data-calendar-day-items>
                                        <div class="space-y-2">
                                            @foreach ($day['items'] as $item)
                                                @if (!empty($item['open_url']))
                                                    <a
                                                        href="{{ $item['open_url'] }}"
                                                        data-calendar-item-id="{{ $item['id'] }}"
                                                        data-calendar-item-draggable="{{ $item['can_drag'] ? 'true' : 'false' }}"
                                                        data-calendar-item-schedule-url="{{ $item['schedule_url'] }}"
                                                        data-calendar-item-datetime="{{ $item['scheduled_at_value'] }}"
                                                        data-calendar-item-day-key="{{ $day['key'] }}"
                                                        draggable="{{ $item['can_drag'] ? 'true' : 'false' }}"
                                                        class="flex items-start gap-2 rounded-lg border border-border bg-surfaceSubtle px-3 py-2 transition-colors hover:bg-surfaceMuted"
                                                    >
                                                        <span @class([
                                                            'mt-1 h-2 w-2 shrink-0 rounded-full',
                                                            'bg-emerald-500' => in_array($item['status']['color'], ['green', 'emerald']),
                                                            'bg-sky-500' => $item['status']['color'] === 'sky',
                                                            'bg-amber-500' => $item['status']['color'] === 'amber',
                                                            'bg-slate-400' => !in_array($item['status']['color'], ['green', 'emerald', 'sky', 'amber']),
                                                        ])></span>
                                                        <div class="min-w-0 flex-1">
                                                            <p class="truncate text-sm font-medium text-textPrimary">{{ $item['title'] }}</p>
                                                            <p class="mt-1 text-xs text-textMuted">
                                                                {{ $item['scheduled_at_label'] ?: 'Geen tijd' }}
                                                                <span class="mx-1 text-textFaint">·</span>
                                                                {{ $item['status']['label'] }}
                                                            </p>
                                                        </div>
                                                    </a>
                                                @else
                                                    <button
                                                        type="button"
                                                        data-calendar-day-item-fallback
                                                        data-day-key="{{ $day['key'] }}"
                                                        data-calendar-item-id="{{ $item['id'] }}"
                                                        data-calendar-item-draggable="{{ $item['can_drag'] ? 'true' : 'false' }}"
                                                        data-calendar-item-schedule-url="{{ $item['schedule_url'] }}"
                                                        data-calendar-item-datetime="{{ $item['scheduled_at_value'] }}"
                                                        data-calendar-item-day-key="{{ $day['key'] }}"
                                                        draggable="{{ $item['can_drag'] ? 'true' : 'false' }}"
                                                        class="flex w-full items-start gap-2 rounded-lg border border-border bg-surfaceSubtle px-3 py-2 text-left transition-colors hover:bg-surfaceMuted"
                                                    >
                                                        <span @class([
                                                            'mt-1 h-2 w-2 shrink-0 rounded-full',
                                                            'bg-emerald-500' => in_array($item['status']['color'], ['green', 'emerald']),
                                                            'bg-sky-500' => $item['status']['color'] === 'sky',
                                                            'bg-amber-500' => $item['status']['color'] === 'amber',
                                                            'bg-slate-400' => !in_array($item['status']['color'], ['green', 'emerald', 'sky', 'amber']),
                                                        ])></span>
                                                        <div class="min-w-0 flex-1">
                                                            <p class="truncate text-sm font-medium text-textPrimary">{{ $item['title'] }}</p>
                                                            <p class="mt-1 text-xs text-textMuted">
                                                                {{ $item['scheduled_at_label'] ?: 'Geen tijd' }}
                                                                <span class="mx-1 text-textFaint">·</span>
                                                                {{ $item['status']['label'] }}
                                                            </p>
                                                        </div>
                                                    </button>
                                                @endif
                                            @endforeach
                                        </div>
                                    </template>
                                @endif
                            </article>
                        @endforeach
                    </div>
                @endforeach
            </div>
        </div>
    @else
        <div class="-mx-4 overflow-x-auto px-4 lg:mx-0 lg:px-0">
            <div class="flex min-w-max gap-3 lg:grid lg:min-w-0 lg:grid-cols-7" data-calendar-week role="grid" aria-label="Weekkalender">
                @foreach ($days as $day)
                    @php
                        $isFocusable = $day['is_selected'] || (!$selectedDate && $loop->first);
                    @endphp
                    <article
                        data-calendar-day-card
                        data-calendar-dropzone
                        data-day-key="{{ $day['key'] }}"
                        data-day-label="{{ $day['full_label'] }}"
                        data-calendar-day-view="week"
                        data-calendar-row="0"
                        data-calendar-col="{{ $loop->index }}"
                        data-calendar-index="{{ $loop->index }}"
                        data-day-is-today="{{ $day['is_today'] ? 'true' : 'false' }}"
                        data-day-is-past="{{ $day['is_past'] ? 'true' : 'false' }}"
                        data-day-in-anchor-month="{{ $day['is_in_anchor_month'] ? 'true' : 'false' }}"
                        tabindex="{{ $isFocusable ? 0 : -1 }}"
                        role="gridcell"
                        aria-selected="{{ $day['is_selected'] ? 'true' : 'false' }}"
                        aria-label="{{ $day['full_label'] }}"
                        @if ($day['is_selected']) data-calendar-selected="true" @endif
                        @class([
                            'group flex w-[200px] shrink-0 cursor-pointer flex-col rounded-lg border p-3 transition-all hover:shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/30 lg:w-auto',
                            'min-h-[20rem] lg:min-h-[24rem]',
                            'border-primary/30 bg-surface ring-2 ring-primary/10' => $day['is_selected'],
                            'border-primary/40 bg-primary/[0.02]' => $day['is_today'] && !$day['is_selected'],
                            'border-border bg-surface hover:border-borderStrong' => !$day['is_selected'] && !$day['is_today'] && !$day['is_past'],
                            'border-border bg-surfaceSubtle/50' => $day['is_past'] && !$day['is_selected'],
                        ])
                    >
                        <div class="mb-3 flex items-start justify-between border-b border-border pb-3">
                            <div class="flex items-center gap-2">
                                <span class="text-xs font-semibold uppercase tracking-wider text-textMuted">{{ $day['weekday'] }}</span>
                                <span
                                    data-calendar-day-number
                                    @class([
                                        'flex h-8 w-8 items-center justify-center rounded-full text-sm font-semibold',
                                        'bg-primary text-textInverse shadow-sm' => $day['is_today'],
                                        'bg-primary/10 text-primary' => $day['is_selected'] && !$day['is_today'],
                                        'text-textPrimary' => !$day['is_today'] && !$day['is_selected'] && !$day['is_past'],
                                        'text-textMuted' => $day['is_past'] && !$day['is_today'],
                                    ])
                                >{{ $day['day_number'] }}</span>
                            </div>

                            <div class="flex items-center gap-1">
                                @if ($day['item_count'] > 0)
                                    <span class="rounded-full bg-surfaceMuted px-2 py-0.5 text-[10px] font-semibold tabular-nums text-textMuted">
                                        {{ $day['item_count'] }}
                                    </span>
                                @endif

                                @if ($day['create_url'] && !$day['is_past'])
                                    <button
                                        type="button"
                                        data-calendar-day-add
                                        class="flex h-6 w-6 items-center justify-center rounded-md text-textMuted transition-colors hover:bg-surfaceMuted hover:text-textPrimary focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary/30"
                                        aria-label="Create content for {{ $day['full_label'] }}"
                                    >
                                        <i data-lucide="plus" class="h-3.5 w-3.5"></i>
                                    </button>
                                @endif
                            </div>
                        </div>

                        <div class="flex flex-1 flex-col gap-2 overflow-y-auto">
                            @forelse ($day['preview_items'] as $item)
                                @if (!empty($item['open_url']))
                                    <a
                                        href="{{ $item['open_url'] }}"
                                        data-calendar-item-id="{{ $item['id'] }}"
                                        data-calendar-item-draggable="{{ $item['can_drag'] ? 'true' : 'false' }}"
                                        data-calendar-item-schedule-url="{{ $item['schedule_url'] }}"
                                        data-calendar-item-datetime="{{ $item['scheduled_at_value'] }}"
                                        data-calendar-item-day-key="{{ $day['key'] }}"
                                        draggable="{{ $item['can_drag'] ? 'true' : 'false' }}"
                                        class="{{ !$day['is_past'] ? 'border-border bg-surfaceSubtle' : 'border-border/70 bg-surfaceMuted/50' }} rounded-lg border px-2.5 py-2 transition-colors hover:bg-surfaceMuted"
                                    >
                                        <div class="flex items-start gap-2">
                                            <span @class([
                                                'mt-1 h-2 w-2 shrink-0 rounded-full',
                                                'bg-emerald-500' => in_array($item['status']['color'], ['green', 'emerald']),
                                                'bg-sky-500' => $item['status']['color'] === 'sky',
                                                'bg-amber-500' => $item['status']['color'] === 'amber',
                                                'bg-slate-400' => !in_array($item['status']['color'], ['green', 'emerald', 'sky', 'amber']),
                                            ])></span>
                                            <div class="min-w-0 flex-1">
                                                <p @class([
                                                    'truncate text-xs font-medium',
                                                    'text-textPrimary' => !$day['is_past'],
                                                    'text-textMuted' => $day['is_past'],
                                                ])>{{ $item['title'] }}</p>
                                                <div class="mt-1 flex items-center gap-2">
                                                    @if ($item['scheduled_at_label'])
                                                        <span class="text-[10px] text-textMuted">{{ $item['scheduled_at_label'] }}</span>
                                                    @endif
                                                    <span @class([
                                                        'inline-flex items-center rounded px-1.5 py-0.5 text-[9px] font-medium',
                                                        'bg-emerald-100 text-emerald-700' => in_array($item['status']['color'], ['green', 'emerald']),
                                                        'bg-sky-100 text-sky-700' => $item['status']['color'] === 'sky',
                                                        'bg-amber-100 text-amber-700' => $item['status']['color'] === 'amber',
                                                        'bg-slate-100 text-slate-600' => !in_array($item['status']['color'], ['green', 'emerald', 'sky', 'amber']),
                                                    ])>{{ $item['status']['label'] }}</span>
                                                </div>
                                            </div>
                                        </div>
                                    </a>
                                @else
                                    <button
                                        type="button"
                                        data-calendar-day-item-fallback
                                        data-day-key="{{ $day['key'] }}"
                                        data-calendar-item-id="{{ $item['id'] }}"
                                        data-calendar-item-draggable="{{ $item['can_drag'] ? 'true' : 'false' }}"
                                        data-calendar-item-schedule-url="{{ $item['schedule_url'] }}"
                                        data-calendar-item-datetime="{{ $item['scheduled_at_value'] }}"
                                        data-calendar-item-day-key="{{ $day['key'] }}"
                                        draggable="{{ $item['can_drag'] ? 'true' : 'false' }}"
                                        class="{{ !$day['is_past'] ? 'border-border bg-surfaceSubtle' : 'border-border/70 bg-surfaceMuted/50' }} w-full rounded-lg border px-2.5 py-2 text-left transition-colors hover:bg-surfaceMuted"
                                    >
                                        <div class="flex items-start gap-2">
                                            <span @class([
                                                'mt-1 h-2 w-2 shrink-0 rounded-full',
                                                'bg-emerald-500' => in_array($item['status']['color'], ['green', 'emerald']),
                                                'bg-sky-500' => $item['status']['color'] === 'sky',
                                                'bg-amber-500' => $item['status']['color'] === 'amber',
                                                'bg-slate-400' => !in_array($item['status']['color'], ['green', 'emerald', 'sky', 'amber']),
                                            ])></span>
                                            <div class="min-w-0 flex-1">
                                                <p @class([
                                                    'truncate text-xs font-medium',
                                                    'text-textPrimary' => !$day['is_past'],
                                                    'text-textMuted' => $day['is_past'],
                                                ])>{{ $item['title'] }}</p>
                                                <div class="mt-1 flex items-center gap-2">
                                                    @if ($item['scheduled_at_label'])
                                                        <span class="text-[10px] text-textMuted">{{ $item['scheduled_at_label'] }}</span>
                                                    @endif
                                                    <span @class([
                                                        'inline-flex items-center rounded px-1.5 py-0.5 text-[9px] font-medium',
                                                        'bg-emerald-100 text-emerald-700' => in_array($item['status']['color'], ['green', 'emerald']),
                                                        'bg-sky-100 text-sky-700' => $item['status']['color'] === 'sky',
                                                        'bg-amber-100 text-amber-700' => $item['status']['color'] === 'amber',
                                                        'bg-slate-100 text-slate-600' => !in_array($item['status']['color'], ['green', 'emerald', 'sky', 'amber']),
                                                    ])>{{ $item['status']['label'] }}</span>
                                                </div>
                                            </div>
                                        </div>
                                    </button>
                                @endif
                            @empty
                                @if (!$day['is_past'])
                                    <div class="flex flex-1 items-center justify-center">
                                        <div class="flex flex-col items-center gap-2 text-center opacity-0 transition-opacity group-hover:opacity-100 group-focus-within:opacity-100">
                                            <span class="flex h-8 w-8 items-center justify-center rounded-full border border-dashed border-border text-textMuted">
                                                <i data-lucide="plus" class="h-4 w-4"></i>
                                            </span>
                                            <span class="text-xs text-textFaint">Klik om te plannen</span>
                                        </div>
                                    </div>
                                @else
                                    <div class="flex flex-1 items-center justify-center">
                                        <span class="text-xs text-textFaint">Geen content</span>
                                    </div>
                                @endif
                            @endforelse

                            @if ($day['overflow_item_count'] > 0)
                                <button
                                    type="button"
                                    data-calendar-day-overflow
                                    data-calendar-overflow-toggle
                                    class="text-left text-[11px] font-medium text-textMuted transition-colors hover:text-textPrimary"
                                >
                                    +{{ $day['overflow_item_count'] }} meer
                                </button>
                            @endif
                        </div>

                        @if ($day['item_count'] > 0)
                            <template data-calendar-day-items>
                                <div class="space-y-2">
                                    @foreach ($day['items'] as $item)
                                        @if (!empty($item['open_url']))
                                            <a
                                                href="{{ $item['open_url'] }}"
                                                data-calendar-item-id="{{ $item['id'] }}"
                                                data-calendar-item-draggable="{{ $item['can_drag'] ? 'true' : 'false' }}"
                                                data-calendar-item-schedule-url="{{ $item['schedule_url'] }}"
                                                data-calendar-item-datetime="{{ $item['scheduled_at_value'] }}"
                                                data-calendar-item-day-key="{{ $day['key'] }}"
                                                draggable="{{ $item['can_drag'] ? 'true' : 'false' }}"
                                                class="flex items-start gap-2 rounded-lg border border-border bg-surfaceSubtle px-3 py-2 transition-colors hover:bg-surfaceMuted"
                                            >
                                                <span @class([
                                                    'mt-1 h-2 w-2 shrink-0 rounded-full',
                                                    'bg-emerald-500' => in_array($item['status']['color'], ['green', 'emerald']),
                                                    'bg-sky-500' => $item['status']['color'] === 'sky',
                                                    'bg-amber-500' => $item['status']['color'] === 'amber',
                                                    'bg-slate-400' => !in_array($item['status']['color'], ['green', 'emerald', 'sky', 'amber']),
                                                ])></span>
                                                <div class="min-w-0 flex-1">
                                                    <p class="truncate text-sm font-medium text-textPrimary">{{ $item['title'] }}</p>
                                                    <p class="mt-1 text-xs text-textMuted">
                                                        {{ $item['scheduled_at_label'] ?: 'Geen tijd' }}
                                                        <span class="mx-1 text-textFaint">·</span>
                                                        {{ $item['status']['label'] }}
                                                    </p>
                                                </div>
                                            </a>
                                        @else
                                            <button
                                                type="button"
                                                data-calendar-day-item-fallback
                                                data-day-key="{{ $day['key'] }}"
                                                data-calendar-item-id="{{ $item['id'] }}"
                                                data-calendar-item-draggable="{{ $item['can_drag'] ? 'true' : 'false' }}"
                                                data-calendar-item-schedule-url="{{ $item['schedule_url'] }}"
                                                data-calendar-item-datetime="{{ $item['scheduled_at_value'] }}"
                                                data-calendar-item-day-key="{{ $day['key'] }}"
                                                draggable="{{ $item['can_drag'] ? 'true' : 'false' }}"
                                                class="flex w-full items-start gap-2 rounded-lg border border-border bg-surfaceSubtle px-3 py-2 text-left transition-colors hover:bg-surfaceMuted"
                                            >
                                                <span @class([
                                                    'mt-1 h-2 w-2 shrink-0 rounded-full',
                                                    'bg-emerald-500' => in_array($item['status']['color'], ['green', 'emerald']),
                                                    'bg-sky-500' => $item['status']['color'] === 'sky',
                                                    'bg-amber-500' => $item['status']['color'] === 'amber',
                                                    'bg-slate-400' => !in_array($item['status']['color'], ['green', 'emerald', 'sky', 'amber']),
                                                ])></span>
                                                <div class="min-w-0 flex-1">
                                                    <p class="truncate text-sm font-medium text-textPrimary">{{ $item['title'] }}</p>
                                                    <p class="mt-1 text-xs text-textMuted">
                                                        {{ $item['scheduled_at_label'] ?: 'Geen tijd' }}
                                                        <span class="mx-1 text-textFaint">·</span>
                                                        {{ $item['status']['label'] }}
                                                    </p>
                                                </div>
                                            </button>
                                        @endif
                                    @endforeach
                                </div>
                            </template>
                        @endif
                    </article>
                @endforeach
            </div>
        </div>
    @endif
@endif
