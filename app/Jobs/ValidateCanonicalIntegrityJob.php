<?php

namespace App\Jobs;

use App\Models\Content;
use App\Services\Seo\ContentIndexationHealthService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ValidateCanonicalIntegrityJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public ?string $contentId = null,
    ) {
    }

    public function handle(ContentIndexationHealthService $health): void
    {
        Content::query()
            ->where('type', 'article')
            ->when($this->contentId, fn ($query) => $query->where('id', $this->contentId))
            ->with(['localizedVariants.currentVersion', 'localizedVariants.publications', 'publications', 'currentVersion'])
            ->chunk(100, fn ($contents) => $contents->each(fn (Content $content) => $health->persist($content)));
    }
}
