<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class ArguslyDiagnosticsCommand extends Command
{
    protected $signature = 'argusly:diagnostics';

    protected $description = 'Show effective Argusly server and connector configuration (safe fields only).';

    public function handle(): int
    {
        $webhookSecret = trim((string) config('argusly.webhooks.secret', ''));
        $connectorApiKey = trim((string) config('argusly_connector.api.api_key', config('argusly_connector.api_key', '')));

        $this->table(['setting', 'value'], [
            ['webhooks.secret', $webhookSecret !== '' ? 'set' : 'missing'],
            ['webhooks.connector_public_url', (string) config('argusly.webhooks.connector_public_url', '')],
            ['webhooks.queue', (string) config('argusly.webhooks.queue', 'deliveries')],
            ['images.enabled', (bool) config('argusly.images.enabled', true) ? 'true' : 'false'],
            ['images.disk', (string) config('argusly.images.disk', 'content_images')],
            ['connector.api.base_url', (string) config('argusly_connector.api.base_url', config('argusly_connector.base_url', ''))],
            ['connector.api.workspace_id', (string) config('argusly_connector.api.workspace_id', config('argusly_connector.workspace_id', ''))],
            ['connector.api.api_key', $connectorApiKey !== '' ? 'set' : 'missing'],
        ]);

        return self::SUCCESS;
    }
}
