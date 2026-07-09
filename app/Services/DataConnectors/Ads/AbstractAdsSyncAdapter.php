<?php

namespace App\Services\DataConnectors\Ads;

use App\Models\Connectors\ConnectorAsyncReportJob;
use App\Services\DataConnectors\ConnectorFatalSyncException;
use App\Services\DataConnectors\ConnectorProviderHttpClient;
use App\Services\DataConnectors\ConnectorRecoverableSyncException;
use App\Services\DataConnectors\ConnectorSyncAdapter;
use App\Services\DataConnectors\ConnectorSyncContext;
use App\Services\DataConnectors\ConnectorSyncCursor;
use App\Services\DataConnectors\ConnectorSyncPage;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;

abstract class AbstractAdsSyncAdapter implements ConnectorSyncAdapter
{
    public function __construct(protected readonly ConnectorProviderHttpClient $http)
    {
    }

    public function fetch(ConnectorSyncContext $context, ConnectorSyncCursor $cursor): ConnectorSyncPage
    {
        $response = $this->requestReport($context, $cursor);
        $this->throwIfFailed($response);

        if ($this->isAsyncReportAccepted($response)) {
            $job = $this->recordAsyncReportJob($context, $response);

            return new ConnectorSyncPage(
                rawRecords: [[
                    'record_type' => 'async_report_job',
                    'external_record_id' => $job->external_report_id ?: (string) $job->id,
                    'observed_at' => now()->toDateTimeString(),
                    'payload' => $response->json() ?: [],
                    'metadata' => [
                        'provider' => $context->plan->provider,
                        'report_type' => $job->report_type,
                        'async_report_job_id' => $job->id,
                    ],
                ]],
            );
        }

        $rows = $this->rows($response);
        $nextCursor = $this->nextCursor($response, $cursor);
        $dateRange = $this->dateRange($context);

        return new ConnectorSyncPage(
            observations: [],
            nextCursor: $nextCursor,
            hasMore: $nextCursor instanceof ConnectorSyncCursor && $nextCursor->has('page_token'),
            metadata: [
                'provider' => $context->plan->provider,
                'row_count' => count($rows),
                'raw_only' => true,
            ],
            rateLimit: $this->rateLimit($response),
            rawRecords: $this->rawRecords($context, $rows, $dateRange),
        );
    }

    abstract protected function requestReport(ConnectorSyncContext $context, ConnectorSyncCursor $cursor): Response;

    /**
     * @return array{start: string, end: string}
     */
    protected function dateRange(ConnectorSyncContext $context): array
    {
        $start = $context->plan->dateRangeStart
            ? Carbon::instance($context->plan->dateRangeStart)->toDateString()
            : now()->subDays(7)->toDateString();

        $end = $context->plan->dateRangeEnd
            ? Carbon::instance($context->plan->dateRangeEnd)->toDateString()
            : now()->subDay()->toDateString();

        return ['start' => $start, 'end' => $end];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function rows(Response $response): array
    {
        $rows = $response->json('rows')
            ?: $response->json('results')
            ?: $response->json('data')
            ?: $response->json('value')
            ?: [];

        return array_values(array_filter((array) $rows, fn (mixed $row): bool => is_array($row)));
    }

    protected function nextCursor(Response $response, ConnectorSyncCursor $cursor): ?ConnectorSyncCursor
    {
        unset($cursor);

        $token = trim((string) (
            $response->json('nextPageToken')
            ?: data_get($response->json(), 'paging.cursors.after')
            ?: $response->json('@odata.nextLink')
            ?: ''
        ));

        return $token === '' ? new ConnectorSyncCursor(['synced_until' => now()->toDateString()]) : new ConnectorSyncCursor(['page_token' => $token]);
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @param array{start: string, end: string} $dateRange
     * @return array<int, array<string, mixed>>
     */
    protected function rawRecords(ConnectorSyncContext $context, array $rows, array $dateRange): array
    {
        return collect($rows)
            ->map(function (array $row) use ($context, $dateRange): array {
                $periodDate = $this->rowDate($row, $dateRange['start']);

                return [
                    'record_type' => $context->plan->dataset->dataset_type,
                    'external_record_id' => $this->externalRecordId($context, $row, $periodDate),
                    'period_start' => Carbon::parse($periodDate)->startOfDay()->toDateTimeString(),
                    'period_end' => Carbon::parse($periodDate)->endOfDay()->toDateTimeString(),
                    'observed_at' => now()->toDateTimeString(),
                    'payload' => $row,
                    'metadata' => [
                        'provider' => $context->plan->provider,
                        'dataset_type' => $context->plan->dataset->dataset_type,
                        'metrics' => ['impressions', 'clicks', 'cost', 'conversions', 'ctr', 'cpc', 'cpm'],
                    ],
                ];
            })
            ->values()
            ->all();
    }

    protected function rowDate(array $row, string $default): string
    {
        return (string) (
            data_get($row, 'segments.date')
            ?: $row['date_start']
            ?? $row['date']
            ?? $row['day']
            ?? $default
        );
    }

    protected function externalRecordId(ConnectorSyncContext $context, array $row, string $periodDate): string
    {
        return $context->plan->provider.':'.hash('sha256', json_encode([
            'dataset' => $context->plan->dataset->external_dataset_id,
            'type' => $context->plan->dataset->dataset_type,
            'date' => $periodDate,
            'id' => data_get($row, 'id')
                ?: data_get($row, 'campaign.id')
                ?: data_get($row, 'ad_group.id')
                ?: data_get($row, 'adset_id')
                ?: null,
            'row' => $row,
        ], JSON_THROW_ON_ERROR));
    }

    protected function recordAsyncReportJob(ConnectorSyncContext $context, Response $response): ConnectorAsyncReportJob
    {
        $payload = (array) ($response->json() ?: []);
        $externalId = trim((string) (
            $payload['report_job_id']
            ?? $payload['reportRunId']
            ?? $payload['id']
            ?? $payload['job_id']
            ?? ''
        ));

        return ConnectorAsyncReportJob::query()->create([
            'workspace_id' => $context->plan->workspace->id,
            'connector_account_id' => $context->plan->account->id,
            'connector_dataset_id' => $context->plan->dataset->id,
            'provider_key' => $context->plan->provider,
            'dataset_key' => $context->plan->dataset->dataset_key,
            'report_type' => $context->plan->dataset->dataset_type,
            'external_report_id' => $externalId === '' ? null : $externalId,
            'status' => ConnectorAsyncReportJob::STATUS_PENDING,
            'requested_at' => now(),
            'rate_limit_json' => $this->rateLimit($response),
            'payload_json' => $payload,
            'metadata_json' => [
                'async_reports_supported' => true,
                'sync_run_id' => $context->run->id,
            ],
        ]);
    }

    protected function isAsyncReportAccepted(Response $response): bool
    {
        if ($response->status() === 202) {
            return true;
        }

        $payload = (array) ($response->json() ?: []);

        return isset($payload['report_job_id'], $payload['async_status'])
            || isset($payload['reportRunId'])
            || (isset($payload['id'], $payload['status']) && in_array((string) $payload['status'], ['pending', 'running', 'started'], true));
    }

    protected function throwIfFailed(Response $response): void
    {
        if ($response->successful()) {
            return;
        }

        $message = class_basename(static::class).' request failed with status '.$response->status().'.';

        if ($response->status() === 429 || $response->status() >= 500) {
            throw new ConnectorRecoverableSyncException($message);
        }

        throw new ConnectorFatalSyncException($message);
    }

    /**
     * @return array<string, mixed>
     */
    protected function rateLimit(Response $response): array
    {
        return array_filter([
            'limit' => $response->header('X-RateLimit-Limit') ?: $response->header('x-ms-ratelimit-limit'),
            'remaining' => $response->header('X-RateLimit-Remaining') ?: $response->header('x-ms-ratelimit-remaining'),
            'reset' => $response->header('X-RateLimit-Reset') ?: $response->header('Retry-After'),
        ], fn (mixed $value): bool => $value !== null && $value !== '');
    }

    protected function apiBaseUrl(string $providerKey): string
    {
        return rtrim((string) config('data_connectors.providers.'.$providerKey.'.config_json.api.base_url'), '/');
    }

    protected function timeoutSeconds(string $providerKey): int
    {
        return max(1, (int) config('data_connectors.providers.'.$providerKey.'.config_json.api.timeout_seconds', 15));
    }
}
