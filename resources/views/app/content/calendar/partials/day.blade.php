{{-- Day View --}}
@php
    $day = $selectedDay ?? collect($days)->first();
@endphp

@if (!$day)
    <div class="rounded-lg border border-border bg-surface p-6">
        <p class="text-sm text-textSecondary">Geen dag beschikbaar.</p>
    </div>
@else
    <section class="space-y-4" data-calendar-day-view-panel>
        <div class="rounded-lg border border-border bg-surface px-5 py-4">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <div class="flex flex-wrap items-center gap-2">
                        <h2 class="text-xl font-semibold text-textPrimary">{{ $day['full_label'] }}</h2>
                        @if ($day['is_today'])
                            <span class="rounded-full bg-primary/10 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wider text-primary">Vandaag</span>
                        @elseif ($day['is_past'])
                            <span class="rounded-full bg-surfaceMuted px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wider text-textMuted">Verleden</span>
                        @endif
                    </div>
                    <p class="mt-1 text-sm text-textMuted">
                        {{ $day['item_count'] === 0 ? 'Geen content gepland voor deze dag.' : $day['item_count'] . ' ' . ($day['item_count'] === 1 ? 'item gepland' : 'items gepland') }}
                    </p>
                </div>

                @if (!$day['is_past'] && $day['create_url'])
                    <button
                        type="button"
                        data-calendar-day-add
                        data-day-key="{{ $day['key'] }}"
                        class="inline-flex items-center gap-1.5 rounded-lg bg-primary px-3 py-2 text-sm font-medium text-textInverse transition-colors hover:bg-primaryHover"
                    >
                        <i data-lucide="plus" class="h-4 w-4"></i>
                        <span>Plan content</span>
                    </button>
                @endif
            </div>
        </div>

        @if ($day['item_count'] === 0)
            <div class="rounded-lg border border-dashed border-border bg-surfaceSubtle px-6 py-10 text-center">
                <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-surfaceMuted text-textMuted">
                    <i data-lucide="{{ $day['is_past'] ? 'calendar-x' : 'calendar' }}" class="h-5 w-5"></i>
                </div>
                <p class="mt-4 text-sm font-medium text-textSecondary">{{ $day['is_past'] ? 'Geen content op deze dag' : 'Nog niets gepland' }}</p>
                <p class="mt-1 text-xs text-textMuted">
                    {{ $day['is_past'] ? 'Er stond niets ingepland of gepubliceerd.' : 'Gebruik de snelle planner rechts om direct een nieuw item toe te voegen.' }}
                </p>
            </div>
        @else
            <div class="space-y-3">
                @foreach ($day['items'] as $item)
                    <article
                        class="rounded-lg border border-border bg-surface p-4 shadow-sm"
                        data-calendar-item-id="{{ $item['id'] }}"
                        data-calendar-item-draggable="{{ $item['can_drag'] ? 'true' : 'false' }}"
                        data-calendar-item-schedule-url="{{ $item['schedule_url'] }}"
                        data-calendar-item-datetime="{{ $item['scheduled_at_value'] }}"
                        data-calendar-item-day-key="{{ $day['key'] }}"
                    >
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div class="min-w-0 flex-1">
                                <div class="flex flex-wrap items-center gap-2">
                                    <h3 class="truncate text-base font-semibold text-textPrimary">{{ $item['title'] }}</h3>
                                    <x-status-badge
                                        :label="$item['status']['label']"
                                        :color="$item['status']['color']"
                                        :dot="true"
                                        size="xs"
                                    />
                                </div>
                                <p class="mt-1.5 text-xs text-textMuted">
                                    {{ $item['scheduled_at_label'] ?: 'Geen tijd ingesteld' }}
                                    <span class="mx-1 text-textFaint">·</span>
                                    {{ $item['channel_label'] }}
                                    <span class="mx-1 text-textFaint">·</span>
                                    {{ $item['site_name'] }}
                                </p>
                            </div>

                            @if (!empty($item['open_url']))
                                <a href="{{ $item['open_url'] }}" class="inline-flex items-center gap-1 rounded-lg border border-border bg-surfaceSubtle px-3 py-1.5 text-xs font-medium text-textPrimary transition-colors hover:bg-surfaceMuted">
                                    Open
                                    <i data-lucide="arrow-up-right" class="h-3 w-3 text-textMuted"></i>
                                </a>
                            @endif
                        </div>

                        <div class="mt-3 flex flex-wrap items-center gap-2">
                            @foreach ($item['detail_actions'] as $action)
                                @if ($action['type'] === 'post')
                                    <form method="POST" action="{{ $action['url'] }}">
                                        @csrf
                                        <button type="submit" class="rounded-lg border border-border bg-surfaceSubtle px-2.5 py-1.5 text-xs font-medium text-textPrimary transition-colors hover:bg-surfaceMuted">{{ $action['label'] }}</button>
                                    </form>
                                @elseif ($action['type'] === 'external')
                                    <a href="{{ $action['url'] }}" target="_blank" rel="noopener" class="inline-flex items-center gap-1 rounded-lg border border-border bg-surfaceSubtle px-2.5 py-1.5 text-xs font-medium text-textPrimary transition-colors hover:bg-surfaceMuted">
                                        {{ $action['label'] }}
                                        <i data-lucide="external-link" class="h-3 w-3 text-textMuted"></i>
                                    </a>
                                @else
                                    <a href="{{ $action['url'] }}" class="rounded-lg border border-border bg-surfaceSubtle px-2.5 py-1.5 text-xs font-medium text-textPrimary transition-colors hover:bg-surfaceMuted">{{ $action['label'] }}</a>
                                @endif
                            @endforeach
                        </div>

                        @if ($item['schedule_action'])
                            <form method="POST" action="{{ $item['schedule_action']['url'] }}" class="mt-3 flex flex-col gap-2 rounded-lg border border-border bg-surfaceSubtle p-3 sm:flex-row sm:items-end">
                                @csrf
                                <div class="min-w-0 flex-1">
                                    <label class="mb-1 block text-[10px] font-semibold uppercase tracking-wider text-textMuted" for="calendar-day-schedule-{{ $item['id'] }}">{{ $item['schedule_action']['label'] }}</label>
                                    <input
                                        id="calendar-day-schedule-{{ $item['id'] }}"
                                        type="datetime-local"
                                        name="scheduled_publish_at"
                                        value="{{ $item['scheduled_at_value'] }}"
                                        class="w-full rounded-lg border border-border bg-background px-3 py-2 text-sm text-textPrimary"
                                    >
                                </div>
                                <button type="submit" class="rounded-lg bg-primary px-3 py-2 text-xs font-medium text-textInverse transition-colors hover:bg-primaryHover">Opslaan</button>
                            </form>
                        @endif
                    </article>
                @endforeach
            </div>
        @endif
    </section>
@endif
