<?php

namespace App\Services\DataConnectors;

use App\Models\Connectors\ConnectorAccount;
use App\Models\Connectors\ConnectorWebhookRegistration;

class ConnectorWebhookPreparationService
{
    public function __construct(private readonly DataConnectorRegistry $registry)
    {
    }

    public function prepare(ConnectorAccount $account): ConnectorWebhookRegistration
    {
        $definition = $this->registry->provider($account->provider_key);
        $supportsWebhooks = (bool) ($definition['supports_webhooks'] ?? false);
        $events = (array) data_get($definition, 'config_json.webhooks.events', []);

        return ConnectorWebhookRegistration::query()->updateOrCreate(
            [
                'connector_account_id' => $account->id,
                'provider_key' => $account->provider_key,
            ],
            [
                'workspace_id' => $account->workspace_id,
                'status' => $supportsWebhooks
                    ? ConnectorWebhookRegistration::STATUS_PREPARED
                    : ConnectorWebhookRegistration::STATUS_NOT_SUPPORTED,
                'event_types_json' => $events,
                'target_url' => rtrim((string) config('app.url'), '/').'/api/v1/connectors/webhooks/'.$account->provider_key,
                'metadata_json' => [
                    'registration_ready' => $supportsWebhooks,
                    'registered' => false,
                    'phase' => 28,
                ],
            ],
        );
    }
}
