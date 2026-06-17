<?php

declare(strict_types=1);

namespace Onygo\ArguslyConnector\Console\Commands;

use Illuminate\Console\Command;
use Onygo\ArguslyConnector\ArguslyClient;
use Throwable;

final class HealthCheckCommand extends Command
{
    protected $signature = 'argusly:connector:health';

    protected $description = 'Run an Argusly connector health check.';

    public function handle(ArguslyClient $client): int
    {
        try {
            $response = $client->health([
                'framework_version' => app()->version(),
                'php_version' => PHP_VERSION,
            ]);
        } catch (Throwable $exception) {
            $this->error('Argusly connector health check failed: ' . $exception->getMessage());

            return self::FAILURE;
        }

        if (! $response->successful()) {
            $this->error(sprintf('Argusly connector health check returned HTTP %d.', $response->status()));

            return self::FAILURE;
        }

        $this->info('Argusly connector health check completed.');

        return self::SUCCESS;
    }
}
