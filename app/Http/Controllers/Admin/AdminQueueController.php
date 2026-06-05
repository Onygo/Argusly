<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ContentTranslation;
use App\Services\Admin\QueueAdminService;
use App\Services\Admin\TranslationAdminService;
use App\Support\AdminFailedJobsQuery;
use App\Support\QueueWorkerHeartbeat;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class AdminQueueController extends Controller
{
    public function __construct(
        private readonly QueueAdminService $queues,
        private readonly TranslationAdminService $translations,
    ) {}

    public function index(Request $request): View
    {
        Gate::authorize('viewQueues');

        $queueConnection = (string) config('queue.default', 'sync');
        $queueConfigured = (bool) config('queue.connections.' . $queueConnection);
        $heartbeatTimestamp = QueueWorkerHeartbeat::timestamp();
        $workerAlive = QueueWorkerHeartbeat::isAlive($heartbeatTimestamp, 120);

        $failedFilters = AdminFailedJobsQuery::resolveFilters($request);
        $failedData = $this->queues->getFailedJobs($request, $failedFilters);
        $stats = $this->queues->getQueueStats();

        $pendingFilters = $request->validate([
            'pending_queue' => ['nullable', 'string', 'max:255'],
            'pending_job_class' => ['nullable', 'string', 'max:255'],
            'pending_age_range' => ['nullable', 'in:,10m,1h,24h,7d'],
            'pending_org_site' => ['nullable', 'string', 'max:255'],
            'pending_search' => ['nullable', 'string', 'max:255'],
        ]);

        $pendingJobs = $this->queues->getPendingJobs([
            'queue' => trim((string) ($pendingFilters['pending_queue'] ?? '')),
            'job_class' => trim((string) ($pendingFilters['pending_job_class'] ?? '')),
            'age_range' => trim((string) ($pendingFilters['pending_age_range'] ?? '')),
            'org_site' => trim((string) ($pendingFilters['pending_org_site'] ?? '')),
            'search' => trim((string) ($pendingFilters['pending_search'] ?? '')),
        ]);

        $translationFilters = $request->validate([
            'translation_organization' => ['nullable', 'string', 'max:255'],
            'translation_site' => ['nullable', 'string', 'max:255'],
            'translation_content_id' => ['nullable', 'string', 'max:255'],
            'translation_locale' => ['nullable', 'string', 'max:16'],
            'translation_status' => ['nullable', 'string', 'in:,queued,processing,completed,failed,stale'],
            'translation_stale_only' => ['nullable', 'boolean'],
            'translation_failed_only' => ['nullable', 'boolean'],
        ]);

        $translationRows = $this->translations->getTranslations([
            'organization' => trim((string) ($translationFilters['translation_organization'] ?? '')),
            'site' => trim((string) ($translationFilters['translation_site'] ?? '')),
            'content_id' => trim((string) ($translationFilters['translation_content_id'] ?? '')),
            'locale' => trim((string) ($translationFilters['translation_locale'] ?? '')),
            'status' => trim((string) ($translationFilters['translation_status'] ?? '')),
            'stale_only' => $request->boolean('translation_stale_only'),
            'failed_only' => $request->boolean('translation_failed_only'),
        ]);

        return view('admin.queues.index', [
            'queue_connection' => $queueConnection,
            'queue_configured' => $queueConfigured,
            'worker_alive' => $workerAlive,
            'worker_heartbeat_cache_store' => QueueWorkerHeartbeat::storeName(),
            'worker_heartbeat_cache_key' => QueueWorkerHeartbeat::key(),
            'worker_last_heartbeat_at' => QueueWorkerHeartbeat::lastHeartbeatAt($heartbeatTimestamp),
            'queue_stats' => $stats,
            'pending_jobs' => $pendingJobs,
            'pending_filters' => [
                'queue' => trim((string) ($pendingFilters['pending_queue'] ?? '')),
                'job_class' => trim((string) ($pendingFilters['pending_job_class'] ?? '')),
                'age_range' => trim((string) ($pendingFilters['pending_age_range'] ?? '')),
                'org_site' => trim((string) ($pendingFilters['pending_org_site'] ?? '')),
                'search' => trim((string) ($pendingFilters['pending_search'] ?? '')),
            ],
            'failed_jobs' => $failedData['paginator'],
            'failed_jobs_count' => $failedData['filtered_count'],
            'failed_jobs_filtered_count' => $failedData['filtered_count'],
            'failed_jobs_total_count' => $failedData['total_count'],
            'filters' => $failedFilters,
            'translation_rows' => $translationRows,
            'translation_filters' => [
                'organization' => trim((string) ($translationFilters['translation_organization'] ?? '')),
                'site' => trim((string) ($translationFilters['translation_site'] ?? '')),
                'content_id' => trim((string) ($translationFilters['translation_content_id'] ?? '')),
                'locale' => trim((string) ($translationFilters['translation_locale'] ?? '')),
                'status' => trim((string) ($translationFilters['translation_status'] ?? '')),
                'stale_only' => $request->boolean('translation_stale_only'),
                'failed_only' => $request->boolean('translation_failed_only'),
            ],
            'queue_options' => collect($stats['queue_names'] ?? [])
                ->merge($failedData['queue_options'] ?? [])
                ->filter(fn (mixed $queue): bool => trim((string) $queue) !== '')
                ->unique()
                ->sort()
                ->values()
                ->all(),
        ]);
    }

    public function showPending(int $job): View
    {
        Gate::authorize('viewQueues');

        $pendingJob = $this->queues->getPendingJobDetail($job);
        if (! $pendingJob) {
            return view('admin.queues.pending-missing', [
                'jobId' => $job,
                'nearbyPendingJobs' => $this->queues->getNearbyPendingJobs($job),
                'recentFailedJobs' => $this->queues->getRecentFailedJobs(5),
            ]);
        }

        return view('admin.queues.pending-show', ['job' => $pendingJob]);
    }

    public function failed(Request $request): RedirectResponse
    {
        Gate::authorize('viewQueues');

        $query = $request->query();
        $query['focus_failed'] = 1;

        return redirect()->route('admin.queues.index', $query);
    }

    public function show(string $failedJob): View
    {
        Gate::authorize('viewQueues');

        $job = $this->queues->getFailedJobDetail($failedJob);
        if (! $job) {
            abort(404);
        }

        return view('admin.queues.show', ['job' => $job]);
    }

    public function destroyPending(int $job, Request $request): RedirectResponse
    {
        Gate::authorize('manageQueues');

        try {
            $result = $this->queues->deleteJob($job, $request->user(), $request);
            if (! $result['deleted']) {
                return back()->withErrors(['queues' => $result['message']]);
            }

            return redirect()
                ->route('admin.queues.index', $request->query())
                ->with('status', $result['message']);
        } catch (\Throwable $exception) {
            return back()->withErrors(['queues' => 'Delete failed: ' . $exception->getMessage()]);
        }
    }

    public function requeuePending(int $job, Request $request): RedirectResponse
    {
        Gate::authorize('manageQueues');

        $validated = $request->validate([
            'queue' => ['nullable', 'string', 'max:255'],
        ]);

        if (! $this->queues->requeuePendingJob($job, $validated['queue'] ?? null, $request->user(), $request)) {
            return back()->withErrors(['queues' => 'Pending job not found.']);
        }

        return redirect()
            ->route('admin.queues.index', $request->query())
            ->with('status', 'Pending job requeued.');
    }

    public function retry(string $failedJob, Request $request): RedirectResponse
    {
        Gate::authorize('manageQueues');

        $validated = $request->validate([
            'queue' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $result = $this->queues->retryFailedJob($failedJob, $validated['queue'] ?? null, $request->user(), $request);
            if (! $result['retried']) {
                return back()->withErrors(['queues' => $result['message']]);
            }

            return redirect()
                ->route('admin.queues.index', $request->query())
                ->with('status', $result['message']);
        } catch (\Throwable $exception) {
            return back()->withErrors(['queues' => 'Retry failed: ' . $exception->getMessage()]);
        }
    }

    public function retryAll(Request $request): RedirectResponse
    {
        Gate::authorize('manageQueues');

        $validated = $request->validate([
            'queue' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $count = $this->queues->retryFailedJobs($validated['queue'] ?? null, $request->user(), $request);

            return redirect()
                ->route('admin.queues.index', $request->query())
                ->with('status', $count > 0 ? "Retried {$count} failed job(s)." : 'No failed jobs matched the retry request.');
        } catch (\Throwable $exception) {
            return back()->withErrors(['queues' => 'Bulk retry failed: ' . $exception->getMessage()]);
        }
    }

    public function destroy(string $failedJob, Request $request): RedirectResponse
    {
        Gate::authorize('manageQueues');

        try {
            $result = $this->queues->deleteFailedJob($failedJob, $request->user(), $request);

            if (! $result['deleted']) {
                return back()->withErrors(['queues' => $result['message']]);
            }

            return redirect()
                ->route('admin.queues.index', $request->query())
                ->with('status', $result['message']);
        } catch (\Throwable $exception) {
            return back()->withErrors(['queues' => 'Delete failed: ' . $exception->getMessage()]);
        }
    }

    public function destroyBulk(Request $request): RedirectResponse
    {
        Gate::authorize('manageQueues');

        $validated = $request->validate([
            'job_ids' => ['nullable', 'array'],
            'job_ids.*' => ['string'],
            'queue' => ['nullable', 'string', 'max:255'],
        ]);

        $count = $this->queues->deleteFailedJobs(
            $validated['job_ids'] ?? [],
            $validated['queue'] ?? null,
            $request->user(),
            $request
        );

        return redirect()
            ->route('admin.queues.index', $request->query())
            ->with('status', $count > 0 ? "Deleted {$count} failed job record(s)." : 'No failed jobs matched the delete request.');
    }

    public function flush(Request $request): RedirectResponse
    {
        Gate::authorize('manageQueues');

        $validated = $request->validate([
            'queue' => ['required', 'string', 'max:255'],
        ]);

        $count = $this->queues->flushQueue($validated['queue'], $request->user(), $request);

        return redirect()
            ->route('admin.queues.index', $request->query())
            ->with('status', $count > 0 ? "Flushed {$count} pending job(s) from {$validated['queue']}." : 'No pending jobs matched the flush request.');
    }

    public function deleteOlder(Request $request): RedirectResponse
    {
        Gate::authorize('manageQueues');

        $validated = $request->validate([
            'hours' => ['required', 'integer', 'min:1', 'max:720'],
            'scope' => ['required', 'in:pending,failed,all'],
        ]);

        $count = $this->queues->deleteJobsOlderThanHours(
            (int) $validated['hours'],
            (string) $validated['scope'],
            $request->user(),
            $request
        );

        return redirect()
            ->route('admin.queues.index', $request->query())
            ->with('status', $count > 0 ? "Deleted {$count} {$validated['scope']} job(s) older than {$validated['hours']} hour(s)." : 'No jobs matched the cleanup request.');
    }

    public function releaseTranslationLock(ContentTranslation $translation, Request $request): RedirectResponse
    {
        Gate::authorize('admin-area-superadmin');

        $result = $this->translations->releaseLock($translation, $request->user(), $request);

        return $result['ok']
            ? redirect()->route('admin.queues.index', $request->query() + ['focus_translations' => 1])->with('status', $result['message'])
            : back()->withErrors(['queues' => $result['message']]);
    }

    public function markTranslationFailed(ContentTranslation $translation, Request $request): RedirectResponse
    {
        Gate::authorize('admin-area-superadmin');

        $result = $this->translations->markAsFailed($translation, $request->user(), $request);

        return $result['ok']
            ? redirect()->route('admin.queues.index', $request->query() + ['focus_translations' => 1])->with('status', $result['message'])
            : back()->withErrors(['queues' => $result['message']]);
    }

    public function retryTranslation(ContentTranslation $translation, Request $request): RedirectResponse
    {
        Gate::authorize('admin-area-superadmin');

        try {
            $result = $this->translations->retryTranslation($translation, $request->user(), $request);
        } catch (\Throwable $exception) {
            return back()->withErrors(['queues' => 'Translation retry failed: ' . $exception->getMessage()]);
        }

        return ($result['ok'] ?? false)
            ? redirect()->route('admin.queues.index', $request->query() + ['focus_translations' => 1])->with('status', $result['message'])
            : redirect()->route('admin.queues.index', $request->query() + ['focus_translations' => 1])->with('status', $result['message']);
    }

    public function retryTranslationFailedJob(ContentTranslation $translation, Request $request): RedirectResponse
    {
        Gate::authorize('admin-area-superadmin');

        $validated = $request->validate([
            'failed_job_id' => ['required', 'string'],
        ]);

        try {
            $result = $this->queues->retryFailedJob($validated['failed_job_id'], null, $request->user(), $request);
        } catch (\Throwable $exception) {
            return back()->withErrors(['queues' => 'Failed queue job retry failed: ' . $exception->getMessage()]);
        }

        return $result['retried']
            ? redirect()->route('admin.queues.index', $request->query() + ['focus_translations' => 1])->with('status', 'Linked failed queue job retried.')
            : back()->withErrors(['queues' => $result['message']]);
    }

    public function deleteTranslationFailedJob(ContentTranslation $translation, Request $request): RedirectResponse
    {
        Gate::authorize('admin-area-superadmin');

        $validated = $request->validate([
            'failed_job_id' => ['required', 'string'],
        ]);

        try {
            $result = $this->queues->deleteFailedJob($validated['failed_job_id'], $request->user(), $request);
        } catch (\Throwable $exception) {
            return back()->withErrors(['queues' => 'Failed queue job delete failed: ' . $exception->getMessage()]);
        }

        return $result['deleted']
            ? redirect()->route('admin.queues.index', $request->query() + ['focus_translations' => 1])->with('status', 'Linked failed queue job deleted.')
            : back()->withErrors(['queues' => $result['message']]);
    }

    public function forceResetAndRetryTranslation(ContentTranslation $translation, Request $request): RedirectResponse
    {
        Gate::authorize('admin-area-superadmin');

        try {
            $result = $this->translations->forceResetAndRetry($translation, $request->user(), $request);
        } catch (\Throwable $exception) {
            return back()->withErrors(['queues' => 'Force reset + retry failed: ' . $exception->getMessage()]);
        }

        return ($result['ok'] ?? false)
            ? redirect()->route('admin.queues.index', $request->query() + ['focus_translations' => 1])->with('status', $result['message'])
            : redirect()->route('admin.queues.index', $request->query() + ['focus_translations' => 1])->with('status', $result['message']);
    }

    public function repairStaleTranslationLocks(Request $request): RedirectResponse
    {
        Gate::authorize('admin-area-superadmin');

        $validated = $request->validate([
            'apply' => ['nullable', 'boolean'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:1000'],
        ]);

        $result = $this->translations->repairStaleLocks(
            apply: (bool) ($validated['apply'] ?? false),
            limit: (int) ($validated['limit'] ?? 250),
            actor: $request->user(),
            request: $request,
        );

        return redirect()
            ->route('admin.queues.index', $request->query() + ['focus_translations' => 1])
            ->with('status', $result['message']);
    }
}
