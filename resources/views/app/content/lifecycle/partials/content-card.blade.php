@php
    $cardData = $cardData ?? [];
    $isOverdue = (bool) ($cardData['is_overdue'] ?? false);
    $isDueSoon = (bool) ($cardData['is_due_soon'] ?? false);
@endphp

<div
    class="group rounded-lg border bg-background p-3 transition {{ $isOverdue ? 'border-rose-200' : ($isDueSoon ? 'border-amber-200' : 'border-border') }}"
    data-content-id="{{ $content->id }}"
>
    <div class="mb-2 flex items-start justify-between gap-3">
        <a
            href="{{ route('app.content.show', $content) }}"
            class="block min-w-0 flex-1 text-sm font-medium text-textPrimary hover:text-primary hover:underline"
            title="{{ $content->title }}"
        >
            {{ Str::limit($content->title, 60) }}
        </a>
        <div class="shrink-0 text-right">
            <div class="text-lg font-semibold text-textPrimary">{{ $cardData['content_health_score'] }}</div>
            <div class="text-[10px] uppercase tracking-[0.18em] text-textSecondary">Health</div>
        </div>
    </div>

    <div class="mb-3 flex flex-wrap items-center gap-1.5 text-[11px]">
        <span class="inline-flex items-center rounded-full border border-border bg-surface px-2 py-0.5 text-textSecondary" title="Workflow status">
            Workflow: {{ $cardData['workflow_label'] }}
        </span>
        <span class="inline-flex items-center rounded-full bg-{{ $cardData['intelligence_color'] }}-50 px-2 py-0.5 text-{{ $cardData['intelligence_color'] }}-700" title="Content intelligence status">
            {{ $cardData['intelligence_label'] }}
        </span>
        @if ($content->clientSite)
            <span class="inline-flex items-center rounded-full bg-surfaceSubtle px-2 py-0.5 text-textSecondary" title="Site">
                {{ $content->clientSite->name }}
            </span>
        @endif
        <span class="inline-flex items-center rounded-full border border-border bg-surface px-2 py-0.5 font-medium {{ $content->is_source_locale ? 'border-emerald-200 bg-emerald-50 text-emerald-700' : 'border-sky-200 bg-sky-50 text-sky-700' }}">
            {{ $cardData['locale_label'] }}
        </span>
        @if ($content->series)
            <span class="inline-flex items-center rounded-full bg-surfaceSubtle px-2 py-0.5 text-textSecondary" title="Chain: {{ $content->series->name }}">
                <i data-lucide="link" class="mr-1 h-3 w-3"></i>
                {{ Str::limit($content->series->name, 15) }}
            </span>
        @endif
    </div>

    <div class="mb-3">
        <div class="h-1.5 overflow-hidden rounded-full bg-surfaceSubtle">
            <div class="h-full rounded-full {{ $cardData['content_health_score'] >= 70 ? 'bg-emerald-500' : ($cardData['content_health_score'] >= 40 ? 'bg-amber-500' : 'bg-rose-500') }}" style="width: {{ max(0, min(100, (int) $cardData['content_health_score'])) }}%;"></div>
        </div>
    </div>

    @if (! empty($cardData['signal_badges']))
        <div class="mb-3 flex flex-wrap gap-1.5 text-[11px]">
            @foreach ($cardData['signal_badges'] as $badge)
                <span
                    class="inline-flex items-center rounded-full px-2 py-0.5 {{ $badge['tone'] === 'green' ? 'bg-emerald-50 text-emerald-700' : ($badge['tone'] === 'amber' ? 'bg-amber-50 text-amber-700' : ($badge['tone'] === 'sky' ? 'bg-sky-50 text-sky-700' : 'bg-rose-50 text-rose-700')) }}"
                    title="{{ $badge['tooltip'] }}"
                >
                    {{ $badge['label'] }}
                </span>
            @endforeach
        </div>
    @endif

    <div class="mb-3 rounded-md border border-border/80 bg-surface px-3 py-2">
        <div class="flex items-center justify-between gap-3">
            <div>
                <div class="text-[10px] uppercase tracking-[0.18em] text-textSecondary">AI Visibility</div>
                <div class="mt-1 text-sm font-medium text-textPrimary">
                    {{ is_numeric($cardData['ai_visibility_score']) ? $cardData['ai_visibility_score'] : 'Not scored' }}
                </div>
            </div>
            <div class="text-xs {{ $cardData['ai_visibility_trend'] > 0 ? 'text-emerald-700' : ($cardData['ai_visibility_trend'] < 0 ? 'text-rose-700' : 'text-textSecondary') }}">
                @if ($cardData['ai_visibility_trend'] > 0)
                    +{{ $cardData['ai_visibility_trend'] }}
                @elseif ($cardData['ai_visibility_trend'] < 0)
                    {{ $cardData['ai_visibility_trend'] }}
                @else
                    Stable
                @endif
            </div>
        </div>
        <div class="mt-2 flex flex-wrap gap-1.5">
            @foreach (collect($cardData['provider_pills'] ?? [])->take(4) as $provider)
                <span class="inline-flex items-center rounded-full border border-border bg-background px-2 py-0.5 text-[10px] text-textSecondary" title="{{ $provider['provider'] }} {{ is_numeric($provider['score']) ? 'score '.$provider['score'] : 'score pending' }}">
                    {{ $provider['provider'] }}
                </span>
            @endforeach
        </div>
    </div>

    <div class="mb-3 space-y-1.5 text-xs">
        @if ($content->due_at)
            <div class="flex items-center gap-2 {{ $isOverdue ? 'text-rose-600' : ($isDueSoon ? 'text-amber-600' : 'text-textSecondary') }}">
                <i data-lucide="calendar" class="h-3.5 w-3.5"></i>
                <span>
                    @if ($isOverdue)
                        Overdue by {{ $content->due_at->diffForHumans(['parts' => 1]) }}
                    @else
                        Due {{ $content->due_at->diffForHumans() }}
                    @endif
                </span>
            </div>
        @endif

        @if ($content->assignedUser)
            <div class="flex items-center gap-2 text-textSecondary">
                <i data-lucide="user" class="h-3.5 w-3.5"></i>
                <span>{{ $content->assignedUser->name }}</span>
            </div>
        @endif

        @if ($content->reviewerUser)
            <div class="flex items-center gap-2 text-textSecondary">
                <i data-lucide="user-check" class="h-3.5 w-3.5"></i>
                <span>Reviewer: {{ $content->reviewerUser->name }}</span>
            </div>
        @endif

        @if ($content->rejection_reason)
            <div class="mt-2 rounded-md border border-rose-200 bg-rose-50 p-2 text-xs text-rose-700">
                <strong>Rejected:</strong> {{ Str::limit($content->rejection_reason, 100) }}
            </div>
        @endif
    </div>

    @if (($cardData['recommendations_count'] ?? 0) > 0)
        <div class="mb-3 rounded-md border border-border/80 bg-surface px-3 py-2 text-xs text-textSecondary">
            {{ $cardData['recommendations_count'] }} AI recommendation{{ $cardData['recommendations_count'] === 1 ? '' : 's' }} ready
        </div>
    @endif

    @include('app.content.lifecycle.partials.quick-actions', ['content' => $content, 'stage' => $stage])

    <div class="mt-3 flex items-center justify-between border-t border-border pt-2 text-[10px] text-textFaint">
        <span title="{{ $content->updated_at->format('Y-m-d H:i:s') }}">
            Updated {{ $content->updated_at->diffForHumans() }}
        </span>
        <div class="flex items-center gap-3">
            <a href="{{ route('app.content.lifecycle.history', $content) }}" class="text-link hover:text-linkHover hover:underline">History</a>
            <a href="{{ route('app.content.show', $content) }}" class="text-link hover:text-linkHover hover:underline">Open</a>
        </div>
    </div>
</div>
