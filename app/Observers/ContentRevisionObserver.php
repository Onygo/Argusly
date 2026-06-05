<?php

namespace App\Observers;

use App\Models\Content;
use App\Models\ContentRevision;
use App\Support\Markdown\MarkdownGenerationDispatcher;

class ContentRevisionObserver
{
    public function saved(ContentRevision $revision): void
    {
        if (! $revision->wasRecentlyCreated && ! $revision->wasChanged(['content_html', 'meta', 'is_active'])) {
            return;
        }

        $content = Content::query()
            ->whereKey((string) $revision->content_id)
            ->first(['id', 'current_revision_id']);

        if (! $content || (string) $content->current_revision_id !== (string) $revision->id) {
            return;
        }

        MarkdownGenerationDispatcher::dispatch((string) $content->id);
    }
}
