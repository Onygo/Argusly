<?php

namespace App\Jobs;

use App\Models\Content;
use App\Services\Marketing\MarketingBlogTranslationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class GenerateMarketingBlogTranslationJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 300;

    public function __construct(
        public string $sourceContentId,
        public bool $publish = false,
        public bool $refreshExisting = false,
    ) {
        $this->queue = config('translation.queue.name', 'default');
        $this->connection = config('translation.queue.connection');
    }

    public function uniqueId(): string
    {
        return sprintf('marketing-blog-translation:%s:en', $this->sourceContentId);
    }

    public function handle(MarketingBlogTranslationService $translations): void
    {
        $source = Content::query()->with('currentVersion')->findOrFail($this->sourceContentId);

        $translations->generateEnglishVariant(
            source: $source,
            publish: $this->publish,
            refreshExisting: $this->refreshExisting,
        );
    }
}
