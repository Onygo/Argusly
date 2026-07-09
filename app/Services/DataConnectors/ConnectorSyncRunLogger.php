<?php

namespace App\Services\DataConnectors;

use App\Models\Connectors\ConnectorAccount;
use App\Models\Connectors\ConnectorDataset;
use App\Models\Connectors\ConnectorSyncRun;
use DateTimeInterface;
use InvalidArgumentException;
use Illuminate\Support\Str;

class ConnectorSyncRunLogger
{
    /**
     * @param array<string, mixed> $attributes
     */
    public function start(
        ConnectorAccount $account,
        ?ConnectorDataset $dataset = null,
        string $runType = ConnectorSyncRun::TYPE_MANUAL,
        array $attributes = [],
    ): ConnectorSyncRun {
        $run = ConnectorSyncRun::query()->create(array_merge([
            'connector_account_id' => $account->id,
            'connector_dataset_id' => $dataset?->id,
            'workspace_id' => $account->workspace_id,
            'client_site_id' => $dataset?->client_site_id ?? $account->client_site_id,
            'provider_key' => $account->provider_key,
            'dataset_key' => $dataset?->dataset_key,
            'status' => ConnectorSyncRun::STATUS_RUNNING,
            'run_type' => $runType,
            'started_at' => now(),
            'attempts' => 1,
            'metrics_json' => [],
            'rate_limit_json' => [],
            'retry_json' => [],
            'idempotency_key' => (string) Str::uuid(),
        ], $attributes));

        return $run;
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public function transition(ConnectorSyncRun $run, string $status, array $attributes = []): ConnectorSyncRun
    {
        $from = (string) $run->status;

        if (! $run->canTransitionTo($status)) {
            throw new InvalidArgumentException("Connector sync run cannot transition from [{$from}] to [{$status}].");
        }

        $attributes['status'] = $status;

        if ($status === ConnectorSyncRun::STATUS_RUNNING && empty($attributes['started_at']) && $run->started_at === null) {
            $attributes['started_at'] = now();
        }

        if (in_array($status, ConnectorSyncRun::TERMINAL_STATUSES, true) && empty($attributes['finished_at'])) {
            $attributes['finished_at'] = now();
        }

        if ($status === ConnectorSyncRun::STATUS_CANCELLED && empty($attributes['cancelled_at'])) {
            $attributes['cancelled_at'] = $attributes['finished_at'] ?? now();
        }

        $run->forceFill($attributes)->save();

        return $run;
    }

    /**
     * @param array<string, mixed> $metrics
     * @param array<string, mixed>|null $cursorAfter
     */
    public function succeed(ConnectorSyncRun $run, array $metrics = [], ?array $cursorAfter = null): ConnectorSyncRun
    {
        $run = $this->transition($run, ConnectorSyncRun::STATUS_SUCCEEDED, [
            'finished_at' => now(),
            'metrics_json' => $metrics,
            'cursor_after_json' => $cursorAfter,
            'error_message' => null,
            'next_retry_at' => null,
        ]);

        $run->account?->forceFill(['last_synced_at' => $run->finished_at])->save();
        $run->dataset?->forceFill(['last_sync_at' => $run->finished_at])->save();

        return $run;
    }

    /**
     * @param array<string, mixed> $metrics
     */
    public function fail(ConnectorSyncRun $run, string $message, array $metrics = []): ConnectorSyncRun
    {
        return $this->transition($run, ConnectorSyncRun::STATUS_FAILED, [
            'finished_at' => now(),
            'error_message' => $message,
            'metrics_json' => $metrics,
        ]);
    }

    public function skip(ConnectorSyncRun $run, string $message): ConnectorSyncRun
    {
        return $this->transition($run, ConnectorSyncRun::STATUS_SKIPPED, [
            'finished_at' => now(),
            'error_message' => $message,
        ]);
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public function cancel(ConnectorSyncRun $run, string $message = 'Connector sync run cancelled.', array $metadata = []): ConnectorSyncRun
    {
        return $this->transition($run, ConnectorSyncRun::STATUS_CANCELLED, [
            'finished_at' => now(),
            'cancelled_at' => now(),
            'error_message' => $message,
            'retry_json' => array_merge($run->retry_json ?? [], [
                'cancelled' => true,
                'cancelled_at' => now()->toIso8601String(),
            ], $this->sanitizeMetadata($metadata)),
        ]);
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public function recordRetryBackoff(
        ConnectorSyncRun $run,
        array $metadata,
        ?DateTimeInterface $nextRetryAt = null,
    ): ConnectorSyncRun {
        $run->forceFill([
            'retry_json' => array_merge($run->retry_json ?? [], [
                'recorded_at' => now()->toIso8601String(),
            ], $this->sanitizeMetadata($metadata)),
            'next_retry_at' => $nextRetryAt,
        ])->save();

        return $run;
    }

    public function recoverStaleRunning(?DateTimeInterface $staleBefore = null, int $limit = 100): int
    {
        $staleBefore ??= now()->subMinutes((int) config('data_connectors.sync.stale_running_after_minutes', 60));
        $count = 0;

        ConnectorSyncRun::query()
            ->where('status', ConnectorSyncRun::STATUS_RUNNING)
            ->whereNotNull('started_at')
            ->where('started_at', '<=', $staleBefore)
            ->oldest('started_at')
            ->limit($limit)
            ->get()
            ->each(function (ConnectorSyncRun $run) use (&$count, $staleBefore): void {
                $this->recordRetryBackoff($run, [
                    'stale' => true,
                    'stale_before' => $staleBefore instanceof DateTimeInterface
                        ? $staleBefore->format(DateTimeInterface::ATOM)
                        : null,
                ]);

                $this->fail(
                    $run,
                    ConnectorSyncRun::STALE_FAILURE_MARKER.' Running sync exceeded the stale-running threshold.',
                    $run->metrics_json ?? [],
                );

                $count++;
            });

        return $count;
    }

    /**
     * @param array<string, mixed> $metadata
     * @return array<string, mixed>
     */
    private function sanitizeMetadata(array $metadata): array
    {
        $sanitized = [];

        foreach ($metadata as $key => $value) {
            $keyString = (string) $key;

            if (preg_match('/(secret|token|password|authorization|api[_-]?key|client[_-]?secret)/i', $keyString) === 1) {
                $sanitized[$keyString] = '[redacted]';

                continue;
            }

            $sanitized[$keyString] = is_array($value)
                ? $this->sanitizeMetadata($value)
                : $value;
        }

        return $sanitized;
    }
}
