<?php

namespace App\Services\Admin;

use App\Models\AuditLog;
use App\Models\User;
use App\Support\AdminFailedJobsQuery;
use App\Support\FailedJobPayloadRedactor;
use App\Support\QueueNames;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class QueueAdminService
{
    private const SNAPSHOT_CACHE_KEY = 'admin.queue.snapshot.v1';

    /**
     * @return array{
     *     queue_names: array<int,string>,
     *     total_pending_jobs: int|null,
     *     total_failed_jobs: int|null,
     *     processing_rate_per_minute: float|null,
     *     queues: array<int,array{
     *         name:string,
     *         pending_count:int,
     *         failed_count:int,
     *         oldest_job_at:?Carbon,
     *         oldest_unreserved_job_at:?Carbon,
     *         processing_rate_per_minute:?float,
     *         is_stuck:bool
     *     }>
     * }
     */
    public function getQueueStats(): array
    {
        $queueNames = $this->knownQueueNames();

        if (! Schema::hasTable('jobs')) {
            return [
                'queue_names' => $queueNames,
                'total_pending_jobs' => null,
                'total_failed_jobs' => Schema::hasTable('failed_jobs') ? (int) DB::table('failed_jobs')->count() : null,
                'processing_rate_per_minute' => null,
                'queues' => collect($queueNames)->map(fn (string $queue): array => [
                    'name' => $queue,
                    'pending_count' => 0,
                    'failed_count' => 0,
                    'oldest_job_at' => null,
                    'oldest_unreserved_job_at' => null,
                    'processing_rate_per_minute' => null,
                    'is_stuck' => false,
                ])->all(),
            ];
        }

        $pendingRows = DB::table('jobs')
            ->selectRaw('queue, COUNT(*) as pending_count, MIN(created_at) as oldest_created_at, MIN(CASE WHEN reserved_at IS NULL THEN created_at END) as oldest_unreserved_created_at')
            ->groupBy('queue')
            ->get()
            ->keyBy(fn (object $row): string => (string) $row->queue);

        $failedCounts = Schema::hasTable('failed_jobs')
            ? DB::table('failed_jobs')
                ->selectRaw('queue, COUNT(*) as failed_count')
                ->groupBy('queue')
                ->pluck('failed_count', 'queue')
            : collect();

        $queueNames = collect($queueNames)
            ->merge($pendingRows->keys())
            ->merge($failedCounts->keys())
            ->filter(fn (mixed $queue): bool => trim((string) $queue) !== '')
            ->unique()
            ->sort()
            ->values()
            ->all();

        $currentSnapshot = [
            'captured_at' => now()->toIso8601String(),
            'counts' => collect($queueNames)->mapWithKeys(function (string $queue) use ($pendingRows): array {
                $row = $pendingRows->get($queue);

                return [$queue => (int) ($row->pending_count ?? 0)];
            })->all(),
        ];

        $previousSnapshot = Cache::get(self::SNAPSHOT_CACHE_KEY);
        Cache::put(self::SNAPSHOT_CACHE_KEY, $currentSnapshot, now()->addHours(6));

        $elapsedMinutes = $this->snapshotElapsedMinutes($previousSnapshot);

        $queues = collect($queueNames)
            ->map(function (string $queue) use ($pendingRows, $failedCounts, $previousSnapshot, $elapsedMinutes): array {
                $row = $pendingRows->get($queue);
                $oldestJobAt = $this->timestampToCarbon($row->oldest_created_at ?? null);
                $oldestUnreservedJobAt = $this->timestampToCarbon($row->oldest_unreserved_created_at ?? null);

                return [
                    'name' => $queue,
                    'pending_count' => (int) ($row->pending_count ?? 0),
                    'failed_count' => (int) ($failedCounts[$queue] ?? 0),
                    'oldest_job_at' => $oldestJobAt,
                    'oldest_unreserved_job_at' => $oldestUnreservedJobAt,
                    'processing_rate_per_minute' => $this->processingRateForQueue($queue, (int) ($row->pending_count ?? 0), $previousSnapshot, $elapsedMinutes),
                    'is_stuck' => $oldestUnreservedJobAt !== null && $oldestUnreservedJobAt->lte(now()->subMinutes(10)),
                ];
            })
            ->values();

        return [
            'queue_names' => $queueNames,
            'total_pending_jobs' => (int) $queues->sum('pending_count'),
            'total_failed_jobs' => Schema::hasTable('failed_jobs') ? (int) DB::table('failed_jobs')->count() : null,
            'processing_rate_per_minute' => $this->aggregateProcessingRate($queues),
            'queues' => $queues->all(),
        ];
    }

    /**
     * @param array{queue?:string,job_class?:string,age_range?:string,org_site?:string,search?:string} $filters
     */
    public function getPendingJobs(array $filters = [], string $pageName = 'pending_page', int $perPage = 20): LengthAwarePaginator
    {
        if (! Schema::hasTable('jobs')) {
            return new \Illuminate\Pagination\LengthAwarePaginator([], 0, $perPage, 1, ['pageName' => $pageName]);
        }

        $query = DB::table('jobs')
            ->select(['id', 'queue', 'payload', 'attempts', 'reserved_at', 'available_at', 'created_at']);

        $queue = trim((string) ($filters['queue'] ?? ''));
        if ($queue !== '') {
            $query->where('queue', $queue);
        }

        $jobClass = trim((string) ($filters['job_class'] ?? ''));
        if ($jobClass !== '') {
            $query->where('payload', 'like', '%' . $jobClass . '%');
        }

        $orgSite = trim((string) ($filters['org_site'] ?? ''));
        if ($orgSite !== '') {
            $query->where('payload', 'like', '%' . $orgSite . '%');
        }

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $query->where(function ($builder) use ($search): void {
                $builder->where('payload', 'like', '%' . $search . '%')
                    ->orWhere('queue', 'like', '%' . $search . '%');

                if (ctype_digit($search)) {
                    $builder->orWhere('id', (int) $search);
                }
            });
        }

        $createdAfter = $this->pendingAgeThreshold(trim((string) ($filters['age_range'] ?? '')));
        if ($createdAfter !== null) {
            $query->where('created_at', '>=', $createdAfter->timestamp);
        }

        return $query
            ->orderBy('created_at')
            ->orderBy('id')
            ->paginate($perPage, ['*'], $pageName)
            ->withQueryString()
            ->through(function (object $row): array {
                $payload = $this->decodePayload($row->payload);
                $context = $this->extractContext($payload);
                $createdAt = $this->timestampToCarbon($row->created_at);

                return [
                    'id' => (int) $row->id,
                    'queue' => (string) $row->queue,
                    'job_class' => $this->resolveJobName($payload, '', (string) $row->id),
                    'attempts' => (int) $row->attempts,
                    'created_at' => $createdAt,
                    'age_human' => $createdAt?->diffForHumans(),
                    'available_at' => $this->timestampToCarbon($row->available_at),
                    'reserved_at' => $this->timestampToCarbon($row->reserved_at),
                    'org_site' => $this->contextDisplay($context),
                    'context' => $context,
                ];
            });
    }

    /**
     * @return array<string,mixed>|null
     */
    public function getPendingJobDetail(int $id): ?array
    {
        if (! Schema::hasTable('jobs')) {
            return null;
        }

        $row = DB::table('jobs')
            ->select(['id', 'queue', 'payload', 'attempts', 'reserved_at', 'available_at', 'created_at'])
            ->where('id', $id)
            ->first();

        if (! $row) {
            return null;
        }

        $payload = $this->decodePayload($row->payload);
        $context = $this->extractContext($payload);
        $redactedPayload = FailedJobPayloadRedactor::redact($payload);

        return [
            'id' => (int) $row->id,
            'queue' => (string) $row->queue,
            'job_name' => $this->resolveJobName($payload, '', (string) $row->id),
            'attempts' => (int) $row->attempts,
            'created_at' => $this->timestampToCarbon($row->created_at),
            'available_at' => $this->timestampToCarbon($row->available_at),
            'reserved_at' => $this->timestampToCarbon($row->reserved_at),
            'context' => $context,
            'org_site' => $this->contextDisplay($context),
            'payload_summary' => $this->payloadSummary($payload),
            'payload_json' => json_encode($redactedPayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}',
        ];
    }

    /**
     * @return array<int,array{id:int,queue:string,job_name:string,created_at:?Carbon,reserved_at:?Carbon}>
     */
    public function getNearbyPendingJobs(int $id, int $limit = 10): array
    {
        if (! Schema::hasTable('jobs')) {
            return [];
        }

        return DB::table('jobs')
            ->select(['id', 'queue', 'payload', 'created_at', 'reserved_at'])
            ->whereBetween('id', [max(1, $id - 25), $id + 25])
            ->orderBy('id')
            ->limit(max(1, $limit))
            ->get()
            ->map(function (object $row): array {
                $payload = $this->decodePayload($row->payload);

                return [
                    'id' => (int) $row->id,
                    'queue' => (string) $row->queue,
                    'job_name' => $this->resolveJobName($payload, '', (string) $row->id),
                    'created_at' => $this->timestampToCarbon($row->created_at),
                    'reserved_at' => $this->timestampToCarbon($row->reserved_at),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return array<int,array{id:string,uuid:string,queue:string,job_name:string,failed_at:string,error_summary:string}>
     */
    public function getRecentFailedJobs(int $limit = 5): array
    {
        if (! Schema::hasTable('failed_jobs')) {
            return [];
        }

        return DB::table('failed_jobs')
            ->select(['id', 'uuid', 'queue', 'payload', 'exception', 'failed_at'])
            ->orderByDesc('failed_at')
            ->orderByDesc('id')
            ->limit(max(1, $limit))
            ->get()
            ->map(function (object $row): array {
                $payload = $this->decodePayload($row->payload);

                return [
                    'id' => (string) $row->id,
                    'uuid' => (string) ($row->uuid ?? ''),
                    'queue' => (string) ($row->queue ?? ''),
                    'job_name' => $this->resolveJobName($payload, (string) ($row->uuid ?? ''), (string) $row->id),
                    'failed_at' => (string) ($row->failed_at ?? ''),
                    'error_summary' => $this->exceptionSummary((string) ($row->exception ?? '')),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param array{range:string,from:?string,to:?string,job_class:string,queue:string,org_site:string} $filters
     * @return array{
     *     paginator: LengthAwarePaginator,
     *     filtered_count: int|null,
     *     total_count: int|null,
     *     queue_options: array<int,string>
     * }
     */
    public function getFailedJobs(Request $request, array $filters, string $pageName = 'failed_page', int $perPage = 20): array
    {
        if (! Schema::hasTable('failed_jobs')) {
            return [
                'paginator' => new \Illuminate\Pagination\LengthAwarePaginator([], 0, $perPage, 1, ['pageName' => $pageName]),
                'filtered_count' => null,
                'total_count' => null,
                'queue_options' => [],
            ];
        }

        [$from, $to] = AdminFailedJobsQuery::resolveDateRange($request, $filters);

        $baseQuery = DB::table('failed_jobs')
            ->select(['id', 'uuid', 'connection', 'queue', 'payload', 'exception', 'failed_at']);

        AdminFailedJobsQuery::applyFilters($baseQuery, $request, $filters, $from, $to);

        $filteredCount = (int) (clone $baseQuery)->count();
        $paginator = (clone $baseQuery)
            ->orderByDesc('failed_at')
            ->orderByDesc('id')
            ->paginate($perPage, ['*'], $pageName)
            ->withQueryString()
            ->through(function (object $row): array {
                return $this->mapFailedJobRow($row);
            });

        $queueOptions = DB::table('failed_jobs')
            ->whereNotNull('queue')
            ->where('queue', '!=', '')
            ->distinct()
            ->orderBy('queue')
            ->pluck('queue')
            ->map(fn ($value): string => (string) $value)
            ->values()
            ->all();

        return [
            'paginator' => $paginator,
            'filtered_count' => $filteredCount,
            'total_count' => (int) DB::table('failed_jobs')->count(),
            'queue_options' => $queueOptions,
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    public function getFailedJobDetail(string $identifier): ?array
    {
        $row = $this->findFailedJob($identifier);
        if (! $row) {
            return null;
        }

        $payload = $this->decodePayload($row->payload);
        $context = $this->extractContext($payload);
        $redactedPayload = FailedJobPayloadRedactor::redact($payload);
        $exception = (string) ($row->exception ?? '');

        return [
            'id' => (string) $row->id,
            'uuid' => (string) ($row->uuid ?? ''),
            'failed_at' => (string) ($row->failed_at ?? ''),
            'connection' => (string) ($row->connection ?? ''),
            'queue' => (string) ($row->queue ?? ''),
            'job_name' => $this->resolveJobName($payload, (string) ($row->uuid ?? ''), (string) ($row->id ?? '')),
            'attempts' => $this->resolveAttempts($payload),
            'context' => $context,
            'error_summary' => $this->exceptionSummary($exception),
            'exception' => $this->redactText($exception),
            'payload_json' => json_encode($redactedPayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}',
        ];
    }

    /**
     * @return array{deleted:bool,not_found:bool,message:string}
     */
    public function deleteJob(int $id, ?User $actor = null, ?Request $request = null): array
    {
        if (! Schema::hasTable('jobs')) {
            return [
                'deleted' => false,
                'not_found' => true,
                'message' => 'Pending jobs table is not available.',
            ];
        }

        $job = DB::table('jobs')->where('id', $id)->first();
        if (! $job) {
            return [
                'deleted' => false,
                'not_found' => true,
                'message' => 'Pending job not found.',
            ];
        }

        try {
            $deleted = DB::table('jobs')->where('id', $id)->delete() > 0;
            $stillExists = DB::table('jobs')->where('id', $id)->exists();

            if (! $deleted || $stillExists) {
                Log::warning('Admin pending job delete did not remove row.', [
                    'job_id' => $id,
                    'admin_user_id' => $actor?->getKey(),
                ]);

                return [
                    'deleted' => false,
                    'not_found' => false,
                    'message' => 'Pending job could not be deleted.',
                ];
            }

            $this->recordAdminAction(
                actor: $actor,
                action: 'queue.pending.deleted',
                subjectId: (string) $id,
                before: $this->pendingJobAuditPayload($job),
                after: null,
                request: $request
            );

            return [
                'deleted' => true,
                'not_found' => false,
                'message' => 'Pending job deleted.',
            ];
        } catch (\Throwable $exception) {
            Log::error('Admin pending job delete failed.', [
                'job_id' => $id,
                'admin_user_id' => $actor?->getKey(),
                'exception' => $exception,
            ]);

            throw $exception;
        }
    }

    public function requeuePendingJob(int $id, ?string $targetQueue = null, ?User $actor = null, ?Request $request = null): bool
    {
        if (! Schema::hasTable('jobs')) {
            return false;
        }

        $job = DB::table('jobs')->where('id', $id)->first();
        if (! $job) {
            return false;
        }

        $queue = trim((string) ($targetQueue ?: $job->queue));
        if ($queue === '') {
            $queue = 'default';
        }

        $before = $this->pendingJobAuditPayload($job);
        $updated = DB::table('jobs')
            ->where('id', $id)
            ->update([
                'queue' => $queue,
                'attempts' => 0,
                'reserved_at' => null,
                'available_at' => now()->timestamp,
                'created_at' => now()->timestamp,
            ]) > 0;

        if ($updated) {
            $after = $before;
            $after['queue'] = $queue;
            $after['attempts'] = 0;
            $after['reserved_at'] = null;
            $after['available_at'] = now()->toIso8601String();
            $after['created_at'] = now()->toIso8601String();

            $this->recordAdminAction(
                actor: $actor,
                action: 'queue.pending.requeued',
                subjectId: (string) $id,
                before: $before,
                after: $after,
                request: $request
            );
        }

        return $updated;
    }

    /**
     * @return array{retried:bool,not_found:bool,message:string}
     */
    public function retryFailedJob(string $id, ?string $targetQueue = null, ?User $actor = null, ?Request $request = null): array
    {
        $job = $this->findFailedJob($id);
        if (! $job || ! Schema::hasTable('jobs')) {
            return [
                'retried' => false,
                'not_found' => true,
                'message' => 'Failed job not found.',
            ];
        }

        $queue = trim((string) ($targetQueue ?: $job->queue));
        if ($queue === '') {
            $queue = 'default';
        }

        try {
            $insertedJobId = null;

            DB::transaction(function () use ($job, $queue, &$insertedJobId): void {
                $insertedJobId = DB::table('jobs')->insertGetId([
                    'queue' => $queue,
                    'payload' => (string) $job->payload,
                    'attempts' => 0,
                    'reserved_at' => null,
                    'available_at' => now()->timestamp,
                    'created_at' => now()->timestamp,
                ]);

                $this->forgetFailedJobRecord((string) $job->id);
            });

            $failedJobStillExists = $this->findFailedJob((string) $job->id) !== null;
            $pendingJobExists = $insertedJobId !== null && DB::table('jobs')->where('id', $insertedJobId)->exists();

            if ($failedJobStillExists || ! $pendingJobExists) {
                Log::warning('Admin failed job retry did not complete state transition.', [
                    'failed_job_id' => (string) $job->id,
                    'admin_user_id' => $actor?->getKey(),
                    'inserted_job_id' => $insertedJobId,
                ]);

                return [
                    'retried' => false,
                    'not_found' => false,
                    'message' => 'Failed job could not be retried.',
                ];
            }

            $this->recordAdminAction(
                actor: $actor,
                action: 'queue.failed.retried',
                subjectId: (string) $job->id,
                before: $this->failedJobAuditPayload($job),
                after: ['queue' => $queue, 'retried_at' => now()->toIso8601String(), 'pending_job_id' => $insertedJobId],
                request: $request
            );

            return [
                'retried' => true,
                'not_found' => false,
                'message' => 'Failed job retried successfully.',
            ];
        } catch (\Throwable $exception) {
            Log::error('Admin failed job retry failed.', [
                'failed_job_id' => (string) $job->id,
                'admin_user_id' => $actor?->getKey(),
                'exception' => $exception,
            ]);

            throw $exception;
        }
    }

    public function flushQueue(string $queue, ?User $actor = null, ?Request $request = null): int
    {
        if (! Schema::hasTable('jobs')) {
            return 0;
        }

        $queue = trim($queue);
        if ($queue === '') {
            return 0;
        }

        $jobs = DB::table('jobs')->where('queue', $queue)->get();
        $deletedCount = DB::table('jobs')->where('queue', $queue)->delete();

        if ($deletedCount > 0) {
            $this->recordAdminAction(
                actor: $actor,
                action: 'queue.pending.flushed',
                subjectId: $queue,
                before: ['queue' => $queue, 'deleted_ids' => $jobs->pluck('id')->map(fn ($id): int => (int) $id)->all()],
                after: ['deleted_count' => $deletedCount],
                request: $request
            );
        }

        return $deletedCount;
    }

    public function retryFailedJobs(?string $queue = null, ?User $actor = null, ?Request $request = null): int
    {
        if (! Schema::hasTable('failed_jobs') || ! Schema::hasTable('jobs')) {
            return 0;
        }

        $jobs = DB::table('failed_jobs')
            ->when(trim((string) $queue) !== '', fn ($query) => $query->where('queue', trim((string) $queue)))
            ->orderBy('id')
            ->get();

        if ($jobs->isEmpty()) {
            return 0;
        }

        $targetQueue = trim((string) $queue);
        $retriedIds = [];

        foreach ($jobs as $job) {
            $result = $this->retryFailedJob((string) $job->id, $targetQueue !== '' ? $targetQueue : null, $actor, $request);
            if ($result['retried']) {
                $retriedIds[] = (int) $job->id;
            }
        }

        if ($retriedIds === []) {
            return 0;
        }

        $this->recordAdminAction(
            actor: $actor,
            action: 'queue.failed.retried_bulk',
            subjectId: $targetQueue !== '' ? $targetQueue : 'all',
            before: [
                'queue' => $targetQueue !== '' ? $targetQueue : null,
                'failed_job_ids' => $retriedIds,
            ],
            after: ['retried_count' => count($retriedIds)],
            request: $request
        );

        return count($retriedIds);
    }

    /**
     * @param array<int,int|string> $ids
     */
    public function deleteFailedJobs(array $ids = [], ?string $queue = null, ?User $actor = null, ?Request $request = null): int
    {
        if (! Schema::hasTable('failed_jobs')) {
            return 0;
        }

        $normalizedIds = collect($ids)
            ->filter(fn (mixed $id): bool => ctype_digit((string) $id))
            ->map(fn (mixed $id): int => (int) $id)
            ->values()
            ->all();

        $query = DB::table('failed_jobs');

        if ($normalizedIds !== []) {
            $query->whereIn('id', $normalizedIds);
        } elseif (trim((string) $queue) !== '') {
            $query->where('queue', trim((string) $queue));
        } else {
            return 0;
        }

        $jobs = $query->get();
        if ($jobs->isEmpty()) {
            return 0;
        }

        $deletedIds = [];

        try {
            $failer = app('queue.failer');

            foreach ($jobs as $job) {
                $deleted = false;

                if (method_exists($failer, 'forget')) {
                    $deleted = (bool) $failer->forget((string) $job->id);
                }

                if (! $deleted) {
                    $deleted = DB::table('failed_jobs')->where('id', $job->id)->delete() > 0;
                }

                if (! $deleted || $this->findFailedJob((string) $job->id) !== null) {
                    Log::warning('Admin bulk failed job delete did not remove row.', [
                        'failed_job_id' => (string) $job->id,
                        'failed_job_uuid' => (string) ($job->uuid ?? ''),
                        'admin_user_id' => $actor?->getKey(),
                    ]);

                    continue;
                }

                $deletedIds[] = (int) $job->id;
            }
        } catch (\Throwable $exception) {
            Log::error('Admin bulk failed job delete failed.', [
                'failed_job_ids' => $jobs->pluck('id')->map(fn ($id): int => (int) $id)->all(),
                'queue' => trim((string) $queue) !== '' ? trim((string) $queue) : null,
                'admin_user_id' => $actor?->getKey(),
                'exception' => $exception,
            ]);

            throw $exception;
        }

        $deletedCount = count($deletedIds);

        if ($deletedCount > 0) {
            $this->recordAdminAction(
                actor: $actor,
                action: 'queue.failed.deleted_bulk',
                subjectId: trim((string) $queue) !== '' ? trim((string) $queue) : 'selected',
                before: [
                    'queue' => trim((string) $queue) !== '' ? trim((string) $queue) : null,
                    'failed_job_ids' => $deletedIds,
                ],
                after: ['deleted_count' => $deletedCount],
                request: $request
            );
        }

        return $deletedCount;
    }

    /**
     * @return array{deleted:bool,not_found:bool,message:string}
     */
    public function deleteFailedJob(string $id, ?User $actor = null, ?Request $request = null): array
    {
        $job = $this->findFailedJob($id);
        if (! $job) {
            return [
                'deleted' => false,
                'not_found' => true,
                'message' => 'Failed job not found.',
            ];
        }

        try {
            $failer = app('queue.failer');
            $deleted = false;

            if (method_exists($failer, 'forget')) {
                $deleted = (bool) $failer->forget((string) $job->id);
            }

            if (! $deleted) {
                $deleted = DB::table('failed_jobs')->where('id', $job->id)->delete() > 0;
            }

            $stillExists = $this->findFailedJob((string) $job->id) !== null;

            if (! $deleted || $stillExists) {
                Log::warning('Admin failed job delete did not remove row.', [
                    'failed_job_id' => (string) $job->id,
                    'failed_job_uuid' => (string) ($job->uuid ?? ''),
                    'admin_user_id' => $actor?->getKey(),
                ]);

                return [
                    'deleted' => false,
                    'not_found' => false,
                    'message' => 'Failed job could not be deleted.',
                ];
            }

            $this->recordAdminAction(
                actor: $actor,
                action: 'queue.failed.deleted',
                subjectId: (string) $job->id,
                before: $this->failedJobAuditPayload($job),
                after: null,
                request: $request
            );

            return [
                'deleted' => true,
                'not_found' => false,
                'message' => 'Failed job deleted.',
            ];
        } catch (\Throwable $exception) {
            Log::error('Admin failed job delete failed.', [
                'failed_job_id' => (string) $job->id,
                'failed_job_uuid' => (string) ($job->uuid ?? ''),
                'admin_user_id' => $actor?->getKey(),
                'exception' => $exception,
            ]);

            throw $exception;
        }
    }

    public function deleteJobsOlderThanHours(int $hours, string $scope = 'pending', ?User $actor = null, ?Request $request = null): int
    {
        $hours = max(1, $hours);
        $deletedCount = 0;
        $before = ['scope' => $scope, 'hours' => $hours];

        if (($scope === 'pending' || $scope === 'all') && Schema::hasTable('jobs')) {
            $threshold = now()->subHours($hours)->timestamp;
            $deletedCount += DB::table('jobs')->where('created_at', '<=', $threshold)->delete();
        }

        if (($scope === 'failed' || $scope === 'all') && Schema::hasTable('failed_jobs')) {
            $threshold = now()->subHours($hours);
            $deletedCount += DB::table('failed_jobs')->where('failed_at', '<=', $threshold)->delete();
        }

        if ($deletedCount > 0) {
            $this->recordAdminAction(
                actor: $actor,
                action: 'queue.jobs.deleted_older_than',
                subjectId: $scope,
                before: $before,
                after: ['deleted_count' => $deletedCount],
                request: $request
            );
        }

        return $deletedCount;
    }

    /**
     * @return array<string,mixed>|null
     */
    public function findFailedJob(string $identifier): ?object
    {
        if (! Schema::hasTable('failed_jobs')) {
            return null;
        }

        $query = DB::table('failed_jobs')
            ->select(['id', 'uuid', 'connection', 'queue', 'payload', 'exception', 'failed_at']);

        if (ctype_digit($identifier)) {
            $query->where('id', (int) $identifier);
        } else {
            $query->where('uuid', $identifier);
        }

        return $query->first();
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,string>
     */
    public function extractContext(array $payload): array
    {
        return array_filter([
            'organization_id' => $this->extractFirstByKeys($payload, ['organization_id', 'org_id', 'tenant_id']),
            'site_id' => $this->extractFirstByKeys($payload, ['site_id', 'client_site_id', 'wp_site_id']),
            'workspace_id' => $this->extractFirstByKeys($payload, ['workspace_id']),
            'brief_id' => $this->extractFirstByKeys($payload, ['brief_id']),
            'draft_id' => $this->extractFirstByKeys($payload, ['draft_id']),
        ], fn ($value): bool => trim((string) $value) !== '');
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public function decodePayload(mixed $payload): array
    {
        if (is_array($payload)) {
            return $payload;
        }

        $decoded = json_decode((string) $payload, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        return ['raw_payload' => (string) $payload];
    }

    public function contextDisplay(array $context): string
    {
        $parts = [];
        if (! empty($context['organization_id'])) {
            $parts[] = 'Org ' . $context['organization_id'];
        }
        if (! empty($context['site_id'])) {
            $parts[] = 'Site ' . $context['site_id'];
        }

        return $parts === [] ? 'Unknown' : implode(' · ', $parts);
    }

    /**
     * @param array<string,mixed> $payload
     */
    public function resolveJobName(array $payload, string $uuid = '', string $id = ''): string
    {
        $displayName = trim((string) data_get($payload, 'displayName', ''));
        if ($displayName !== '') {
            return $displayName;
        }

        $commandName = trim((string) data_get($payload, 'data.commandName', ''));
        if ($commandName !== '') {
            return $commandName;
        }

        $job = trim((string) data_get($payload, 'job', ''));
        if ($job !== '') {
            return $job;
        }

        if ($uuid !== '') {
            return $uuid;
        }

        return $id !== '' ? 'Job #' . $id : 'Unknown';
    }

    /**
     * @param array<string,mixed> $payload
     */
    public function resolveAttempts(array $payload): int
    {
        $attempts = data_get($payload, 'attempts', data_get($payload, 'data.attempts', 0));

        return max(0, (int) $attempts);
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,string>
     */
    public function payloadSummary(array $payload): array
    {
        $summary = [
            'display_name' => trim((string) data_get($payload, 'displayName', '')),
            'handler' => trim((string) data_get($payload, 'job', '')),
            'uuid' => trim((string) data_get($payload, 'uuid', '')),
            'command_name' => trim((string) data_get($payload, 'data.commandName', '')),
            'max_tries' => trim((string) data_get($payload, 'maxTries', data_get($payload, 'data.maxTries', ''))),
            'timeout' => trim((string) data_get($payload, 'timeout', data_get($payload, 'data.timeout', ''))),
            'backoff' => trim((string) data_get($payload, 'backoff', data_get($payload, 'data.backoff', ''))),
        ];

        return array_filter($summary, fn (string $value): bool => $value !== '');
    }

    public function exceptionSummary(string $exception): string
    {
        $firstLine = trim(Str::before($exception, "\n"));
        if ($firstLine === '') {
            $firstLine = 'Unknown error';
        }

        return Str::limit($this->redactText($firstLine), 220);
    }

    public function redactText(string $value): string
    {
        $patterns = [
            '/(api[_-]?key|token|secret|password|authorization|cookie|signature|webhook_secret)\s*[:=]\s*([^\s"\']+)/i',
            '/(Bearer\s+)[A-Za-z0-9\-\._~\+\/]+=*/i',
        ];

        foreach ($patterns as $pattern) {
            $value = preg_replace($pattern, '$1[REDACTED]', $value) ?? $value;
        }

        return $value;
    }

    private function mapFailedJobRow(object $row): array
    {
        $payload = $this->decodePayload($row->payload);
        $context = $this->extractContext($payload);
        $jobName = $this->resolveJobName($payload, (string) ($row->uuid ?? ''), (string) ($row->id ?? ''));

        return [
            'id' => (string) $row->id,
            'uuid' => (string) ($row->uuid ?? ''),
            'failed_at' => (string) ($row->failed_at ?? ''),
            'connection' => (string) ($row->connection ?? ''),
            'queue' => (string) ($row->queue ?? ''),
            'job_name' => $jobName,
            'org_site' => $this->contextDisplay($context),
            'error_summary' => $this->exceptionSummary((string) ($row->exception ?? '')),
            'attempts' => $this->resolveAttempts($payload),
        ];
    }

    /**
     * @param array<int,string> $keys
     */
    private function extractFirstByKeys(array $payload, array $keys): ?string
    {
        $needleSet = array_map('strtolower', $keys);

        $walker = function (mixed $value) use (&$walker, $needleSet): ?string {
            if (! is_array($value)) {
                return null;
            }

            foreach ($value as $k => $v) {
                if (in_array(strtolower((string) $k), $needleSet, true) && (is_scalar($v) || $v === null)) {
                    $resolved = trim((string) $v);
                    if ($resolved !== '') {
                        return $resolved;
                    }
                }

                $nested = $walker($v);
                if ($nested !== null) {
                    return $nested;
                }
            }

            return null;
        };

        return $walker($payload);
    }

    /**
     * @return array<int,string>
     */
    private function knownQueueNames(): array
    {
        return QueueNames::all();
    }

    /**
     * @param array<string,mixed>|null $snapshot
     */
    private function snapshotElapsedMinutes(?array $snapshot): ?float
    {
        $capturedAt = data_get($snapshot, 'captured_at');
        if (! is_string($capturedAt) || $capturedAt === '') {
            return null;
        }

        try {
            $then = Carbon::parse($capturedAt);
        } catch (\Throwable) {
            return null;
        }

        $seconds = max(0, now()->diffInSeconds($then));

        return $seconds >= 30 ? $seconds / 60 : null;
    }

    /**
     * @param array<string,mixed>|null $snapshot
     */
    private function processingRateForQueue(string $queue, int $currentCount, ?array $snapshot, ?float $elapsedMinutes): ?float
    {
        if ($elapsedMinutes === null || $elapsedMinutes <= 0) {
            return null;
        }

        $previousCount = data_get($snapshot, 'counts.' . $queue);
        if (! is_numeric($previousCount)) {
            return null;
        }

        return round(max(0, ((int) $previousCount - $currentCount) / $elapsedMinutes), 2);
    }

    private function aggregateProcessingRate(Collection $queues): ?float
    {
        $rates = $queues
            ->pluck('processing_rate_per_minute')
            ->filter(fn (mixed $rate): bool => is_numeric($rate));

        if ($rates->isEmpty()) {
            return null;
        }

        return round((float) $rates->sum(), 2);
    }

    private function timestampToCarbon(mixed $timestamp): ?Carbon
    {
        if ($timestamp === null || $timestamp === '') {
            return null;
        }

        try {
            return Carbon::createFromTimestamp((int) $timestamp);
        } catch (\Throwable) {
            return null;
        }
    }

    private function pendingJobAuditPayload(object $job): array
    {
        $payload = $this->decodePayload($job->payload);

        return [
            'id' => (int) $job->id,
            'queue' => (string) $job->queue,
            'job_class' => $this->resolveJobName($payload, '', (string) $job->id),
            'attempts' => (int) $job->attempts,
            'created_at' => $this->timestampToCarbon($job->created_at)?->toIso8601String(),
            'reserved_at' => $this->timestampToCarbon($job->reserved_at)?->toIso8601String(),
            'available_at' => $this->timestampToCarbon($job->available_at)?->toIso8601String(),
        ];
    }

    private function pendingAgeThreshold(string $ageRange): ?Carbon
    {
        return match ($ageRange) {
            '10m' => now()->subMinutes(10),
            '1h' => now()->subHour(),
            '24h' => now()->subDay(),
            '7d' => now()->subDays(7),
            default => null,
        };
    }

    private function failedJobAuditPayload(object $job): array
    {
        $payload = $this->decodePayload($job->payload);

        return [
            'id' => (int) $job->id,
            'uuid' => (string) ($job->uuid ?? ''),
            'queue' => (string) ($job->queue ?? ''),
            'connection' => (string) ($job->connection ?? ''),
            'job_class' => $this->resolveJobName($payload, (string) ($job->uuid ?? ''), (string) ($job->id ?? '')),
        ];
    }

    private function forgetFailedJobRecord(string $id): bool
    {
        $failer = app('queue.failer');
        $deleted = false;

        if (method_exists($failer, 'forget')) {
            $deleted = (bool) $failer->forget($id);
        }

        if (! $deleted) {
            $deleted = DB::table('failed_jobs')->where('id', (int) $id)->delete() > 0;
        }

        return $deleted;
    }

    /**
     * @param array<string,mixed>|null $before
     * @param array<string,mixed>|null $after
     */
    private function recordAdminAction(
        ?User $actor,
        string $action,
        string $subjectId,
        ?array $before = null,
        ?array $after = null,
        ?Request $request = null
    ): void {
        if (! Schema::hasTable('audit_logs')) {
            return;
        }

        AuditLog::query()->create([
            'id' => (string) Str::uuid(),
            'actor_type' => $actor ? $actor::class : null,
            'actor_id' => $actor ? (string) $actor->getKey() : null,
            'subject_type' => 'admin.queue',
            'subject_id' => $subjectId,
            'action' => $action,
            'before' => $before,
            'after' => $after,
            'ip' => $request?->ip(),
            'user_agent' => $request?->userAgent(),
            'created_at' => now(),
        ]);
    }
}
