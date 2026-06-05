<?php

namespace App\Jobs;

use App\Models\Content;
use App\Services\InternalLinking\InternalLinkingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class GenerateInternalLinksJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;
    public int $timeout = 180;
    public bool $failOnTimeout = true;

    public function __construct(
        public readonly string $contentId,
    ) {
        $this->onQueue('generation');
    }

    public function handle(InternalLinkingService $internalLinkingService): void
    {
        $content = Content::query()->find($this->contentId);
        if (! $content) {
            return;
        }

        $internalLinkingService->generateForContent($content);
    }
}
