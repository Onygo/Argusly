<?php

namespace App\Jobs;

use App\Models\Content;
use App\Services\Seo\ContentIndexationHealthService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ValidateSitemapEntriesJob implements ShouldQueue
{
    use Queueable;

    public function handle(ContentIndexationHealthService $health): void
    {
        Content::query()
            ->where('type', 'article')
            ->with(['localizedVariants.currentVersion', 'localizedVariants.publications', 'publications', 'currentVersion'])
            ->chunk(100, fn ($contents) => $contents->each(fn (Content $content) => $health->persist($content)));
    }
}
