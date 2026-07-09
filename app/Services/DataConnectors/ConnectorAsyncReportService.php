<?php

namespace App\Services\DataConnectors;

use App\Models\Connectors\ConnectorAsyncReportJob;
use App\Models\Connectors\ConnectorDataset;
use Illuminate\Database\Eloquent\Collection;

class ConnectorAsyncReportService
{
    /**
     * @return Collection<int, ConnectorAsyncReportJob>
     */
    public function latestForDataset(ConnectorDataset $dataset, int $limit = 10): Collection
    {
        return ConnectorAsyncReportJob::query()
            ->where('connector_dataset_id', $dataset->id)
            ->latest('created_at')
            ->limit(max(1, $limit))
            ->get();
    }

    public function markReady(ConnectorAsyncReportJob $job, array $payload = []): ConnectorAsyncReportJob
    {
        $job->forceFill([
            'status' => ConnectorAsyncReportJob::STATUS_READY,
            'ready_at' => now(),
            'payload_json' => array_merge((array) ($job->payload_json ?? []), $payload),
        ])->save();

        return $job->fresh();
    }

    public function markSucceeded(ConnectorAsyncReportJob $job, array $payload = []): ConnectorAsyncReportJob
    {
        $job->forceFill([
            'status' => ConnectorAsyncReportJob::STATUS_SUCCEEDED,
            'completed_at' => now(),
            'payload_json' => array_merge((array) ($job->payload_json ?? []), $payload),
        ])->save();

        return $job->fresh();
    }

    public function markFailed(ConnectorAsyncReportJob $job, string $message): ConnectorAsyncReportJob
    {
        $job->forceFill([
            'status' => ConnectorAsyncReportJob::STATUS_FAILED,
            'failed_at' => now(),
            'metadata_json' => array_merge((array) ($job->metadata_json ?? []), [
                'last_error' => $message,
            ]),
        ])->save();

        return $job->fresh();
    }
}
