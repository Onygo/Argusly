<?php

namespace App\Services\DataConnectors\Normalization;

use App\Data\Connectors\NormalizedRecord;
use App\Events\Connectors\Normalization\NormalizedCampaignDataUpdated;
use App\Events\Connectors\Normalization\NormalizedCrmDataUpdated;
use App\Events\Connectors\Normalization\NormalizedLeadDataUpdated;
use App\Events\Connectors\Normalization\NormalizedMarketingPerformanceUpdated;
use App\Events\Connectors\Normalization\NormalizedSalesDataUpdated;
use App\Jobs\Connectors\TransformConnectorRawRecordsJob;
use App\Models\Connectors\ConnectorAccount;
use App\Models\Connectors\ConnectorBackfillRange;
use App\Models\Connectors\ConnectorRawRecord;
use App\Models\Connectors\NormalizationRun;
use App\Models\Connectors\NormalizationRunItem;
use App\Services\DataConnectors\ConnectorSyncContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

class ConnectorNormalizationService
{
    public function __construct(
        private readonly NormalizedRecordMapperResolver $mappers,
        private readonly NormalizedRecordWriter $writer,
    ) {}

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function enqueueForSyncContext(
        ConnectorSyncContext $context,
        string $trigger,
        array $metadata = [],
    ): NormalizationRun {
        return $this->enqueue([
            'workspace_id' => $context->plan->workspace->id,
            'connector_account_id' => $context->plan->account->id,
            'connector_dataset_id' => $context->plan->dataset->id,
            'connector_sync_run_id' => $context->run->id,
            'provider' => $context->plan->provider,
            'dataset_key' => $context->plan->dataset->dataset_key,
            'source_type' => 'sync_run',
            'source_key' => (string) $context->run->id,
            'scope_start_date' => $context->run->window_start?->toDateString(),
            'scope_end_date' => $context->run->window_end?->toDateString(),
            'trigger' => $trigger,
            'metadata_json' => array_filter($metadata),
        ]);
    }

    public function enqueueForAccount(ConnectorAccount $account, string $trigger = 'manual'): NormalizationRun
    {
        return $this->enqueue([
            'workspace_id' => $account->workspace_id,
            'connector_account_id' => $account->id,
            'provider' => $account->provider_key,
            'source_type' => 'connector_account',
            'source_key' => (string) $account->id,
            'trigger' => $trigger,
            'metadata_json' => [
                'manual' => $trigger === 'manual',
                'scope' => 'connector_account',
            ],
        ]);
    }

    public function enqueueForBackfillRange(ConnectorBackfillRange $range): ?NormalizationRun
    {
        if ($range->status !== ConnectorBackfillRange::STATUS_SUCCEEDED || ! $range->connector_sync_run_id) {
            return null;
        }

        return $this->enqueue([
            'workspace_id' => $range->workspace_id,
            'connector_account_id' => $range->connector_account_id,
            'connector_dataset_id' => $range->connector_dataset_id,
            'connector_sync_run_id' => $range->connector_sync_run_id,
            'connector_backfill_range_id' => $range->id,
            'provider' => $range->provider_key,
            'dataset_key' => $range->dataset_key,
            'source_type' => 'backfill_range',
            'source_key' => (string) $range->id,
            'scope_start_date' => $range->range_start?->toDateString(),
            'scope_end_date' => $range->range_end?->toDateString(),
            'trigger' => 'backfill_completed',
            'metadata_json' => [
                'range_start' => $range->range_start?->toDateString(),
                'range_end' => $range->range_end?->toDateString(),
            ],
        ]);
    }

    public function retry(NormalizationRun $run): NormalizationRun
    {
        $scope = $this->scopeAttributesFromRun($run);

        if (! $run->source_type && ! $run->connector_sync_run_id && ! $run->connector_backfill_range_id) {
            $scope['source_type'] = 'normalization_run_retry';
            $scope['source_key'] = (string) $run->id;
        }

        $attributes = $this->preparedRunAttributes(array_merge(
            $scope,
            [
                'trigger' => $run->trigger,
                'metadata_json' => array_merge((array) ($run->metadata_json ?? []), [
                    'retried_at' => now()->toIso8601String(),
                ]),
            ],
        ));

        $run = $this->activateExistingRun($run, [
            'source_type' => $attributes['source_type'],
            'source_key' => $attributes['source_key'],
            'scope_start_date' => $attributes['scope_start_date'],
            'scope_end_date' => $attributes['scope_end_date'],
            'scope_hash' => $attributes['scope_hash'],
            'active_scope_hash' => $attributes['active_scope_hash'],
            'status' => NormalizationRun::STATUS_PENDING,
            'started_at' => null,
            'finished_at' => null,
            'duration_ms' => null,
            'records_processed' => 0,
            'records_written' => 0,
            'records_failed' => 0,
            'records_skipped' => 0,
            'latest_error' => null,
            'metadata_json' => $attributes['metadata_json'],
        ]);

        TransformConnectorRawRecordsJob::dispatch((string) $run->id);

        return $run;
    }

    public function reprocess(NormalizationRun $run, string $trigger = 'manual_reprocess'): NormalizationRun
    {
        return $this->enqueue(array_merge($this->scopeAttributesFromRun($run), [
            'trigger' => $trigger,
            'metadata_json' => array_merge((array) ($run->metadata_json ?? []), [
                'reprocessed_from_run_id' => (string) $run->id,
                'reprocessed_at' => now()->toIso8601String(),
            ]),
        ]));
    }

    public function normalizeRunId(string $runId): ?NormalizationRun
    {
        $run = NormalizationRun::query()
            ->with(['account', 'dataset', 'syncRun', 'backfillRange'])
            ->find($runId);

        if (! $run instanceof NormalizationRun) {
            return null;
        }

        return $this->normalize($run);
    }

    public function normalize(NormalizationRun $run): NormalizationRun
    {
        $claimed = $this->claim($run);

        if (! $claimed instanceof NormalizationRun) {
            return $run->fresh() ?? $run;
        }

        $run = $claimed;

        if (! $this->mappers->has($run->provider)) {
            return $this->skip($run, 'No mapper is configured for this provider.');
        }

        $startedAt = microtime(true);

        $mapper = $this->mappers->resolve($run->provider);
        $entityCounts = [];
        $processed = 0;
        $written = 0;
        $failed = 0;
        $skipped = 0;
        $latestError = null;

        $this->rawRecordQuery($run)
            ->orderBy('created_at')
            ->chunk(250, function ($rawRecords) use ($run, $mapper, &$entityCounts, &$processed, &$written, &$failed, &$skipped, &$latestError): void {
                foreach ($rawRecords as $rawRecord) {
                    $processed++;

                    $item = NormalizationRunItem::query()->updateOrCreate(
                        [
                            'connector_normalization_run_id' => $run->id,
                            'connector_raw_record_id' => $rawRecord->id,
                        ],
                        [
                            'entity_type' => $rawRecord->record_type,
                            'status' => NormalizationRunItem::STATUS_RUNNING,
                            'records_written' => 0,
                            'error_message' => null,
                            'metadata_json' => [
                                'provider' => $rawRecord->provider_key,
                                'dataset_key' => $rawRecord->dataset_key,
                            ],
                        ],
                    );

                    try {
                        $records = $mapper->map($rawRecord);

                        if ($records === []) {
                            $skipped++;
                            $item->forceFill([
                                'status' => NormalizationRunItem::STATUS_SKIPPED,
                                'metadata_json' => array_merge((array) ($item->metadata_json ?? []), [
                                    'reason' => 'mapper_returned_no_records',
                                ]),
                            ])->save();

                            continue;
                        }

                        $result = DB::transaction(fn (): array => $this->writer->write($rawRecord, $records));
                        $written += (int) $result['written'];
                        foreach ($result['entity_counts'] as $entityType => $count) {
                            $entityCounts[$entityType] = ($entityCounts[$entityType] ?? 0) + $count;
                        }

                        $item->forceFill([
                            'status' => NormalizationRunItem::STATUS_COMPLETED,
                            'records_written' => (int) $result['written'],
                            'metadata_json' => array_merge((array) ($item->metadata_json ?? []), [
                                'normalized_entity_types' => collect($records)->map(fn (NormalizedRecord $record): string => $record->entityType)->unique()->values()->all(),
                            ]),
                        ])->save();
                    } catch (Throwable $exception) {
                        $failed++;
                        $latestError = $exception->getMessage();

                        $item->forceFill([
                            'status' => NormalizationRunItem::STATUS_FAILED,
                            'error_message' => $exception->getMessage(),
                            'metadata_json' => array_merge((array) ($item->metadata_json ?? []), [
                                'error_class' => $exception::class,
                            ]),
                        ])->save();
                    }
                }
            });

        $status = match (true) {
            $processed === 0 => NormalizationRun::STATUS_SKIPPED,
            $failed > 0 => NormalizationRun::STATUS_FAILED,
            default => NormalizationRun::STATUS_COMPLETED,
        };

        $run->forceFill([
            'status' => $status,
            'finished_at' => now(),
            'active_scope_hash' => null,
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            'records_processed' => $processed,
            'records_written' => $written,
            'records_failed' => $failed,
            'records_skipped' => $skipped + ($processed === 0 ? 1 : 0),
            'latest_error' => $latestError,
            'metadata_json' => array_merge((array) ($run->metadata_json ?? []), [
                'entity_counts' => $entityCounts,
            ]),
        ])->save();

        if ($written > 0) {
            $this->dispatchFeedEvents($run->fresh(), $entityCounts);
        }

        return $run->fresh();
    }

    private function skip(NormalizationRun $run, string $reason): NormalizationRun
    {
        $run->forceFill([
            'status' => NormalizationRun::STATUS_SKIPPED,
            'started_at' => now(),
            'finished_at' => now(),
            'active_scope_hash' => null,
            'records_skipped' => 1,
            'latest_error' => $reason,
            'metadata_json' => array_merge((array) ($run->metadata_json ?? []), [
                'skip_reason' => $reason,
            ]),
        ])->save();

        return $run;
    }

    private function rawRecordQuery(NormalizationRun $run): Builder
    {
        return ConnectorRawRecord::query()
            ->with(['dataset'])
            ->where('workspace_id', $run->workspace_id)
            ->where('provider_key', $run->provider)
            ->when($run->connector_account_id, fn (Builder $query): Builder => $query->where('connector_account_id', $run->connector_account_id))
            ->when($run->connector_dataset_id, fn (Builder $query): Builder => $query->where('connector_dataset_id', $run->connector_dataset_id))
            ->when($run->connector_sync_run_id, fn (Builder $query): Builder => $query->where('connector_sync_run_id', $run->connector_sync_run_id))
            ->when($run->scope_start_date, function (Builder $query, Carbon $start): Builder {
                return $query->whereDate('period_start', '>=', $start->toDateString());
            })
            ->when($run->scope_end_date, function (Builder $query, Carbon $end): Builder {
                return $query->whereDate('period_start', '<=', $end->toDateString());
            })
            ->when(data_get($run->metadata_json, 'range_start'), function (Builder $query, string $start): Builder {
                return $query->whereDate('period_start', '>=', Carbon::parse($start)->toDateString());
            })
            ->when(data_get($run->metadata_json, 'range_end'), function (Builder $query, string $end): Builder {
                return $query->whereDate('period_start', '<=', Carbon::parse($end)->toDateString());
            });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function enqueue(array $attributes): NormalizationRun
    {
        $attributes = $this->preparedRunAttributes($attributes);

        try {
            $run = NormalizationRun::query()->create($attributes);
        } catch (QueryException $exception) {
            $run = $this->activeRunForHash((string) $attributes['active_scope_hash']);

            if (! $run instanceof NormalizationRun) {
                throw $exception;
            }

            $this->recordCollapsedRequest($run, $attributes);

            return $run;
        }

        TransformConnectorRawRecordsJob::dispatch((string) $run->id);

        return $run;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function preparedRunAttributes(array $attributes): array
    {
        $attributes['scope_start_date'] = $this->dateOrNull($attributes['scope_start_date'] ?? null);
        $attributes['scope_end_date'] = $this->dateOrNull($attributes['scope_end_date'] ?? null);
        $attributes['source_type'] = $this->stringOrNull($attributes['source_type'] ?? null);
        $attributes['source_key'] = $this->stringOrNull($attributes['source_key'] ?? null);
        $attributes['dataset_key'] = $this->stringOrNull($attributes['dataset_key'] ?? null);
        $attributes['scope_hash'] = NormalizationRun::scopeHashFor($attributes);
        $attributes['active_scope_hash'] = $attributes['scope_hash'];
        $attributes['status'] = NormalizationRun::STATUS_PENDING;
        $attributes['metadata_json'] = (array) ($attributes['metadata_json'] ?? []);

        return $attributes;
    }

    /**
     * @return array<string, mixed>
     */
    private function scopeAttributesFromRun(NormalizationRun $run): array
    {
        return [
            'workspace_id' => $run->workspace_id,
            'connector_account_id' => $run->connector_account_id,
            'connector_dataset_id' => $run->connector_dataset_id,
            'connector_sync_run_id' => $run->connector_sync_run_id,
            'connector_backfill_range_id' => $run->connector_backfill_range_id,
            'provider' => $run->provider,
            'dataset_key' => $run->dataset_key,
            'source_type' => $run->source_type ?: $this->inferSourceType($run),
            'source_key' => $run->source_key ?: $this->inferSourceKey($run),
            'scope_start_date' => $run->scope_start_date ?: data_get($run->metadata_json, 'range_start'),
            'scope_end_date' => $run->scope_end_date ?: data_get($run->metadata_json, 'range_end'),
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function activateExistingRun(NormalizationRun $run, array $attributes): NormalizationRun
    {
        try {
            return DB::transaction(function () use ($run, $attributes): NormalizationRun {
                $locked = NormalizationRun::query()
                    ->whereKey($run->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $locked->forceFill($attributes)->save();

                return $locked->fresh();
            });
        } catch (QueryException $exception) {
            $active = $this->activeRunForHash((string) ($attributes['active_scope_hash'] ?? ''));

            if ($active instanceof NormalizationRun) {
                return $active;
            }

            throw $exception;
        }
    }

    private function claim(NormalizationRun $run): ?NormalizationRun
    {
        return DB::transaction(function () use ($run): ?NormalizationRun {
            $locked = NormalizationRun::query()
                ->whereKey($run->id)
                ->lockForUpdate()
                ->first();

            if (! $locked instanceof NormalizationRun) {
                return null;
            }

            if ($locked->status !== NormalizationRun::STATUS_PENDING) {
                return null;
            }

            $attributes = $this->preparedRunAttributes(array_merge(
                $this->scopeAttributesFromRun($locked),
                [
                    'trigger' => $locked->trigger,
                    'metadata_json' => (array) ($locked->metadata_json ?? []),
                ],
            ));

            $locked->forceFill([
                'source_type' => $attributes['source_type'],
                'source_key' => $attributes['source_key'],
                'scope_start_date' => $attributes['scope_start_date'],
                'scope_end_date' => $attributes['scope_end_date'],
                'scope_hash' => $attributes['scope_hash'],
                'active_scope_hash' => $attributes['active_scope_hash'],
                'status' => NormalizationRun::STATUS_RUNNING,
                'started_at' => now(),
                'finished_at' => null,
                'duration_ms' => null,
                'records_processed' => 0,
                'records_written' => 0,
                'records_failed' => 0,
                'records_skipped' => 0,
                'latest_error' => null,
            ])->save();

            return $locked->fresh(['account', 'dataset', 'syncRun', 'backfillRange']);
        });
    }

    private function activeRunForHash(string $hash): ?NormalizationRun
    {
        if ($hash === '') {
            return null;
        }

        return NormalizationRun::query()
            ->where('active_scope_hash', $hash)
            ->whereIn('status', NormalizationRun::activeStatuses())
            ->latest('created_at')
            ->first();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function recordCollapsedRequest(NormalizationRun $run, array $attributes): void
    {
        $metadata = (array) ($run->metadata_json ?? []);
        $collapsed = (array) ($metadata['collapsed_requests'] ?? []);
        $collapsed[] = [
            'trigger' => $attributes['trigger'] ?? null,
            'requested_at' => now()->toIso8601String(),
        ];

        $run->forceFill([
            'metadata_json' => array_merge($metadata, [
                'collapsed_requests' => array_slice($collapsed, -10),
                'collapsed_request_count' => (int) ($metadata['collapsed_request_count'] ?? 0) + 1,
            ]),
        ])->save();
    }

    private function inferSourceType(NormalizationRun $run): string
    {
        if ($run->connector_backfill_range_id) {
            return 'backfill_range';
        }

        if ($run->connector_sync_run_id) {
            return 'sync_run';
        }

        return 'connector_account';
    }

    private function inferSourceKey(NormalizationRun $run): ?string
    {
        return (string) (
            $run->connector_backfill_range_id
            ?: $run->connector_sync_run_id
            ?: $run->connector_account_id
            ?: ''
        ) ?: null;
    }

    private function dateOrNull(mixed $value): ?string
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        return Carbon::parse($value)->toDateString();
    }

    private function stringOrNull(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /**
     * @param  array<string, int>  $entityCounts
     */
    private function dispatchFeedEvents(NormalizationRun $run, array $entityCounts): void
    {
        if (($entityCounts[NormalizedRecord::DAILY_PERFORMANCE] ?? 0) > 0) {
            event(new NormalizedMarketingPerformanceUpdated($run, $entityCounts));
        }

        if (
            ($entityCounts[NormalizedRecord::MARKETING_ACCOUNT] ?? 0) > 0
            || ($entityCounts[NormalizedRecord::CAMPAIGN] ?? 0) > 0
            || ($entityCounts[NormalizedRecord::AD_GROUP] ?? 0) > 0
            || ($entityCounts[NormalizedRecord::AD] ?? 0) > 0
        ) {
            event(new NormalizedCampaignDataUpdated($run, $entityCounts));
        }

        if (
            ($entityCounts[NormalizedRecord::CRM_COMPANY] ?? 0) > 0
            || ($entityCounts[NormalizedRecord::CRM_CONTACT] ?? 0) > 0
            || ($entityCounts[NormalizedRecord::CRM_DEAL] ?? 0) > 0
            || ($entityCounts[NormalizedRecord::CRM_ACTIVITY] ?? 0) > 0
        ) {
            event(new NormalizedCrmDataUpdated($run, $entityCounts));
        }

        if (($entityCounts[NormalizedRecord::CRM_CONTACT] ?? 0) > 0) {
            event(new NormalizedLeadDataUpdated($run, $entityCounts));
        }

        if (($entityCounts[NormalizedRecord::CRM_DEAL] ?? 0) > 0) {
            event(new NormalizedSalesDataUpdated($run, $entityCounts));
        }
    }
}
