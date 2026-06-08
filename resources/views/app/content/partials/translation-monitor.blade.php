@php
    use App\Enums\SupportedLanguage;

    $monitorTranslations = collect($translationDebugger['translations'] ?? [])->values();
    $monitorEvents = collect($translationDebugger['events'] ?? [])->values();
    $variantByLocale = collect($localizedContentStatuses ?? [])
        ->filter(fn (array $status): bool => filled($status['locale'] ?? null))
        ->mapWithKeys(fn (array $status): array => [(string) $status['locale'] => $status['content'] ?? null]);
    $staleThresholdSeconds = max(
        5,
        (int) config('translation.processing_lock_ttl_seconds', config('argusly.translations.stale_lock_timeout_minutes', 10) * 60)
    );

    $formatAge = static function (?int $seconds): string {
        if ($seconds === null) {
            return 'n/a';
        }

        if ($seconds < 60) {
            return sprintf('%ds ago', $seconds);
        }

        if ($seconds < 3600) {
            return sprintf('%dm ago', (int) floor($seconds / 60));
        }

        if ($seconds < 86400) {
            return sprintf('%dh ago', (int) floor($seconds / 3600));
        }

        return sprintf('%dd ago', (int) floor($seconds / 86400));
    };

    $statusMeta = static function (array $translation, int $staleThresholdSeconds): array {
        $queueState = (string) ($translation['queue_state'] ?? '');
        $status = (string) ($translation['status'] ?? '');
        $heartbeatAge = isset($translation['heartbeat_age_seconds']) ? (int) $translation['heartbeat_age_seconds'] : null;
        $isStale = in_array($queueState, ['stale', 'stale_recovered'], true)
            || (filled($translation['stale_reason'] ?? null))
            || ($heartbeatAge !== null && $heartbeatAge > $staleThresholdSeconds);

        $code = match (true) {
            $status === 'completed' || $queueState === 'completed' => 'completed',
            $status === 'failed' || $queueState === 'failed' || $isStale => 'failed',
            $status === 'processing' || in_array($queueState, ['processing', 'running'], true) => 'running',
            $status === 'queued' || $queueState === 'queued' => 'queued',
            default => 'idle',
        };

        return [
            'code' => $code,
            'label' => match ($code) {
                'completed' => 'Completed',
                'failed' => 'Failed',
                'running' => 'Running',
                'queued' => 'Queued',
                default => 'Idle',
            },
            'is_stale' => $isStale,
            'classes' => match ($code) {
                'completed' => 'border-emerald-200 bg-emerald-50 text-emerald-800',
                'failed' => 'border-rose-200 bg-rose-50 text-rose-800',
                'running' => 'border-amber-200 bg-amber-50 text-amber-900',
                'queued' => 'border-sky-200 bg-sky-50 text-sky-800',
                default => 'border-slate-200 bg-slate-50 text-slate-700',
            },
        ];
    };

    $eventBadgeClasses = static function (string $eventType): string {
        return match ($eventType) {
            'DISPATCHED' => 'border-sky-200 bg-sky-50 text-sky-800',
            'JOB_STARTED', 'PROVIDER_REQUEST', 'PROVIDER_RESPONSE' => 'border-amber-200 bg-amber-50 text-amber-900',
            'COMPLETED' => 'border-emerald-200 bg-emerald-50 text-emerald-800',
            'FAILED' => 'border-rose-200 bg-rose-50 text-rose-800',
            'STATE_SNAPSHOT', 'QUEUE_STATE', 'LOCK_STATE' => 'border-slate-200 bg-slate-100 text-slate-700',
            default => 'border-slate-200 bg-slate-50 text-slate-700',
        };
    };
@endphp

<div class="mt-4 rounded border border-border bg-surfaceSubtle p-4">
    <div class="flex items-start justify-between gap-3">
        <div>
            <div class="text-sm font-semibold text-textPrimary">Translation Monitor</div>
            <p class="mt-1 text-xs text-textSecondary">Current queue, lock and recent lifecycle activity</p>
        </div>
        <a href="{{ request()->fullUrl() }}" class="rounded border border-border px-3 py-1.5 text-xs text-textPrimary hover:bg-background">
            Refresh status
        </a>
    </div>

    @if ($monitorTranslations->isEmpty())
        <div class="mt-4 rounded border border-dashed border-border bg-background px-4 py-5 text-sm text-textSecondary">
            No active translation lock.
        </div>
        <div class="mt-4 rounded border border-border bg-background">
            <div class="border-b border-border px-3 py-2 text-xs font-medium text-textPrimary">Recent events</div>
            @if ($monitorEvents->isEmpty())
                <div class="px-4 py-5 text-sm text-textSecondary">No translation events recorded yet.</div>
            @else
                <div class="max-h-60 overflow-y-auto">
                    <table class="min-w-full text-left text-xs text-textSecondary">
                        <thead class="sticky top-0 bg-background">
                            <tr class="border-b border-border text-[11px] uppercase tracking-wide text-textFaint">
                                <th class="px-3 py-2 font-medium">Time</th>
                                <th class="px-3 py-2 font-medium">Event</th>
                                <th class="px-3 py-2 font-medium">Message</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($monitorEvents as $event)
                                <tr class="border-b border-border last:border-b-0">
                                    <td class="whitespace-nowrap px-3 py-2 text-textPrimary">
                                        {{ $event->created_at?->format('H:i:s') ?? 'n/a' }}
                                    </td>
                                    <td class="px-3 py-2">
                                        <span class="inline-flex rounded-full border px-2 py-0.5 text-[11px] font-medium {{ $eventBadgeClasses((string) $event->event_type) }}">
                                            {{ $event->event_type }}
                                        </span>
                                    </td>
                                    <td class="px-3 py-2 text-textPrimary">{{ $event->message }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    @else
        <div class="mt-4 space-y-4">
            @foreach ($monitorTranslations as $debugTranslation)
                @php
                    $locale = (string) ($debugTranslation['locale'] ?? '');
                    $language = SupportedLanguage::tryFromString($locale);
                    $languageLabel = $language?->englishLabel() ?? strtoupper($locale);
                    $summary = $statusMeta($debugTranslation, $staleThresholdSeconds);
                    $heartbeatAge = isset($debugTranslation['heartbeat_age_seconds']) ? (int) $debugTranslation['heartbeat_age_seconds'] : null;
                    $heartbeatLabel = $formatAge($heartbeatAge);
                    $retryCount = max(0, (int) ($debugTranslation['retry_count'] ?? 0));
                    $targetVariant = $variantByLocale->get($locale);
                    $translationEvents = $monitorEvents
                        ->filter(function ($event) use ($locale, $monitorTranslations): bool {
                            $eventLocale = strtolower((string) ($event->locale ?? ''));

                            if ($eventLocale !== '') {
                                return $eventLocale === strtolower($locale);
                            }

                            return $monitorTranslations->count() === 1;
                        })
                        ->values();

                    $message = match (true) {
                        $summary['is_stale'] => $languageLabel . ' translation lock looks stale. You can clear it and retry.',
                        $summary['code'] === 'failed' && filled($debugTranslation['error_message'] ?? null) => $languageLabel . ' translation failed. Last error: ' . $debugTranslation['error_message'],
                        $summary['code'] === 'failed' => $languageLabel . ' translation failed.',
                        $summary['code'] === 'running' && $heartbeatAge !== null => $languageLabel . ' translation is running. Last heartbeat was ' . $heartbeatLabel . '.',
                        $summary['code'] === 'running' => $languageLabel . ' translation is running.',
                        $summary['code'] === 'queued' => $languageLabel . ' translation is queued and waiting for a worker.',
                        $summary['code'] === 'completed' => $languageLabel . ' translation completed.',
                        default => 'No active translation lock.',
                    };
                @endphp

                <section class="rounded border border-border bg-background p-4">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <div class="text-xs font-semibold tracking-wide text-textSecondary">{{ strtoupper($locale) }}</div>
                            <div class="mt-1 text-sm text-textPrimary">{{ $message }}</div>
                        </div>
                        @if ($summary['is_stale'])
                            <span class="rounded-full border border-amber-200 bg-amber-50 px-2.5 py-1 text-[11px] font-medium text-amber-900">
                                Stale lock warning
                            </span>
                        @endif
                    </div>

                    <div class="mt-4 grid gap-2 sm:grid-cols-2 xl:grid-cols-4">
                        <div class="rounded border border-border bg-surfaceSubtle px-3 py-2">
                            <div class="text-[11px] uppercase tracking-wide text-textFaint">Status</div>
                            <div class="mt-1">
                                <span class="inline-flex rounded-full border px-2.5 py-1 text-xs font-medium {{ $summary['classes'] }}">
                                    {{ $summary['label'] }}
                                </span>
                            </div>
                        </div>
                        <div class="rounded border border-border bg-surfaceSubtle px-3 py-2">
                            <div class="text-[11px] uppercase tracking-wide text-textFaint">Locale</div>
                            <div class="mt-1 text-sm font-medium text-textPrimary">{{ strtoupper($locale) }}</div>
                        </div>
                        <div class="rounded border border-border bg-surfaceSubtle px-3 py-2">
                            <div class="text-[11px] uppercase tracking-wide text-textFaint">Attempts</div>
                            <div class="mt-1 text-sm font-medium text-textPrimary">{{ $retryCount }} / 3</div>
                        </div>
                        <div class="rounded border border-border bg-surfaceSubtle px-3 py-2">
                            <div class="text-[11px] uppercase tracking-wide text-textFaint">Heartbeat</div>
                            <div class="mt-1 text-sm font-medium text-textPrimary">{{ $heartbeatLabel }}</div>
                        </div>
                    </div>

                    <div class="mt-4 flex flex-wrap gap-2">
                        @if ($targetVariant)
                            <a href="{{ route('app.content.show', $targetVariant) }}" class="rounded border border-border px-3 py-2 text-xs text-textPrimary hover:bg-surfaceSubtle">
                                Open translated draft
                            </a>
                        @endif

                        @if (in_array($summary['code'], ['failed'], true) || $summary['is_stale'])
                            <form method="POST" action="{{ route('app.content.translate', $localizedContentSource ?? $content) }}">
                                @csrf
                                <input type="hidden" name="target_locale" value="{{ $locale }}">
                                <button class="rounded border border-border px-3 py-2 text-xs text-textPrimary hover:bg-surfaceSubtle">
                                    Retry translation
                                </button>
                            </form>
                        @endif

                        @can('admin-area-superadmin')
                            @if (($summary['is_stale'] || $summary['code'] === 'failed') && filled($debugTranslation['id'] ?? null))
                                <form method="POST" action="{{ route('admin.queues.translations.release-lock', $debugTranslation['id']) }}" onsubmit="return confirm('Clear this translation lock?');">
                                    @csrf
                                    <button class="rounded border border-amber-300 px-3 py-2 text-xs text-amber-900 hover:bg-amber-50">
                                        Clear stale lock
                                    </button>
                                </form>
                            @endif
                        @endcan

                        <details class="group">
                            <summary class="list-none rounded border border-border px-3 py-2 text-xs text-textPrimary hover:bg-surfaceSubtle">
                                View raw debug
                            </summary>
                            <div class="mt-3 rounded border border-border bg-surfaceSubtle p-3">
                                <div class="text-xs font-medium text-textPrimary">Technical details</div>
                                <dl class="mt-3 grid gap-x-4 gap-y-2 text-xs text-textSecondary sm:grid-cols-2">
                                    <div>
                                        <dt class="text-textFaint">Queue state</dt>
                                        <dd class="mt-1 text-textPrimary">{{ $debugTranslation['queue_state'] ?? 'n/a' }}</dd>
                                    </div>
                                    <div>
                                        <dt class="text-textFaint">Job UUID</dt>
                                        <dd class="mt-1 break-all text-textPrimary">{{ $debugTranslation['job_uuid'] ?? 'n/a' }}</dd>
                                    </div>
                                    <div>
                                        <dt class="text-textFaint">Heartbeat age</dt>
                                        <dd class="mt-1 text-textPrimary">{{ $heartbeatLabel }}</dd>
                                    </div>
                                    <div>
                                        <dt class="text-textFaint">Retry count</dt>
                                        <dd class="mt-1 text-textPrimary">{{ $retryCount }}</dd>
                                    </div>
                                    <div>
                                        <dt class="text-textFaint">Last error</dt>
                                        <dd class="mt-1 text-textPrimary">{{ $debugTranslation['error_message'] ?? 'None' }}</dd>
                                    </div>
                                    <div>
                                        <dt class="text-textFaint">Trace ID</dt>
                                        <dd class="mt-1 break-all text-textPrimary">{{ $translationEvents->last()?->trace_id ?? 'n/a' }}</dd>
                                    </div>
                                </dl>
                            </div>
                        </details>
                    </div>

                    <div class="mt-4 rounded border border-border">
                        <div class="border-b border-border px-3 py-2 text-xs font-medium text-textPrimary">Recent events</div>
                        @if ($translationEvents->isEmpty())
                            <div class="px-3 py-4 text-sm text-textSecondary">No translation events recorded yet.</div>
                        @else
                            <div class="max-h-60 overflow-y-auto">
                                <table class="min-w-full text-left text-xs text-textSecondary">
                                    <thead class="sticky top-0 bg-background">
                                        <tr class="border-b border-border text-[11px] uppercase tracking-wide text-textFaint">
                                            <th class="px-3 py-2 font-medium">Time</th>
                                            <th class="px-3 py-2 font-medium">Event</th>
                                            <th class="px-3 py-2 font-medium">Message</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach ($translationEvents as $event)
                                            <tr class="border-b border-border last:border-b-0">
                                                <td class="whitespace-nowrap px-3 py-2 text-textPrimary">
                                                    {{ $event->created_at?->format('H:i:s') ?? 'n/a' }}
                                                </td>
                                                <td class="px-3 py-2">
                                                    <span class="inline-flex rounded-full border px-2 py-0.5 text-[11px] font-medium {{ $eventBadgeClasses((string) $event->event_type) }}">
                                                        {{ $event->event_type }}
                                                    </span>
                                                </td>
                                                <td class="px-3 py-2 text-textPrimary">{{ $event->message }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @endif
                    </div>
                </section>
            @endforeach
        </div>
    @endif
</div>
