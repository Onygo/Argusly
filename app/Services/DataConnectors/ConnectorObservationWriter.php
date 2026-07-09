<?php

namespace App\Services\DataConnectors;

use App\Models\MarketingMetricDefinition;
use App\Models\MarketingObservation;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class ConnectorObservationWriter
{
    /**
     * @param array<int, array<string, mixed>> $records
     */
    public function write(ConnectorSyncContext $context, array $records): ConnectorObservationWriteResult
    {
        $observations = collect();

        foreach ($records as $record) {
            $observations->push($this->writeOne($context, $record));
        }

        return new ConnectorObservationWriteResult($observations->count(), $observations);
    }

    /**
     * @param array<string, mixed> $record
     */
    private function writeOne(ConnectorSyncContext $context, array $record): MarketingObservation
    {
        $attributes = (array) ($record['attributes'] ?? $record);

        foreach (['metric_key', 'metric_value', 'period_start', 'period_end', 'granularity'] as $required) {
            if (! array_key_exists($required, $attributes)) {
                throw new InvalidArgumentException("Connector observation is missing [{$required}].");
            }
        }

        $metricKey = (string) $attributes['metric_key'];
        $metric = MarketingMetricDefinition::query()->where('metric_key', $metricKey)->first();
        $dimensions = (array) ($record['dimensions'] ?? $attributes['dimensions'] ?? []);
        $attributions = (array) ($record['attributions'] ?? $attributes['attributions'] ?? []);

        unset($attributes['dimensions'], $attributes['attributions']);

        $attributes = array_merge($attributes, [
            'workspace_id' => $context->plan->workspace->id,
            'client_site_id' => $context->plan->clientSite?->id,
            'connector_provider_id' => $context->plan->account->connector_provider_id,
            'connector_account_id' => $context->plan->account->id,
            'connector_dataset_id' => $context->plan->dataset->id,
            'connector_sync_run_id' => $context->run->id,
            'marketing_metric_definition_id' => $attributes['marketing_metric_definition_id'] ?? $metric?->id,
            'source_metadata_json' => $attributes['source_metadata_json']
                ?? $attributes['source_metadata']
                ?? [],
            'quality_metadata_json' => $attributes['quality_metadata_json']
                ?? $attributes['quality_metadata']
                ?? [],
            'raw_metadata_json' => $attributes['raw_metadata_json']
                ?? $attributes['raw_metadata']
                ?? [],
        ]);

        unset($attributes['source_metadata'], $attributes['quality_metadata'], $attributes['raw_metadata']);
        $attributes['fingerprint'] ??= $this->fingerprintFor($attributes, $dimensions);

        return MarketingObservation::upsertByFingerprint($attributes, $dimensions, $attributions);
    }

    /**
     * @param array<string, mixed> $attributes
     * @param array<mixed> $dimensions
     */
    private function fingerprintFor(array $attributes, array $dimensions): string
    {
        $dimensionPayload = collect($dimensions)
            ->map(function (mixed $dimension, mixed $key): array {
                $payload = is_array($dimension)
                    ? $dimension
                    : ['dimension_key' => $key, 'dimension_value' => $dimension];

                $dimensionKey = (string) ($payload['dimension_key'] ?? $payload['key'] ?? $key);
                $dimensionValue = mb_strtolower(trim((string) ($payload['dimension_value'] ?? $payload['value'] ?? '')));

                return [
                    'key' => $dimensionKey,
                    'value' => $dimensionValue,
                ];
            })
            ->sortBy(fn (array $dimension): string => $dimension['key'].'='.$dimension['value'])
            ->values()
            ->all();

        return hash('sha256', json_encode([
            'workspace_id' => (string) ($attributes['workspace_id'] ?? ''),
            'connector_provider_id' => (string) ($attributes['connector_provider_id'] ?? ''),
            'connector_account_id' => (string) ($attributes['connector_account_id'] ?? ''),
            'connector_dataset_id' => (string) ($attributes['connector_dataset_id'] ?? ''),
            'metric_key' => (string) ($attributes['metric_key'] ?? ''),
            'unit' => (string) ($attributes['unit'] ?? ''),
            'period_start' => (string) ($attributes['period_start'] ?? ''),
            'period_end' => (string) ($attributes['period_end'] ?? ''),
            'granularity' => (string) ($attributes['granularity'] ?? ''),
            'external_id' => (string) ($attributes['external_id'] ?? ''),
            'dimensions' => $dimensionPayload,
        ], JSON_THROW_ON_ERROR));
    }
}
