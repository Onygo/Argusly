<?php

namespace App\Services\DataConnectors;

use App\Events\Connectors\ConnectorSyncCancelled;
use App\Events\Connectors\ConnectorSyncCheckpointAdvanced;
use App\Events\Connectors\ConnectorSyncFailed;
use App\Events\Connectors\ConnectorSyncFinished;
use App\Events\Connectors\ConnectorSyncPageProcessed;
use App\Events\Connectors\ConnectorSyncRecovered;
use App\Events\Connectors\ConnectorSyncStarted;
use App\Models\Connectors\ConnectorHealthEvent;
use App\Models\Connectors\ConnectorSyncRun;
use Illuminate\Support\Facades\DB;
use Throwable;

class ConnectorSyncEngine
{
    public function __construct(
        private readonly DataConnectorRegistry $registry,
        private readonly ConnectorSyncRunLogger $runs,
        private readonly ConnectorObservationWriter $writer,
        private readonly ConnectorSyncCheckpoint $checkpoints,
        private readonly ConnectorHealthService $health,
        private readonly ?ConnectorRawRecordWriter $rawRecords = null,
        private readonly ?ConnectorSyncPipeline $pipeline = null,
    ) {
    }

    public function sync(ConnectorSyncPlan $plan): ConnectorSyncResult
    {
        $run = $this->runs->start($plan->account, $plan->dataset, $plan->runType, [
            'window_start' => $plan->dateRangeStart,
            'window_end' => $plan->dateRangeEnd,
            'cursor_before_json' => $plan->checkpoint()->toArray(),
            'retry_json' => [
                'policy' => $plan->retryPolicy(),
                'recoverable' => false,
                'fatal' => false,
            ],
        ]);

        $context = new ConnectorSyncContext($plan, $run);
        event(new ConnectorSyncStarted($context));

        try {
            return ($this->pipeline ?? new ConnectorSyncPipeline)->run(
                $context,
                fn (ConnectorSyncContext $context): ConnectorSyncResult => $this->execute($context)
            );
        } catch (ConnectorSyncCancelledException $exception) {
            $run = $this->runs->cancel($run->fresh(), $exception->getMessage() ?: 'Connector sync run cancelled.');
            event(new ConnectorSyncCancelled(new ConnectorSyncContext($plan, $run)));

            return new ConnectorSyncResult($run, (array) ($run->metrics_json ?? []), ConnectorSyncCursor::from($run->cursor_after_json));
        } catch (ConnectorRecoverableSyncException $exception) {
            $run = $this->recordFailure($context, $exception, true);
            event(new ConnectorSyncFailed(new ConnectorSyncContext($plan, $run), $exception, true));

            return new ConnectorSyncResult($run, (array) ($run->metrics_json ?? []), ConnectorSyncCursor::from($run->cursor_after_json));
        } catch (Throwable $exception) {
            $run = $this->recordFailure($context, $exception, false);
            event(new ConnectorSyncFailed(new ConnectorSyncContext($plan, $run), $exception, false));

            return new ConnectorSyncResult($run, (array) ($run->metrics_json ?? []), ConnectorSyncCursor::from($run->cursor_after_json));
        }
    }

    private function execute(ConnectorSyncContext $context): ConnectorSyncResult
    {
        $adapter = $this->registry->syncAdapter($context->plan->provider);
        $cursor = $context->plan->checkpoint();
        $pages = 0;
        $written = 0;
        $rawWritten = 0;

        do {
            $context->run->refresh();

            if ($context->run->status === ConnectorSyncRun::STATUS_CANCELLED) {
                throw new ConnectorSyncCancelledException('Connector sync run cancelled.');
            }

            $page = $adapter->fetch($context, $cursor);
            $writeResult = DB::transaction(function () use ($context, $page, &$rawWritten): ConnectorObservationWriteResult {
                $writeResult = $this->writer->write($context, $page->observations);
                $rawWritten += ($this->rawRecords ?? new ConnectorRawRecordWriter)->write($context, $page->rawRecords);

                if ($page->nextCursor instanceof ConnectorSyncCursor) {
                    $this->checkpoints->advance($context, $page->nextCursor);
                }

                return $writeResult;
            });

            $pages++;
            $written += $writeResult->written;

            if ($page->nextCursor instanceof ConnectorSyncCursor) {
                $cursor = $page->nextCursor;
                event(new ConnectorSyncCheckpointAdvanced($context, $cursor));
            }

            if ($page->rateLimit !== []) {
                $context->run->forceFill(['rate_limit_json' => $page->rateLimit])->save();
            }

            event(new ConnectorSyncPageProcessed($context, $page, $writeResult));
        } while ($page->hasMore);

        $metrics = [
            'pages' => $pages,
            'observations_written' => $written,
            'raw_records_written' => $rawWritten,
            'records_processed' => $written + $rawWritten,
            'incremental' => $context->plan->incremental,
            'backfill' => $context->plan->backfill,
        ];

        $run = $this->runs->succeed($context->run->fresh(), $metrics, $cursor->toArray());

        $this->health->resolve($context->plan->account, 'Connector sync completed.', [
            'sync_run_id' => $run->id,
            'dataset_id' => $context->plan->dataset->id,
            'observations_written' => $written,
        ], $context->plan->dataset);

        event(new ConnectorSyncFinished(new ConnectorSyncContext($context->plan, $run), $metrics));

        if (($context->plan->account->health_status ?? null) !== ConnectorHealthEvent::STATUS_HEALTHY) {
            event(new ConnectorSyncRecovered(new ConnectorSyncContext($context->plan, $run)));
        }

        return new ConnectorSyncResult($run, $metrics, $cursor);
    }

    private function recordFailure(ConnectorSyncContext $context, Throwable $exception, bool $recoverable): ConnectorSyncRun
    {
        $policy = $context->plan->retryPolicy();
        $nextRetryAt = $recoverable
            ? now()->addSeconds((int) ($policy['backoff_seconds'] ?? 300))
            : null;

        $run = $this->runs->recordRetryBackoff($context->run->fresh(), [
            'policy' => $policy,
            'retry_count' => (int) $context->run->attempts,
            'recoverable' => $recoverable,
            'fatal' => ! $recoverable,
            'error_class' => $exception::class,
            'error_message' => $exception->getMessage(),
            'backoff_seconds' => $recoverable ? (int) ($policy['backoff_seconds'] ?? 300) : null,
        ], $nextRetryAt);

        $run = $this->runs->fail($run, $exception->getMessage(), array_merge((array) ($run->metrics_json ?? []), [
            'recoverable' => $recoverable,
        ]));

        $this->health->record(
            account: $context->plan->account,
            severity: $recoverable ? ConnectorHealthEvent::SEVERITY_WARNING : ConnectorHealthEvent::SEVERITY_ERROR,
            eventType: $recoverable ? 'sync.recoverable_failed' : 'sync.failed',
            message: $exception->getMessage(),
            context: [
                'sync_run_id' => $run->id,
                'dataset_id' => $context->plan->dataset->id,
                'recoverable' => $recoverable,
            ],
            dataset: $context->plan->dataset,
        );

        return $run;
    }
}
