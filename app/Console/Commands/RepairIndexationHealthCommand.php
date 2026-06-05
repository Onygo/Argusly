<?php

namespace App\Console\Commands;

use App\Models\Content;
use App\Services\Seo\ContentIndexationHealthService;
use Illuminate\Console\Command;

class RepairIndexationHealthCommand extends Command
{
    protected $signature = 'seo:repair-indexation-health {--content-id=}';

    protected $description = 'Recalculate and persist indexation health for public content.';

    public function handle(ContentIndexationHealthService $health): int
    {
        $contents = Content::query()
            ->where('type', 'article')
            ->when($this->option('content-id'), fn ($query, $id) => $query->where('id', (string) $id))
            ->with(['localizedVariants.currentVersion', 'localizedVariants.publications', 'publications', 'currentVersion'])
            ->get();

        foreach ($contents as $content) {
            $health->persist($content);
        }

        $this->info(sprintf('Rebuilt indexation health for %d content item(s).', $contents->count()));

        return self::SUCCESS;
    }
}
