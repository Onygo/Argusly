<?php

namespace App\Services\DataConnectors;

use App\Models\Connectors\ConnectorRawRecord;
use App\Support\MarketingMetadataRedactor;
use Illuminate\Support\Arr;

class ConnectorRawRecordWriter
{
    /**
     * @param array<int, array<string, mixed>> $records
     */
    public function write(ConnectorSyncContext $context, array $records): int
    {
        $written = 0;

        foreach ($records as $record) {
            $this->writeOne($context, (array) $record);
            $written++;
        }

        return $written;
    }

    /**
     * @param array<string, mixed> $record
     */
    private function writeOne(ConnectorSyncContext $context, array $record): ConnectorRawRecord
    {
        $payload = (array) ($record['payload'] ?? $record['payload_json'] ?? Arr::except($record, [
            'record_type',
            'external_record_id',
            'period_start',
            'period_end',
            'observed_at',
            'metadata',
            'metadata_json',
            'fingerprint',
        ]));

        $recordType = trim((string) ($record['record_type'] ?? $record['type'] ?? $context->plan->dataset->dataset_type));
        $externalId = trim((string) ($record['external_record_id'] ?? $record['external_id'] ?? ''));
        $metadata = (array) ($record['metadata'] ?? $record['metadata_json'] ?? []);
        $fingerprint = (string) ($record['fingerprint'] ?? $this->fingerprintFor(
            context: $context,
            recordType: $recordType,
            externalId: $externalId,
            payload: $payload,
        ));

        return ConnectorRawRecord::query()->updateOrCreate(
            ['fingerprint' => $fingerprint],
            [
                'workspace_id' => $context->plan->workspace->id,
                'client_site_id' => $context->plan->clientSite?->id,
                'connector_provider_id' => $context->plan->account->connector_provider_id,
                'connector_account_id' => $context->plan->account->id,
                'connector_dataset_id' => $context->plan->dataset->id,
                'connector_sync_run_id' => $context->run->id,
                'provider_key' => $context->plan->provider,
                'dataset_key' => $context->plan->dataset->dataset_key,
                'record_type' => $recordType === '' ? 'raw' : $recordType,
                'external_record_id' => $externalId === '' ? null : $externalId,
                'period_start' => $record['period_start'] ?? null,
                'period_end' => $record['period_end'] ?? null,
                'observed_at' => $record['observed_at'] ?? now(),
                'payload_json' => MarketingMetadataRedactor::redact($payload),
                'metadata_json' => MarketingMetadataRedactor::redact($metadata),
            ],
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function fingerprintFor(
        ConnectorSyncContext $context,
        string $recordType,
        string $externalId,
        array $payload,
    ): string {
        return hash('sha256', json_encode([
            'workspace_id' => (string) $context->plan->workspace->id,
            'connector_account_id' => (string) $context->plan->account->id,
            'connector_dataset_id' => (string) $context->plan->dataset->id,
            'provider' => $context->plan->provider,
            'dataset' => $context->plan->dataset->dataset_key,
            'record_type' => $recordType,
            'external_record_id' => $externalId,
            'payload' => $externalId === '' ? $payload : null,
        ], JSON_THROW_ON_ERROR));
    }
}
