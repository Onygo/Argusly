<?php

declare(strict_types=1);

namespace Onygo\ArguslyConnector\Console\Commands;

use Illuminate\Console\Command;
use Onygo\ArguslyConnector\ArguslyClient;
use Throwable;

final class ContentPullCommand extends Command
{
    protected $signature = 'argusly:connector:content:pull {--limit=25}';

    protected $description = 'Fetch content available to this connector from Argusly.';

    public function handle(ArguslyClient $client): int
    {
        try {
            $response = $client->contentIndex([
                'limit' => (int) $this->option('limit'),
            ]);
        } catch (Throwable $exception) {
            $this->error('Argusly content pull failed: ' . $exception->getMessage());

            return self::FAILURE;
        }

        if (! $response->successful()) {
            $this->error(sprintf('Argusly content pull returned HTTP %d.', $response->status()));

            return self::FAILURE;
        }

        $items = $response->json('data');
        $this->info(sprintf('Fetched %d Argusly content item(s).', is_array($items) ? count($items) : 0));

        return self::SUCCESS;
    }
}
