<?php

namespace App\Services\DataConnectors\Crm;

use App\Services\DataConnectors\ConnectorFatalSyncException;
use App\Services\DataConnectors\ConnectorProviderHttpClient;
use App\Services\DataConnectors\ConnectorRecoverableSyncException;
use App\Services\DataConnectors\ConnectorSyncAdapter;
use App\Services\DataConnectors\ConnectorSyncContext;
use App\Services\DataConnectors\ConnectorSyncCursor;
use App\Services\DataConnectors\ConnectorSyncPage;
use Illuminate\Http\Client\Response;

abstract class AbstractCrmSyncAdapter implements ConnectorSyncAdapter
{
    public function __construct(protected readonly ConnectorProviderHttpClient $http)
    {
    }

    public function fetch(ConnectorSyncContext $context, ConnectorSyncCursor $cursor): ConnectorSyncPage
    {
        $response = $this->requestObjects($context, $cursor);
        $this->throwIfFailed($response);

        $rows = $this->rows($response);
        $nextCursor = $this->nextCursor($response, $cursor, $rows);

        return new ConnectorSyncPage(
            observations: [],
            nextCursor: $nextCursor,
            hasMore: $nextCursor->has('page_token') || $nextCursor->has('after') || $nextCursor->has('start'),
            metadata: [
                'provider' => $context->plan->provider,
                'object' => $context->plan->dataset->dataset_type,
                'sync_mode' => 'cursor_incremental',
                'raw_only' => true,
            ],
            rateLimit: $this->rateLimit($response),
            rawRecords: $this->rawRecords($context, $rows),
        );
    }

    abstract protected function requestObjects(ConnectorSyncContext $context, ConnectorSyncCursor $cursor): Response;

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function rows(Response $response): array
    {
        return array_values(array_filter((array) (
            $response->json('results')
            ?: $response->json('records')
            ?: $response->json('data')
            ?: $response->json('value')
            ?: []
        ), fn (mixed $row): bool => is_array($row)));
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    protected function nextCursor(Response $response, ConnectorSyncCursor $cursor, array $rows): ConnectorSyncCursor
    {
        unset($cursor);

        $nextPage = trim((string) (
            data_get($response->json(), 'paging.next.after')
            ?: data_get($response->json(), 'additional_data.pagination.next_start')
            ?: $response->json('nextRecordsUrl')
            ?: $response->json('nextPageToken')
            ?: ''
        ));

        if ($nextPage !== '') {
            return new ConnectorSyncCursor(['after' => $nextPage, 'page_token' => $nextPage]);
        }

        $watermark = collect($rows)
            ->map(fn (array $row): string => $this->updatedAt($row))
            ->filter()
            ->sort()
            ->last();

        return new ConnectorSyncCursor(array_filter([
            'last_updated_at' => $watermark ?: now()->toIso8601String(),
        ]));
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    protected function rawRecords(ConnectorSyncContext $context, array $rows): array
    {
        return collect($rows)
            ->map(fn (array $row): array => [
                'record_type' => $context->plan->dataset->dataset_type,
                'external_record_id' => (string) (
                    $row['id']
                    ?? $row['Id']
                    ?? data_get($row, 'properties.hs_object_id')
                    ?? $this->externalRecordId($context, $row)
                ),
                'observed_at' => now()->toDateTimeString(),
                'payload' => $row,
                'metadata' => [
                    'provider' => $context->plan->provider,
                    'object' => $context->plan->dataset->dataset_type,
                    'sync_mode' => 'cursor_incremental',
                    'updated_at' => $this->updatedAt($row),
                ],
            ])
            ->values()
            ->all();
    }

    protected function updatedAt(array $row): string
    {
        return (string) (
            $row['updatedAt']
            ?? $row['updated_at']
            ?? $row['SystemModstamp']
            ?? $row['LastModifiedDate']
            ?? $row['update_time']
            ?? data_get($row, 'properties.updatedate')
            ?? ''
        );
    }

    protected function externalRecordId(ConnectorSyncContext $context, array $row): string
    {
        return $context->plan->provider.':'.hash('sha256', json_encode([
            'dataset' => $context->plan->dataset->dataset_key,
            'row' => $row,
        ], JSON_THROW_ON_ERROR));
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
            'limit' => $response->header('X-RateLimit-Limit') ?: $response->header('Sforce-Limit-Info'),
            'remaining' => $response->header('X-RateLimit-Remaining'),
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
