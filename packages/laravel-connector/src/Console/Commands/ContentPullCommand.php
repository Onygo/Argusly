<?php

declare(strict_types=1);

namespace Onygo\ArguslyConnector\Console\Commands;

use Illuminate\Console\Command;
use Onygo\ArguslyConnector\ArguslyClient;
use Throwable;

final class ContentPullCommand extends Command
{
    protected $signature = 'argusly:connector:content:pull {--limit=25}';

    protected $description = 'Placeholder command for pulling content from Argusly.';

    public function handle(ArguslyClient $client): int
    {
        try {
            $response = $client->pullContent([
                'limit' => (int) $this->option('limit'),
            ]);
        } catch (Throwable $exception) {
            $this->error('Argusly content pull failed: ' . $exception->getMessage());

            return self::FAILURE;
        }

        $this->line('Argusly content pull placeholder executed.');
        $this->line('HTTP status: ' . $response->status());

        return $response->successful() ? self::SUCCESS : self::FAILURE;
    }
}
