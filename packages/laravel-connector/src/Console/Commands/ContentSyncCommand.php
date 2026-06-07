<?php

declare(strict_types=1);

namespace Onygo\ArguslyConnector\Console\Commands;

use Illuminate\Console\Command;
use Onygo\ArguslyConnector\ArguslyClient;
use Throwable;

final class ContentSyncCommand extends Command
{
    protected $signature = 'argusly:connector:content:sync {content_id} {--idempotency-key=}';

    protected $description = 'Placeholder command for syncing local content state with Argusly.';

    public function handle(ArguslyClient $client): int
    {
        try {
            $response = $client->syncContent((string) $this->argument('content_id'), [
                'status' => 'placeholder',
            ], $this->option('idempotency-key') ? (string) $this->option('idempotency-key') : null);
        } catch (Throwable $exception) {
            $this->error('Argusly content sync failed: ' . $exception->getMessage());

            return self::FAILURE;
        }

        $this->line('Argusly content sync placeholder executed.');
        $this->line('HTTP status: ' . $response->status());

        return $response->successful() ? self::SUCCESS : self::FAILURE;
    }
}
