<?php

declare(strict_types=1);

namespace Onygo\ArguslyConnector\Console\Commands;

use Illuminate\Console\Command;
use Onygo\ArguslyConnector\ArguslyClient;
use Throwable;

final class ContentSyncCommand extends Command
{
    protected $signature = 'argusly:connector:content:ack {content_id} {status=synced} {--remote-id=} {--remote-url=} {--idempotency-key=}';

    protected $description = 'Acknowledge a local content sync result to Argusly.';

    public function handle(ArguslyClient $client): int
    {
        try {
            $response = $client->acknowledgeContentSync((string) $this->argument('content_id'), array_filter([
                'status' => (string) $this->argument('status'),
                'remote_id' => $this->option('remote-id') ? (string) $this->option('remote-id') : null,
                'remote_url' => $this->option('remote-url') ? (string) $this->option('remote-url') : null,
            ], static fn ($value): bool => $value !== null && $value !== ''), $this->option('idempotency-key') ? (string) $this->option('idempotency-key') : null);
        } catch (Throwable $exception) {
            $this->error('Argusly content sync acknowledgement failed: ' . $exception->getMessage());

            return self::FAILURE;
        }

        if (! $response->successful()) {
            $this->error(sprintf('Argusly content sync acknowledgement returned HTTP %d.', $response->status()));

            return self::FAILURE;
        }

        $this->info('Argusly content sync acknowledgement accepted.');

        return self::SUCCESS;
    }
}
