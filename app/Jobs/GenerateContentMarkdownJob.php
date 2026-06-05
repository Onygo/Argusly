<?php

namespace App\Jobs;

use App\Services\Markdown\MarkdownArtifactService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class GenerateContentMarkdownJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 120;
    public int $uniqueFor = 300;

    public function __construct(
        public string $contentId,
        public ?string $locale = null,
        public bool $force = false
    ) {}

    public function uniqueId(): string
    {
        return implode(':', [
            $this->contentId,
            $this->locale ?: 'auto',
            $this->force ? 'force' : 'default',
        ]);
    }

    public function handle(MarkdownArtifactService $artifacts): void
    {
        $artifacts->rebuildForContentId($this->contentId, $this->locale, $this->force);
    }
}
