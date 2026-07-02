@extends('layouts.admin', ['title' => 'Queues'])

@php
    $hasStuckQueues = collect($queue_stats['queues'] ?? [])->contains(fn ($queue) => (bool) ($queue['is_stuck'] ?? false));
    $focusFailed = (bool) request()->boolean('focus_failed');
    $focusTranslations = (bool) request()->boolean('focus_translations');
    $translationCollection = $translation_rows->getCollection();
    $staleTranslationCount = $translationCollection->where('is_stale', true)->count();
@endphp

@section('pageHeader')
    <x-page-header title="Queues">
        @if ($hasStuckQueues)
            <x-slot:actions>
                <span class="rounded-full border border-amber-500/30 bg-amber-500/10 px-2.5 py-1 text-xs font-medium text-amber-800">Stuck queue detected</span>
            </x-slot:actions>
        @endif
    </x-page-header>
@endsection

@section('pageDescription')
    <x-page-description>Database queue overview, pending job controls, and failed job recovery.</x-page-description>
@endsection

@section('primaryActions')
    <a href="{{ route('admin.system-health.index') }}" class="rounded border border-border px-3 py-2 text-sm text-textPrimary hover:bg-surfaceSubtle">System health</a>
    <a href="{{ route('admin.queues.index', ['focus_translations' => 1]) }}#translations" class="rounded border border-border px-3 py-2 text-sm text-textPrimary hover:bg-surfaceSubtle">Translations</a>
    <a href="{{ route('admin.queues.index', ['focus_failed' => 1]) }}#failed-jobs" class="rounded border border-border px-3 py-2 text-sm text-textPrimary hover:bg-surfaceSubtle">Failed jobs</a>
@endsection

@section('metricSection')
    <x-metric-section>
        <x-metric-card label="Worker status" :value="$worker_alive ? 'Alive' : 'No recent heartbeat'" :tone="$worker_alive ? 'success' : 'danger'">
            <p>
                Last heartbeat:
                {{ $worker_last_heartbeat_at ? $worker_last_heartbeat_at->format('Y-m-d H:i:s') : 'never' }}
            </p>
            <p>Cache store: {{ $worker_heartbeat_cache_store }}</p>
        </x-metric-card>
        <x-metric-card label="Pending jobs" :value="$queue_stats['total_pending_jobs'] ?? 'N/A'" :helper="'Connection: '.$queue_connection.' ('.($queue_configured ? 'configured' : 'missing').')'" />
        <x-metric-card label="Translations" :value="$translation_rows->total()" :helper="$staleTranslationCount.' stale lock(s) on this page. Failed jobs in current queue view: '.($failed_jobs_count ?? 'N/A')" />
        <x-metric-card label="Processing rate" :value="$queue_stats['processing_rate_per_minute'] !== null ? number_format((float) $queue_stats['processing_rate_per_minute'], 2).' / min' : 'N/A'" helper="Computed from recent queue snapshots captured in admin." />
    </x-metric-section>
@endsection

@section('content')
    @if (session('status'))
        <x-alert class="mb-4">{{ session('status') }}</x-alert>
    @endif
    @if ($errors->has('queues'))
        <div class="mb-4 rounded-md border border-rose-500/30 bg-rose-500/10 px-3 py-2 text-sm text-rose-800">{{ $errors->first('queues') }}</div>
    @endif

    <div class="mb-6 rounded-lg border border-border bg-surface p-4">
        <div class="mb-4 flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
            <div>
                <h2 class="text-sm font-semibold text-textPrimary">Queue overview</h2>
                <p class="mt-1 text-sm text-textSecondary">Pending counts, oldest jobs, failed backlog, and stuck-queue detection per queue.</p>
            </div>
        </div>

        <x-data-table label="Queue overview" description="Pending counts, failed backlog, queue health, oldest pending jobs, processing rate, and queue recovery actions." density="compact">
                <x-data-table.header>
                <x-data-table.row>
                    <x-data-table.cell heading>Queue</x-data-table.cell>
                    <x-data-table.cell heading>Pending</x-data-table.cell>
                    <x-data-table.cell heading>Failed</x-data-table.cell>
                    <x-data-table.cell heading>Status</x-data-table.cell>
                    <x-data-table.cell heading>Actions</x-data-table.cell>
                    <x-data-table.cell heading>Oldest pending</x-data-table.cell>
                    <x-data-table.cell heading>Rate</x-data-table.cell>
                </x-data-table.row>
                </x-data-table.header>
                <tbody>
                @forelse (($queue_stats['queues'] ?? []) as $queue)
                    <x-data-table.row>
                        <x-data-table.cell label="Queue" class="font-medium text-textPrimary">{{ $queue['name'] }}</x-data-table.cell>
                        <x-data-table.cell label="Pending" class="text-textSecondary">{{ $queue['pending_count'] }}</x-data-table.cell>
                        <x-data-table.cell label="Failed" class="text-textSecondary">
                            @if ((int) $queue['failed_count'] > 0)
                                <a href="{{ route('admin.queues.index', array_merge(request()->query(), ['queue' => $queue['name']])) }}#failed-jobs" class="font-medium text-danger hover:underline">{{ $queue['failed_count'] }}</a>
                            @else
                                {{ $queue['failed_count'] }}
                            @endif
                        </x-data-table.cell>
                        <x-data-table.cell label="Status">
                            @if ($queue['is_stuck'])
                                <x-data-table.badge tone="warning" label="Stuck" />
                                @if ($queue['oldest_unreserved_job_at'])
                                    <div class="mt-1 text-xs text-textFaint">Oldest waiting job: {{ $queue['oldest_unreserved_job_at']->diffForHumans() }}</div>
                                @endif
                            @elseif ((int) $queue['failed_count'] > 0)
                                <x-data-table.badge tone="danger" label="Failed backlog" />
                            @else
                                <x-data-table.badge tone="success" label="Healthy" />
                            @endif
                        </x-data-table.cell>
                        <x-data-table.cell label="Actions">
                            <x-data-table.actions align="start">
                                <form method="POST" action="{{ route('admin.queues.pending.flush', request()->query()) }}" onsubmit="return confirm('Flush all pending jobs from queue {{ $queue['name'] }}?');">
                                    @csrf
                                    <input type="hidden" name="queue" value="{{ $queue['name'] }}">
                                    <button type="submit" class="rounded border border-danger/30 px-2 py-1 text-xs text-danger">Flush queue</button>
                                </form>
                                <form method="POST" action="{{ route('admin.queues.retry-all', request()->query()) }}" onsubmit="return confirm('Retry all failed jobs for queue {{ $queue['name'] }}?');">
                                    @csrf
                                    <input type="hidden" name="queue" value="{{ $queue['name'] }}">
                                    <button type="submit" class="rounded border border-border px-2 py-1 text-xs">Retry failed</button>
                                </form>
                                <form method="POST" action="{{ route('admin.queues.destroy-bulk', request()->query()) }}" onsubmit="return confirm('Delete all failed job records for queue {{ $queue['name'] }}?');">
                                    @csrf
                                    <input type="hidden" name="queue" value="{{ $queue['name'] }}">
                                    <button type="submit" class="rounded border border-danger/30 px-2 py-1 text-xs text-danger">Delete failed</button>
                                </form>
                            </x-data-table.actions>
                        </x-data-table.cell>
                        <x-data-table.cell label="Oldest pending" class="text-textSecondary">
                            @if ($queue['oldest_job_at'])
                                {{ $queue['oldest_job_at']->format('Y-m-d H:i:s') }}
                                <div class="text-xs text-textFaint">{{ $queue['oldest_job_at']->diffForHumans() }}</div>
                            @else
                                No pending jobs
                            @endif
                        </x-data-table.cell>
                        <x-data-table.cell label="Rate" class="text-textSecondary">
                            {{ $queue['processing_rate_per_minute'] !== null ? number_format((float) $queue['processing_rate_per_minute'], 2) . ' / min' : 'N/A' }}
                        </x-data-table.cell>
                    </x-data-table.row>
                @empty
                    <x-data-table.empty colspan="7" title="No queue data available" />
                @endforelse
                </tbody>
        </x-data-table>
    </div>

    <div class="mb-6 grid gap-4 xl:grid-cols-2">
        <div class="rounded-lg border border-border bg-surface p-4">
            <h2 class="text-sm font-semibold text-textPrimary">Maintenance</h2>
            <p class="mt-1 text-sm text-textSecondary">Bulk cleanup for pending and failed records.</p>
            <form method="POST" action="{{ route('admin.queues.delete-older', request()->query()) }}" class="mt-4 grid gap-3 md:grid-cols-3" onsubmit="return confirm('Delete jobs older than the selected age?');">
                @csrf
                <div>
                    <label class="mb-1 block text-xs text-textSecondary">Scope</label>
                    <select name="scope" class="pl-select bg-background">
                        <option value="pending">Pending only</option>
                        <option value="failed">Failed only</option>
                        <option value="all">Pending and failed</option>
                    </select>
                </div>
                <div>
                    <label class="mb-1 block text-xs text-textSecondary">Older than (hours)</label>
                    <input type="number" min="1" max="720" step="1" name="hours" value="24" class="pl-input w-full">
                </div>
                <div class="flex items-end">
                    <button type="submit" class="rounded border border-danger/30 px-3 py-2 text-sm text-danger hover:bg-danger/5">Delete old jobs</button>
                </div>
            </form>
        </div>

        <div class="rounded-lg border border-border bg-surface p-4">
            <h2 class="text-sm font-semibold text-textPrimary">Failed job bulk retry</h2>
            <p class="mt-1 text-sm text-textSecondary">Retry every failed record, or restrict the action to a queue.</p>
            <form method="POST" action="{{ route('admin.queues.retry-all', request()->query()) }}" class="mt-4 grid gap-3 md:grid-cols-[1fr_auto]" onsubmit="return confirm('Retry all failed jobs that match this request?');">
                @csrf
                <div>
                    <label class="mb-1 block text-xs text-textSecondary">Queue</label>
                    <input type="text" name="queue" list="queue-options" class="pl-input w-full" placeholder="Leave blank for all queues">
                </div>
                <div class="flex items-end">
                    <button type="submit" class="rounded border border-border px-3 py-2 text-sm text-textPrimary hover:bg-surfaceSubtle">Retry failed jobs</button>
                </div>
            </form>
        </div>
    </div>

    <div id="translations" class="mb-6 rounded-lg border border-border bg-surface p-4 {{ $focusTranslations ? 'ring-2 ring-primary/20' : '' }}">
        <div class="mb-4 flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
            <div>
                <h2 class="text-sm font-semibold text-textPrimary">Translations</h2>
                <p class="mt-1 text-sm text-textSecondary">Translation state from the database, linked queue failures, and stale lock recovery.</p>
            </div>
        </div>

        <form method="GET" action="{{ route('admin.queues.index') }}" class="mb-4 rounded-lg border border-border bg-background p-4">
            <input type="hidden" name="focus_translations" value="1">
            <div class="grid gap-3 md:grid-cols-6">
                <div>
                    <label class="mb-1 block text-xs text-textSecondary">Organization</label>
                    <input type="text" name="translation_organization" value="{{ $translation_filters['organization'] ?? '' }}" class="pl-input w-full" placeholder="id or name">
                </div>
                <div>
                    <label class="mb-1 block text-xs text-textSecondary">Site</label>
                    <input type="text" name="translation_site" value="{{ $translation_filters['site'] ?? '' }}" class="pl-input w-full" placeholder="id or name">
                </div>
                <div>
                    <label class="mb-1 block text-xs text-textSecondary">Content ID</label>
                    <input type="text" name="translation_content_id" value="{{ $translation_filters['content_id'] ?? '' }}" class="pl-input w-full" placeholder="uuid">
                </div>
                <div>
                    <label class="mb-1 block text-xs text-textSecondary">Locale</label>
                    <input type="text" name="translation_locale" value="{{ $translation_filters['locale'] ?? '' }}" class="pl-input w-full" placeholder="nl">
                </div>
                <div>
                    <label class="mb-1 block text-xs text-textSecondary">Status</label>
                    <select name="translation_status" class="pl-select bg-background">
                        <option value="">Any status</option>
                        <option value="queued" @selected(($translation_filters['status'] ?? '') === 'queued')>Queued</option>
                        <option value="processing" @selected(($translation_filters['status'] ?? '') === 'processing')>Processing</option>
                        <option value="completed" @selected(($translation_filters['status'] ?? '') === 'completed')>Completed</option>
                        <option value="failed" @selected(($translation_filters['status'] ?? '') === 'failed')>Failed</option>
                        <option value="stale" @selected(($translation_filters['status'] ?? '') === 'stale')>Stale</option>
                    </select>
                </div>
                <div class="flex items-end gap-3">
                    <label class="inline-flex items-center gap-2 text-sm text-textSecondary">
                        <input type="checkbox" name="translation_stale_only" value="1" @checked($translation_filters['stale_only'] ?? false) class="h-4 w-4 rounded border-border text-primary">
                        Stale only
                    </label>
                    <label class="inline-flex items-center gap-2 text-sm text-textSecondary">
                        <input type="checkbox" name="translation_failed_only" value="1" @checked($translation_filters['failed_only'] ?? false) class="h-4 w-4 rounded border-border text-primary">
                        Failed only
                    </label>
                </div>
            </div>
            <div class="mt-3 flex flex-wrap gap-2">
                <button type="submit" class="rounded border border-border px-3 py-2 text-sm text-textPrimary hover:bg-surfaceSubtle">Apply filters</button>
                <a href="{{ route('admin.queues.index', ['focus_translations' => 1]) }}#translations" class="rounded border border-border px-3 py-2 text-sm text-textPrimary hover:bg-surfaceSubtle">Reset</a>
            </div>
        </form>

        @can('admin-area-superadmin')
            <div class="mb-4 flex flex-wrap gap-2">
                <form method="POST" action="{{ route('admin.queues.translations.repair-stale-locks', request()->query()) }}" onsubmit="return confirm('Run a dry run for stale translation locks?');">
                    @csrf
                    <button type="submit" class="rounded border border-border px-3 py-2 text-sm text-textPrimary hover:bg-surfaceSubtle">Dry run repair</button>
                </form>
                <form method="POST" action="{{ route('admin.queues.translations.repair-stale-locks', request()->query()) }}" onsubmit="return confirm('Repair stale translation locks now?');">
                    @csrf
                    <input type="hidden" name="apply" value="1">
                    <button type="submit" class="rounded border border-danger/30 px-3 py-2 text-sm text-danger hover:bg-danger/5">Repair stale translation locks</button>
                </form>
            </div>
        @endcan

        @if ($translation_rows->isEmpty())
            <p class="text-sm text-textSecondary">No translations found for the selected filters.</p>
        @else
            <x-data-table label="Translation queue state" description="Translation records, locale pairs, queue lock state, attempts, errors, credits, timestamps, and recovery actions." density="compact">
                    <x-data-table.header>
                    <x-data-table.row>
                        <x-data-table.cell heading>Content</x-data-table.cell>
                        <x-data-table.cell heading>Locales</x-data-table.cell>
                        <x-data-table.cell heading>Status</x-data-table.cell>
                        <x-data-table.cell heading>Lock</x-data-table.cell>
                        <x-data-table.cell heading>Attempts</x-data-table.cell>
                        <x-data-table.cell heading>Last error</x-data-table.cell>
                        <x-data-table.cell heading>Credits</x-data-table.cell>
                        <x-data-table.cell heading>Updated</x-data-table.cell>
                        <x-data-table.cell heading>Actions</x-data-table.cell>
                    </x-data-table.row>
                    </x-data-table.header>
                    <tbody>
                    @foreach ($translation_rows as $translation)
                        <x-data-table.row @class([$translation['is_stale'] ? 'bg-amber-500/5' : ''])>
                            <x-data-table.cell label="Content" class="text-textPrimary">
                                <div class="font-medium">{{ $translation['content_title'] }}</div>
                                <div class="mt-1 text-xs text-textFaint">Content {{ $translation['content_id'] }}</div>
                                <div class="text-xs text-textFaint">Family {{ $translation['family_id'] }}</div>
                                <div class="text-xs text-textFaint">{{ $translation['organization'] ?? 'Unknown org' }} · {{ $translation['site'] ?? 'Unknown site' }}</div>
                                @if ($translation['content_url'])
                                    <a href="{{ $translation['content_url'] }}" class="mt-1 inline-block text-xs text-link hover:underline">Open content</a>
                                @endif
                            </x-data-table.cell>
                            <x-data-table.cell label="Locales" class="text-textSecondary">
                                <div>{{ strtoupper($translation['source_locale']) }} → {{ strtoupper($translation['target_locale']) }}</div>
                                <div class="mt-1 text-xs text-textFaint">Translation {{ $translation['id'] }}</div>
                            </x-data-table.cell>
                            <x-data-table.cell label="Status">
                                <x-data-table.badge :tone="match ($translation['status_tone'] ?? 'slate') {
                                    'green' => 'success',
                                    'amber' => 'warning',
                                    'red' => 'danger',
                                    'sky' => 'info',
                                    default => 'neutral',
                                }" :label="$translation['display_state']" />
                                <div class="mt-1 text-xs text-textFaint">Raw status: {{ $translation['status'] }}</div>
                                @if ($translation['recovery_hint'])
                                    <div class="mt-2 text-xs text-amber-800">{{ $translation['recovery_hint'] }}</div>
                                @endif
                                @if ($translation['failed_jobs_count'] > 0)
                                    <div class="mt-2 text-xs text-rose-700">{{ $translation['failed_jobs_count'] }} linked failed job(s)</div>
                                @endif
                                @if (($translation['pending_jobs_count'] ?? 0) > 0)
                                    <div class="mt-1 text-xs text-emerald-700">{{ $translation['pending_jobs_count'] }} pending queue job(s)</div>
                                @endif
                                @if ($translation['stale_reason'])
                                    <div class="mt-1 text-xs text-amber-800">{{ str_replace('_', ' ', $translation['stale_reason']) }}</div>
                                @endif
                            </x-data-table.cell>
                            <x-data-table.cell label="Lock" class="text-textSecondary">
                                <div>{{ $translation['locked_at']?->format('Y-m-d H:i:s') ?? 'n/a' }}</div>
                                <div class="mt-1 text-xs text-textFaint">Job {{ $translation['locked_by_job_id'] ?? 'n/a' }}</div>
                                <div class="mt-1 text-xs text-textFaint">UUID {{ $translation['job_uuid'] ?? 'n/a' }}</div>
                                <div class="mt-1 text-xs text-textFaint">Started {{ $translation['processing_started_at']?->format('Y-m-d H:i:s') ?? 'n/a' }}</div>
                                <div class="mt-1 text-xs text-textFaint">Heartbeat {{ $translation['processing_last_heartbeat_at']?->diffForHumans() ?? 'n/a' }}</div>
                                @if ($translation['processing_failed_at'])
                                    <div class="mt-1 text-xs text-rose-700">Failed {{ $translation['processing_failed_at']->format('Y-m-d H:i:s') }}</div>
                                @endif
                                @if ($translation['latest_failed_job'])
                                    <div class="mt-1 text-xs text-textFaint">Failed job {{ $translation['latest_failed_job']['id'] }}</div>
                                @endif
                            </x-data-table.cell>
                            <x-data-table.cell label="Attempts" class="text-textSecondary">{{ $translation['attempts'] }}</x-data-table.cell>
                            <x-data-table.cell label="Last error" class="text-xs text-textSecondary">{{ $translation['last_error'] ?? 'None' }}</x-data-table.cell>
                            @if (($translation['failure_reason'] ?? null) === 'insufficient_credits')
                                <x-data-table.cell label="Credits" class="text-xs text-textSecondary">
                                    <div>Required {{ (int) ($translation['required_credits'] ?? 0) }}</div>
                                    <div class="mt-1 text-textFaint">Available {{ (int) ($translation['available_credits'] ?? 0) }}</div>
                                    <div class="mt-1 text-textFaint">Balance {{ (int) ($translation['credit_balance'] ?? 0) }}</div>
                                    <div class="mt-1 text-textFaint">Plan {{ $translation['plan_name'] ?? 'n/a' }}</div>
                                    <div class="mt-1 text-textFaint">Entitlement {{ $translation['entitlement_source'] ?? 'n/a' }}</div>
                                </x-data-table.cell>
                            @else
                                <x-data-table.cell label="Credits" class="text-xs text-textFaint">n/a</x-data-table.cell>
                            @endif
                            <x-data-table.cell label="Updated" class="text-textSecondary">
                                <div>{{ $translation['created_at']?->format('Y-m-d H:i:s') ?? 'n/a' }}</div>
                                <div class="mt-1 text-xs text-textFaint">Updated {{ $translation['updated_at']?->format('Y-m-d H:i:s') ?? 'n/a' }}</div>
                            </x-data-table.cell>
                            <x-data-table.cell label="Actions">
                                <x-data-table.actions align="start">
                                    @can('admin-area-superadmin')
                                        @if ($translation['can_recover'])
                                            <form method="POST" action="{{ route('admin.queues.translations.retry', [$translation['id']] + request()->query()) }}">
                                                @csrf
                                                <button type="submit" class="rounded border border-sky-300 px-2 py-1 text-xs text-sky-800">Retry translation</button>
                                            </form>
                                            <form method="POST" action="{{ route('admin.queues.translations.force-reset-and-retry', [$translation['id']] + request()->query()) }}" onsubmit="return confirm('Force reset this translation and retry it?');">
                                                @csrf
                                                <button type="submit" class="rounded border border-amber-300 px-2 py-1 text-xs text-amber-800">Force reset + retry</button>
                                            </form>
                                            <form method="POST" action="{{ route('admin.queues.translations.release-lock', [$translation['id']] + request()->query()) }}" onsubmit="return confirm('Release this translation lock?');">
                                                @csrf
                                                <button type="submit" class="rounded border border-amber-300 px-2 py-1 text-xs text-amber-800">Release lock</button>
                                            </form>
                                            <form method="POST" action="{{ route('admin.queues.translations.mark-failed', [$translation['id']] + request()->query()) }}" onsubmit="return confirm('Mark this translation as failed?');">
                                                @csrf
                                                <button type="submit" class="rounded border border-border px-2 py-1 text-xs">Mark as failed</button>
                                            </form>
                                            @if ($translation['latest_failed_job'])
                                                <form method="POST" action="{{ route('admin.queues.translations.failed-job.retry', [$translation['id']] + request()->query()) }}" onsubmit="return confirm('Retry the linked failed queue job?');">
                                                    @csrf
                                                    <input type="hidden" name="failed_job_id" value="{{ $translation['latest_failed_job']['id'] }}">
                                                    <button type="submit" class="rounded border border-border px-2 py-1 text-xs">Retry failed job</button>
                                                </form>
                                                <form method="POST" action="{{ route('admin.queues.translations.failed-job.delete', [$translation['id']] + request()->query()) }}" onsubmit="return confirm('Delete the linked failed queue job?');">
                                                    @csrf
                                                    @method('DELETE')
                                                    <input type="hidden" name="failed_job_id" value="{{ $translation['latest_failed_job']['id'] }}">
                                                    <button type="submit" class="rounded border border-rose-300 px-2 py-1 text-xs text-rose-800">Delete failed job</button>
                                                </form>
                                            @endif
                                        @else
                                            <span class="text-xs text-emerald-700">Completed translations do not need recovery.</span>
                                        @endif
                                    @else
                                        <span class="text-xs text-textFaint">View only</span>
                                    @endcan
                                </x-data-table.actions>
                            </x-data-table.cell>
                        </x-data-table.row>
                    @endforeach
                    </tbody>
                <x-slot:pagination>{{ $translation_rows->onEachSide(1)->links() }}</x-slot:pagination>
            </x-data-table>
        @endif
    </div>

    <div class="mb-6 rounded-lg border border-border bg-surface p-4">
        <div class="mb-4 flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
            <div>
                <h2 class="text-sm font-semibold text-textPrimary">Pending jobs</h2>
                <p class="mt-1 text-sm text-textSecondary">Paginated database queue backlog with delete and requeue controls.</p>
            </div>
        </div>

        <form method="GET" action="{{ route('admin.queues.index') }}" class="mb-4 grid gap-3 md:grid-cols-5">
            <div>
                <label class="mb-1 block text-xs text-textSecondary">Queue</label>
                <input type="text" name="pending_queue" list="queue-options" value="{{ $pending_filters['queue'] ?? '' }}" class="pl-input w-full" placeholder="default">
            </div>
            <div>
                <label class="mb-1 block text-xs text-textSecondary">Job class</label>
                <input type="text" name="pending_job_class" value="{{ $pending_filters['job_class'] ?? '' }}" class="pl-input w-full" placeholder="App\\Jobs\\...">
            </div>
            <div>
                <label class="mb-1 block text-xs text-textSecondary">Age range</label>
                <select name="pending_age_range" class="pl-select bg-background">
                    <option value="">Any age</option>
                    <option value="10m" @selected(($pending_filters['age_range'] ?? '') === '10m')>Last 10 minutes</option>
                    <option value="1h" @selected(($pending_filters['age_range'] ?? '') === '1h')>Last hour</option>
                    <option value="24h" @selected(($pending_filters['age_range'] ?? '') === '24h')>Last 24 hours</option>
                    <option value="7d" @selected(($pending_filters['age_range'] ?? '') === '7d')>Last 7 days</option>
                </select>
            </div>
            <div>
                <label class="mb-1 block text-xs text-textSecondary">Org or Site</label>
                <input type="text" name="pending_org_site" value="{{ $pending_filters['org_site'] ?? '' }}" class="pl-input w-full" placeholder="org id or site id">
            </div>
            <div>
                <label class="mb-1 block text-xs text-textSecondary">Search</label>
                <input type="text" name="pending_search" value="{{ $pending_filters['search'] ?? '' }}" class="pl-input w-full" placeholder="id, queue, payload text">
            </div>
            <div class="flex items-end gap-2 md:col-span-5">
                <button type="submit" class="rounded border border-border px-3 py-2 text-sm text-textPrimary hover:bg-surfaceSubtle">Apply filters</button>
                <a href="{{ route('admin.queues.index', $focusFailed ? ['focus_failed' => 1] : []) }}" class="rounded border border-border px-3 py-2 text-sm text-textPrimary hover:bg-surfaceSubtle">Reset</a>
            </div>
        </form>

        <datalist id="queue-options">
            @foreach (($queue_options ?? []) as $queueOption)
                <option value="{{ $queueOption }}"></option>
            @endforeach
        </datalist>

        @if ($pending_jobs->isEmpty())
            <p class="text-sm text-textSecondary">No pending jobs found for the selected filters.</p>
        @else
            <x-data-table label="Pending queue jobs" description="Pending queue jobs with queue, class, attempts, timestamps, organization or site context, and requeue/delete actions." density="compact">
                    <x-data-table.header>
                    <x-data-table.row>
                        <x-data-table.cell heading>ID</x-data-table.cell>
                        <x-data-table.cell heading>Queue</x-data-table.cell>
                        <x-data-table.cell heading>Job</x-data-table.cell>
                        <x-data-table.cell heading>Attempts</x-data-table.cell>
                        <x-data-table.cell heading>Created</x-data-table.cell>
                        <x-data-table.cell heading>Age</x-data-table.cell>
                        <x-data-table.cell heading>Org/Site</x-data-table.cell>
                        <x-data-table.cell heading>Actions</x-data-table.cell>
                    </x-data-table.row>
                    </x-data-table.header>
                    <tbody>
                    @foreach ($pending_jobs as $job)
                        <x-data-table.row>
                            <x-data-table.cell label="ID" class="text-textSecondary">{{ $job['id'] }}</x-data-table.cell>
                            <x-data-table.cell label="Queue" class="text-textSecondary">{{ $job['queue'] }}</x-data-table.cell>
                            <x-data-table.cell label="Job" class="text-textPrimary">{{ $job['job_class'] }}</x-data-table.cell>
                            <x-data-table.cell label="Attempts" class="text-textSecondary">{{ $job['attempts'] }}</x-data-table.cell>
                            <x-data-table.cell label="Created" class="text-textSecondary">
                                {{ $job['created_at']?->format('Y-m-d H:i:s') ?? 'Unknown' }}
                                @if ($job['reserved_at'])
                                    <div class="text-xs text-textFaint">Reserved {{ $job['reserved_at']->diffForHumans() }}</div>
                                @endif
                            </x-data-table.cell>
                            <x-data-table.cell label="Age" class="text-textSecondary">{{ $job['age_human'] ?? 'Unknown' }}</x-data-table.cell>
                            <x-data-table.cell label="Org/Site" class="text-textSecondary">{{ $job['org_site'] }}</x-data-table.cell>
                            <x-data-table.cell label="Actions">
                                <x-data-table.actions align="start">
                                    <a href="{{ route('admin.queues.pending.show', [$job['id']] + request()->query()) }}" class="rounded border border-border px-2 py-1 text-xs">Details</a>
                                    <form method="POST" action="{{ route('admin.queues.pending.requeue', [$job['id']] + request()->query()) }}" class="flex flex-wrap gap-2">
                                        @csrf
                                        <input type="text" name="queue" list="queue-options" value="{{ $job['queue'] }}" class="pl-input min-w-32" aria-label="Requeue target">
                                        <button type="submit" class="rounded border border-border px-2 py-1 text-xs">Requeue</button>
                                    </form>
                                    <form method="POST" action="{{ route('admin.queues.pending.destroy', [$job['id']] + request()->query()) }}" onsubmit="return confirm('Delete pending job {{ $job['id'] }}?');">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="rounded border border-danger/30 px-2 py-1 text-xs text-danger">Delete</button>
                                    </form>
                                </x-data-table.actions>
                            </x-data-table.cell>
                        </x-data-table.row>
                    @endforeach
                    </tbody>
                <x-slot:pagination>{{ $pending_jobs->onEachSide(1)->links() }}</x-slot:pagination>
            </x-data-table>
        @endif
    </div>

    <div id="failed-jobs" class="rounded-lg border border-border bg-surface p-4 {{ $focusFailed ? 'ring-2 ring-primary/20' : '' }}">
        <div class="mb-4 flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
            <div>
                <h2 class="text-sm font-semibold text-textPrimary">Failed jobs</h2>
                <p class="mt-1 text-sm text-textSecondary">Retry, inspect, and delete failed database queue records.</p>
            </div>
        </div>

        <form method="GET" action="{{ route('admin.queues.index') }}" class="mb-6 rounded-lg border border-border bg-background p-4">
            <input type="hidden" name="focus_failed" value="1">
            <div class="grid gap-3 md:grid-cols-6">
                <div>
                    <label class="mb-1 block text-xs text-textSecondary">Time range</label>
                    <select name="range" class="pl-select bg-background">
                        <option value="24h" @selected(($filters['range'] ?? '24h') === '24h')>Last 24 hours</option>
                        <option value="7d" @selected(($filters['range'] ?? '24h') === '7d')>Last 7 days</option>
                        <option value="30d" @selected(($filters['range'] ?? '24h') === '30d')>Last 30 days</option>
                        <option value="custom" @selected(($filters['range'] ?? '24h') === 'custom')>Custom</option>
                    </select>
                </div>
                <div>
                    <label class="mb-1 block text-xs text-textSecondary">From</label>
                    <input type="datetime-local" name="from" value="{{ old('from', $filters['from'] ?? '') }}" class="pl-input w-full">
                </div>
                <div>
                    <label class="mb-1 block text-xs text-textSecondary">To</label>
                    <input type="datetime-local" name="to" value="{{ old('to', $filters['to'] ?? '') }}" class="pl-input w-full">
                </div>
                <div>
                    <label class="mb-1 block text-xs text-textSecondary">Job class</label>
                    <input type="text" name="job_class" value="{{ old('job_class', $filters['job_class'] ?? '') }}" class="pl-input w-full" placeholder="App\\Jobs\\...">
                </div>
                <div>
                    <label class="mb-1 block text-xs text-textSecondary">Queue</label>
                    <input type="text" name="queue" list="queue-options" value="{{ old('queue', $filters['queue'] ?? '') }}" class="pl-input w-full" placeholder="default">
                </div>
                <div>
                    <label class="mb-1 block text-xs text-textSecondary">Org or Site</label>
                    <input type="text" name="org_site" value="{{ old('org_site', $filters['org_site'] ?? '') }}" class="pl-input w-full" placeholder="org id or site id">
                </div>
            </div>
            <div class="mt-3 flex flex-wrap gap-2">
                <button type="submit" class="rounded border border-border px-3 py-2 text-sm text-textPrimary hover:bg-surfaceSubtle">Apply filters</button>
                <a href="{{ route('admin.queues.index', ['focus_failed' => 1]) }}#failed-jobs" class="rounded border border-border px-3 py-2 text-sm text-textPrimary hover:bg-surfaceSubtle">Reset</a>
            </div>
        </form>

        @if ($failed_jobs_count === null)
            <p class="text-sm text-textSecondary">`failed_jobs` table not available in this environment.</p>
        @elseif ($failed_jobs->isEmpty())
            <p class="text-sm text-textSecondary">No failed jobs found for the selected filters.</p>
        @else
            @if (($failed_jobs_total_count ?? null) !== null && $failed_jobs_count !== $failed_jobs_total_count)
                <p class="mb-3 text-sm text-textSecondary">Filtered {{ $failed_jobs_count }} of {{ $failed_jobs_total_count }} total failed jobs</p>
            @endif

            <form id="failed-bulk-delete-form" method="POST" action="{{ route('admin.queues.destroy-bulk', request()->query()) }}">
                @csrf
            </form>

            <x-data-table label="Failed queue jobs" description="Failed database queue records with bulk deletion, retry actions, queue context, attempts, and error summaries." density="compact">
                <x-slot:bulkActions>
                    <x-data-table.bulk-actions>
                        <div class="text-sm font-medium">Failed job bulk actions</div>
                        <div class="flex flex-wrap gap-2">
                            <button form="failed-bulk-delete-form" type="submit" class="rounded border border-white/30 px-3 py-2 text-sm text-textInverse hover:bg-white/10" onclick="return confirm('Delete the selected failed job records?');">Delete selected</button>
                            <form method="POST" action="{{ route('admin.queues.retry-all', request()->query()) }}" onsubmit="return confirm('Retry all failed jobs in the current filter set?');">
                                @csrf
                                <button type="submit" class="rounded border border-white/30 px-3 py-2 text-sm text-textInverse hover:bg-white/10">Retry all failed jobs</button>
                            </form>
                        </div>
                    </x-data-table.bulk-actions>
                </x-slot:bulkActions>

                    <x-data-table.header>
                    <x-data-table.row>
                        <x-data-table.cell heading>Select</x-data-table.cell>
                        <x-data-table.cell heading>Failed At</x-data-table.cell>
                        <x-data-table.cell heading>Job</x-data-table.cell>
                        <x-data-table.cell heading>Queue</x-data-table.cell>
                        <x-data-table.cell heading>Org/Site</x-data-table.cell>
                        <x-data-table.cell heading>Error Summary</x-data-table.cell>
                        <x-data-table.cell heading>Attempts</x-data-table.cell>
                        <x-data-table.cell heading>Actions</x-data-table.cell>
                    </x-data-table.row>
                    </x-data-table.header>
                    <tbody>
                    @foreach ($failed_jobs as $job)
                        <x-data-table.row>
                            <x-data-table.cell label="Select">
                                <input form="failed-bulk-delete-form" type="checkbox" name="job_ids[]" value="{{ $job['id'] }}" class="h-4 w-4 rounded border-border text-primary">
                            </x-data-table.cell>
                            <x-data-table.cell label="Failed At" class="text-textSecondary">{{ $job['failed_at'] }}</x-data-table.cell>
                            <x-data-table.cell label="Job" class="text-textPrimary">
                                <a href="{{ route('admin.queues.show', $job['id']) }}" class="hover:text-link">
                                    {{ $job['job_name'] }}
                                </a>
                            </x-data-table.cell>
                            <x-data-table.cell label="Queue" class="text-textSecondary">{{ $job['queue'] }}</x-data-table.cell>
                            <x-data-table.cell label="Org/Site" class="text-textSecondary">{{ $job['org_site'] }}</x-data-table.cell>
                            <x-data-table.cell label="Error Summary" class="text-xs text-textSecondary">{{ $job['error_summary'] }}</x-data-table.cell>
                            <x-data-table.cell label="Attempts" class="text-textSecondary">{{ $job['attempts'] }}</x-data-table.cell>
                            <x-data-table.cell label="Actions">
                                <x-data-table.actions align="start">
                                    <a href="{{ route('admin.queues.show', $job['id']) }}" class="rounded border border-border px-2 py-1 text-xs">Details</a>
                                    <form method="POST" action="{{ route('admin.queues.retry', [$job['id']] + request()->query()) }}">
                                        @csrf
                                        <button type="submit" class="rounded border border-border px-2 py-1 text-xs">Retry</button>
                                    </form>
                                    <form method="POST" action="{{ route('admin.queues.destroy', [$job['id']] + request()->query()) }}" onsubmit="return confirm('Delete this failed job record?');">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="rounded border border-danger/30 px-2 py-1 text-xs text-danger">Delete</button>
                                    </form>
                                </x-data-table.actions>
                            </x-data-table.cell>
                        </x-data-table.row>
                    @endforeach
                    </tbody>
                <x-slot:pagination>{{ $failed_jobs->onEachSide(1)->links() }}</x-slot:pagination>
            </x-data-table>
        @endif
    </div>
@endsection
