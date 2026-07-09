<?php

namespace App\Services\DataConnectors;

use App\Models\ClientSite;
use App\Models\Connectors\ConnectorAccount;
use App\Models\Connectors\ConnectorDataset;
use App\Models\Connectors\ConnectorSyncRun;
use App\Models\Workspace;
use DateTimeInterface;

class ConnectorSyncPlan
{
    /**
     * @param list<string> $metrics
     * @param list<string> $dimensions
     * @param array<string, mixed> $filters
     * @param array<string, mixed> $capabilities
     * @param array<string, mixed> $retryPolicy
     */
    public function __construct(
        public readonly Workspace $workspace,
        public readonly ?ClientSite $clientSite,
        public readonly string $provider,
        public readonly ConnectorAccount $account,
        public readonly ConnectorDataset $dataset,
        public readonly string $priority = 'normal',
        public readonly bool $incremental = true,
        public readonly bool $backfill = false,
        public readonly ?DateTimeInterface $dateRangeStart = null,
        public readonly ?DateTimeInterface $dateRangeEnd = null,
        public readonly array $metrics = [],
        public readonly array $dimensions = [],
        public readonly array $filters = [],
        public readonly int $pageSize = 500,
        public readonly array $capabilities = [],
        public readonly bool $forceRefresh = false,
        public readonly ?ConnectorSyncCursor $checkpoint = null,
        public readonly array $retryPolicy = [],
        public readonly string $runType = ConnectorSyncRun::TYPE_SCHEDULED,
    ) {
    }

    public static function forDataset(
        ConnectorDataset $dataset,
        string $runType = ConnectorSyncRun::TYPE_SCHEDULED,
        bool $incremental = true,
        bool $backfill = false,
    ): self {
        $dataset->loadMissing(['account', 'workspace', 'clientSite']);

        return new self(
            workspace: $dataset->workspace,
            clientSite: $dataset->clientSite,
            provider: (string) $dataset->provider_key,
            account: $dataset->account,
            dataset: $dataset,
            incremental: $incremental,
            backfill: $backfill,
            checkpoint: ConnectorSyncCursor::from($dataset->cursor_json),
            runType: $runType,
        );
    }

    public function checkpoint(): ConnectorSyncCursor
    {
        if ($this->forceRefresh) {
            return new ConnectorSyncCursor;
        }

        return $this->checkpoint ?? ConnectorSyncCursor::from($this->dataset->cursor_json);
    }

    /**
     * @return array<string, mixed>
     */
    public function retryPolicy(): array
    {
        return array_merge([
            'max_attempts' => 3,
            'backoff_seconds' => 300,
        ], $this->retryPolicy);
    }
}
