<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class PublishLayerDiagnosticsCommand extends Command
{
    protected $signature = 'publishlayer:diagnostics';

    protected $description = 'Show effective Argusly server and connector configuration (safe fields only).';

    public function handle(): int
    {
        $webhookSecret = trim((string) config('publishlayer.webhooks.secret', ''));
        $connectorApiKey = trim((string) config('publishlayer_connector.api.api_key', config('publishlayer_connector.api_key', '')));

        $this->table(['setting', 'value'], [
            ['webhooks.secret', $webhookSecret !== '' ? 'set' : 'missing'],
            ['webhooks.connector_public_url', (string) config('publishlayer.webhooks.connector_public_url', '')],
            ['webhooks.queue', (string) config('publishlayer.webhooks.queue', 'deliveries')],
            ['images.enabled', (bool) config('publishlayer.images.enabled', true) ? 'true' : 'false'],
            ['images.disk', (string) config('publishlayer.images.disk', 'public')],
            ['connector.api.base_url', (string) config('publishlayer_connector.api.base_url', config('publishlayer_connector.base_url', ''))],
            ['connector.api.workspace_id', (string) config('publishlayer_connector.api.workspace_id', config('publishlayer_connector.workspace_id', ''))],
            ['connector.api.api_key', $connectorApiKey !== '' ? 'set' : 'missing'],
        ]);

        return self::SUCCESS;
    }
}

