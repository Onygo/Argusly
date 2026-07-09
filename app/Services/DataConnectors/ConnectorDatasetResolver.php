<?php

namespace App\Services\DataConnectors;

use App\Models\Connectors\ConnectorAccount;
use App\Models\Connectors\ConnectorDataset;

class ConnectorDatasetResolver
{
    public function __construct(
        private readonly ConnectorDatasetFingerprint $fingerprint,
    ) {
    }

    /**
     * @param array<string, mixed> $dataset
     */
    public function resolve(ConnectorAccount $account, array $dataset): ?ConnectorDataset
    {
        return ConnectorDataset::query()
            ->where('connector_account_id', $account->id)
            ->where('dataset_key', $this->fingerprint->keyFor($account, $dataset))
            ->first();
    }

    /**
     * @return array<string, mixed>
     */
    public function syncEligibility(ConnectorDataset $dataset, ?string $requiredCapability = null): array
    {
        $eligible = $dataset->isSyncEligible($requiredCapability);

        return [
            'eligible' => $eligible,
            'status' => $dataset->status,
            'required_capability' => $requiredCapability,
            'has_required_capability' => $requiredCapability === null || $dataset->hasCapability($requiredCapability),
            'next_sync_at' => $dataset->next_sync_at?->toIso8601String(),
        ];
    }
}
