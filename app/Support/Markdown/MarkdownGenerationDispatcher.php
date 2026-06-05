<?php

namespace App\Support\Markdown;

use App\Jobs\GenerateContentMarkdownJob;

class MarkdownGenerationDispatcher
{
    public static function dispatch(string $contentId, ?string $locale = null, bool $force = false): void
    {
        GenerateContentMarkdownJob::dispatch($contentId, $locale, $force)
            ->afterCommit()
            ->onQueue('markdown');
    }
}
