<?php

namespace App\Observers;

use App\Models\Content;
use App\Models\ContentSeo;
use App\Services\Content\ContentCacheInvalidationService;
use App\Support\Markdown\MarkdownGenerationDispatcher;
use Illuminate\Support\Facades\DB;

class ContentSeoObserver
{
    public function saved(ContentSeo $seo): void
    {
        if (! $seo->wasRecentlyCreated && ! $seo->wasChanged([
            'meta_title',
            'meta_description',
            'primary_keyword',
            'secondary_keywords',
            'robots_index',
            'robots_follow',
            'schema_type',
        ])) {
            return;
        }

        if (! $seo->content_id) {
            return;
        }

        MarkdownGenerationDispatcher::dispatch((string) $seo->content_id);

        $content = Content::query()
            ->whereKey((string) $seo->content_id)
            ->first(['id', 'status', 'publish_status', 'type']);

        if (! $content instanceof Content) {
            return;
        }

        if ((string) $content->type !== 'article') {
            return;
        }

        if ((string) $content->status !== 'published' || (string) ($content->publish_status ?? '') !== 'published') {
            return;
        }

        $dispatch = function () use ($content): void {
            app(ContentCacheInvalidationService::class)->invalidateContent(
                $content->fresh(['clientSite', 'translationSourceContent', 'localizedVariants']),
                'content_seo.saved'
            );
        };

        if (app()->runningUnitTests()) {
            $dispatch();

            return;
        }

        DB::afterCommit($dispatch);
    }
}
