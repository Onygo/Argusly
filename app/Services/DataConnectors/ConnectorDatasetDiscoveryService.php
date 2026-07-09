<?php

namespace App\Services\DataConnectors;

use App\Models\Connectors\ConnectorAccount;
use App\Models\Connectors\ConnectorDataset;
use App\Models\Connectors\ConnectorHealthEvent;
use App\Models\Connectors\ConnectorSyncRun;
use App\Support\MarketingMetadataRedactor;
use Throwable;

class ConnectorDatasetDiscoveryService
{
    public function __construct(
        private readonly DataConnectorRegistry $registry,
        private readonly ConnectorDatasetFingerprint $fingerprint,
        private readonly ConnectorDatasetResolver $resolver,
        private readonly ConnectorSyncRunLogger $syncRuns,
        private readonly ConnectorHealthService $health,
    ) {
    }

    /**
     * @return array{sync_run: ConnectorSyncRun, datasets: list<ConnectorDataset>, created: int, updated: int, deactivated: int}
     */
    public function discover(ConnectorAccount $account, bool $markMissingInactive = true): array
    {
        $startedAt = now();
        $run = $this->syncRuns->start($account, null, ConnectorSyncRun::TYPE_DISCOVERY, [
            'metrics_json' => [
                'operation' => 'dataset_discovery',
            ],
        ]);

        try {
            $adapter = $this->registry->datasetDiscoveryAdapter($account->provider_key);
            $discovered = $adapter->discoverDatasets($account);

            $created = 0;
            $updated = 0;
            $seenKeys = [];
            $datasets = [];

            foreach ($discovered as $item) {
                $dataset = $this->upsertDataset($account, (array) $item, $startedAt);
                $dataset->wasRecentlyCreated ? $created++ : $updated++;

                $seenKeys[] = $dataset->dataset_key;
                $datasets[] = $dataset->fresh();
            }

            $deactivated = $markMissingInactive
                ? $this->deactivateMissingDatasets($account, $seenKeys, $startedAt)
                : 0;

            $this->syncRuns->succeed($run, [
                'operation' => 'dataset_discovery',
                'discovered' => count($discovered),
                'created' => $created,
                'updated' => $updated,
                'deactivated' => $deactivated,
            ]);

            $this->health->resolve($account, 'Dataset discovery completed.', [
                'discovered' => count($discovered),
                'created' => $created,
                'updated' => $updated,
                'deactivated' => $deactivated,
            ]);

            return [
                'sync_run' => $run->fresh(),
                'datasets' => $datasets,
                'created' => $created,
                'updated' => $updated,
                'deactivated' => $deactivated,
            ];
        } catch (Throwable $exception) {
            $this->syncRuns->fail($run, $exception->getMessage(), [
                'operation' => 'dataset_discovery',
                'exception' => $exception::class,
            ]);

            $this->health->record(
                account: $account,
                severity: ConnectorHealthEvent::SEVERITY_ERROR,
                eventType: 'dataset.discovery_failed',
                message: 'Dataset discovery failed.',
                context: [
                    'exception' => $exception::class,
                    'message' => $exception->getMessage(),
                ],
            );

            throw $exception;
        }
    }

    /**
     * @param array<string, mixed> $item
     */
    private function upsertDataset(ConnectorAccount $account, array $item, mixed $seenAt): ConnectorDataset
    {
        $existing = $this->resolver->resolve($account, $item);
        $datasetKey = $this->fingerprint->keyFor($account, $item);
        $capabilities = ConnectorDatasetCapability::normalizeMany((array) ($item['capabilities'] ?? $item['capabilities_json'] ?? []));

        $attributes = [
            'connector_account_id' => $account->id,
            'workspace_id' => $account->workspace_id,
            'client_site_id' => $item['client_site_id'] ?? $account->client_site_id,
            'provider_key' => $account->provider_key,
            'dataset_key' => $datasetKey,
            'dataset_type' => (string) ($item['dataset_type'] ?? $item['type'] ?? 'dataset'),
            'external_dataset_id' => $item['external_dataset_id'] ?? $item['external_id'] ?? $item['id'] ?? null,
            'display_name' => (string) ($item['display_name'] ?? $item['name'] ?? $datasetKey),
            'status' => ConnectorDataset::STATUS_ACTIVE,
            'sync_frequency' => $item['sync_frequency'] ?? data_get($item, 'sync.frequency') ?? $existing?->sync_frequency,
            'next_sync_at' => $item['next_sync_at'] ?? $existing?->next_sync_at,
            'last_seen_at' => $seenAt,
            'deactivated_at' => null,
            'capabilities_json' => $capabilities,
            'sync_config_json' => MarketingMetadataRedactor::redact((array) ($item['sync_config'] ?? $item['sync_config_json'] ?? [])),
            'config_json' => MarketingMetadataRedactor::redact((array) ($item['config'] ?? $item['config_json'] ?? [])),
            'metadata_json' => MarketingMetadataRedactor::redact((array) ($item['metadata'] ?? $item['metadata_json'] ?? [])),
        ];

        if ($existing instanceof ConnectorDataset) {
            $existing->forceFill($attributes)->save();

            return $existing;
        }

        return ConnectorDataset::query()->create(array_merge($attributes, [
            'discovered_at' => $seenAt,
        ]));
    }

    /**
     * @param list<string> $seenKeys
     */
    private function deactivateMissingDatasets(ConnectorAccount $account, array $seenKeys, mixed $seenAt): int
    {
        return ConnectorDataset::query()
            ->where('connector_account_id', $account->id)
            ->where('status', ConnectorDataset::STATUS_ACTIVE)
            ->when($seenKeys !== [], fn ($query) => $query->whereNotIn('dataset_key', $seenKeys))
            ->update([
                'status' => ConnectorDataset::STATUS_INACTIVE,
                'deactivated_at' => $seenAt,
                'updated_at' => $seenAt,
            ]);
    }
}
