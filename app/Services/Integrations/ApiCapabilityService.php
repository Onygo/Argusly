<?php

namespace App\Services\Integrations;

use App\Models\Workspace;
use App\Services\Entitlements\FeatureGate;
use RuntimeException;

class ApiCapabilityService
{
    public function __construct(private readonly FeatureGate $featureGate) {}

    public function assertApiOnlyEnabled(Workspace $workspace): void
    {
        if (! $this->featureGate->can($workspace, 'api_only_enabled')) {
            throw new RuntimeException('API-only access is not enabled for this workspace plan.');
        }
    }

    public function assertCanCreateDestination(Workspace $workspace): void
    {
        $limit = $this->destinationLimit($workspace);
        if ($limit < 0) {
            return;
        }

        $count = (int) $workspace->contentDestinations()->whereNull('deleted_at')->count();
        if ($count >= $limit) {
            throw new RuntimeException(sprintf('Destination limit reached (%d).', $limit));
        }
    }

    public function assertCanCreateApiKey(Workspace $workspace): void
    {
        $limit = $this->apiKeyLimit($workspace);
        if ($limit < 0) {
            return;
        }

        $count = (int) $workspace->apiKeys()
            ->whereNull('revoked_at')
            ->where(function ($query): void {
                $query->whereNull('is_legacy_import')
                    ->orWhere('is_legacy_import', false);
            })
            ->count();
        if ($count >= $limit) {
            throw new RuntimeException(sprintf('API key limit reached (%d).', $limit));
        }
    }

    public function assertWebhooksEnabled(Workspace $workspace): void
    {
        if (! $this->featureGate->can($workspace, 'api_webhooks_enabled')) {
            throw new RuntimeException('Webhooks are not enabled for this workspace plan.');
        }
    }

    public function assertAnalyticsIngestEnabled(Workspace $workspace): void
    {
        if (! $this->featureGate->can($workspace, 'api_analytics_ingest_enabled')) {
            throw new RuntimeException('Analytics ingest is not enabled for this workspace plan.');
        }
    }

    public function apiRateLimitPerMinute(Workspace $workspace): int
    {
        $value = $this->featureGate->value($workspace, 'api_rate_limit_per_minute', 120);

        return max(10, (int) (is_numeric($value) ? $value : 120));
    }

    public function apiKeyLimit(Workspace $workspace): int
    {
        $value = $this->featureGate->value($workspace, 'api_max_keys', 3);

        return is_numeric($value) ? (int) $value : 3;
    }

    public function destinationLimit(Workspace $workspace): int
    {
        $value = $this->featureGate->value($workspace, 'api_max_destinations', 3);

        return is_numeric($value) ? (int) $value : 3;
    }
}
