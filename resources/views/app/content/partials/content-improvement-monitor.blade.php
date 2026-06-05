@php
    $activeRuns = collect($contentImprovementDashboard['active'] ?? []);
    $recentEvents = collect($contentImprovementDashboard['events'] ?? [])->sortByDesc('id')->take(8)->values();
@endphp

<div id="content-improvement-monitor" class="mt-5 rounded-2xl bg-slate-50 p-4">
    <div class="flex items-start justify-between gap-3">
        <div>
            <div class="text-xs font-medium uppercase tracking-[0.18em] text-textSecondary">AI improvements running</div>
            <h4 class="mt-1 text-base font-semibold text-textPrimary">Improvement Monitor</h4>
            <p class="mt-1 text-sm text-textSecondary">Queued and running generation jobs with progress and diagnostics.</p>
        </div>
    </div>

    @if ($activeRuns->isEmpty())
        <div class="mt-4 rounded-2xl border border-border/70 bg-white px-4 py-5 text-sm text-textSecondary">
            No AI improvements are running right now.
        </div>
    @else
        <div class="mt-4 space-y-3">
            @foreach ($activeRuns as $run)
                @php
                    $diagnostics = (array) ($run->diagnostics ?? []);
                    $elapsed = isset($diagnostics['elapsed_seconds']) ? (int) $diagnostics['elapsed_seconds'] : (($run->started_at?->diffInSeconds(now())) ?? null);
                @endphp
                <div class="rounded-2xl border border-border/70 bg-white px-4 py-4" data-active-run data-run-status="{{ $run->status }}">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <div>
                            <div class="text-sm font-medium text-textPrimary">{{ $run->recommendation_label ?: \Illuminate\Support\Str::headline((string) $run->type) }}</div>
                            <div class="mt-1 text-xs text-textSecondary">{{ ucfirst((string) $run->status) }} · {{ (int) $run->progress_percentage }}%</div>
                        </div>
                        <span class="inline-flex items-center gap-2 rounded-full border {{ $run->status === 'running' ? 'border-amber-200 bg-amber-50 text-amber-900' : 'border-sky-200 bg-sky-50 text-sky-800' }} px-3 py-1 text-xs font-medium">
                            <span class="h-2 w-2 animate-pulse rounded-full {{ $run->status === 'running' ? 'bg-amber-500' : 'bg-sky-500' }}"></span>
                            {{ ucfirst((string) $run->status) }}
                        </span>
                    </div>
                    <div class="mt-3 h-2 overflow-hidden rounded-full bg-slate-100">
                        <div class="h-full rounded-full {{ $run->status === 'running' ? 'bg-amber-500' : 'bg-sky-500' }}" style="width: {{ max(5, min(100, (int) $run->progress_percentage)) }}%;"></div>
                    </div>
                    <dl class="mt-3 grid gap-2 text-xs text-textSecondary sm:grid-cols-2 xl:grid-cols-4">
                        <div>
                            <dt class="text-textFaint">Queue</dt>
                            <dd class="mt-1 text-textPrimary">{{ $diagnostics['queue_name'] ?? 'generation' }}</dd>
                        </div>
                        <div>
                            <dt class="text-textFaint">Retry count</dt>
                            <dd class="mt-1 text-textPrimary">{{ (int) ($diagnostics['retry_count'] ?? 0) }}</dd>
                        </div>
                        <div>
                            <dt class="text-textFaint">Elapsed time</dt>
                            <dd class="mt-1 text-textPrimary">{{ $elapsed !== null ? $elapsed . 's' : 'n/a' }}</dd>
                        </div>
                        <div>
                            <dt class="text-textFaint">Failure reason</dt>
                            <dd class="mt-1 text-textPrimary">{{ $run->error_message ?: 'None' }}</dd>
                        </div>
                    </dl>
                </div>
            @endforeach
        </div>
    @endif

    <div class="mt-4 rounded-2xl border border-border/70 bg-white p-4">
        <div class="flex items-center justify-between gap-3">
            <div>
                <div class="text-sm font-medium text-textPrimary">Lifecycle activity</div>
                <p class="mt-1 text-xs text-textSecondary">Recent queue and review events for AI improvements.</p>
            </div>
        </div>

        @if ($recentEvents->isEmpty())
            <div class="mt-3 text-sm text-textSecondary">No lifecycle events recorded yet.</div>
        @else
            <div class="mt-3 max-h-48 overflow-y-auto">
                <table class="min-w-full text-left text-xs text-textSecondary">
                    <thead class="sticky top-0 bg-white text-[11px] uppercase tracking-wide text-textFaint">
                        <tr>
                            <th class="px-0 py-2 font-medium">Time</th>
                            <th class="px-3 py-2 font-medium">Event</th>
                            <th class="px-0 py-2 font-medium">Message</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border/60">
                        @foreach ($recentEvents as $event)
                            @php
                                $badgeClass = match ((string) $event->event_type) {
                                    'COMPLETED', 'APPLIED' => 'bg-emerald-50 text-emerald-700',
                                    'NO_CHANGES' => 'bg-amber-50 text-amber-800',
                                    'FAILED' => 'bg-rose-50 text-rose-700',
                                    'STARTED' => 'bg-amber-50 text-amber-800',
                                    default => 'bg-slate-100 text-slate-700',
                                };
                            @endphp
                            <tr>
                                <td class="py-2 pr-3 align-top text-textPrimary">{{ optional($event->created_at)->format('H:i:s') ?? 'n/a' }}</td>
                                <td class="px-3 py-2 align-top">
                                    <span class="inline-flex rounded-full px-2 py-1 text-[11px] font-medium {{ $badgeClass }}">
                                        {{ str_replace('_', ' ', (string) $event->event_type) }}
                                    </span>
                                </td>
                                <td class="py-2 align-top text-textPrimary">{{ $event->message }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</div>
