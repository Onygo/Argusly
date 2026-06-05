<?php

namespace App\Observers;

use App\Models\Content;
use App\Models\ContentVersion;
use App\Services\Content\ContentCacheInvalidationService;
use App\Services\PublicBlog\PublicBlogPerformanceDataService;
use App\Support\Markdown\MarkdownGenerationDispatcher;
use Illuminate\Support\Facades\DB;

class ContentVersionObserver
{
    public function saved(ContentVersion $version): void
    {
        if (! $version->wasRecentlyCreated && ! $version->wasChanged(['body', 'meta', 'source'])) {
            return;
        }

        $content = Content::query()
            ->whereKey((string) $version->content_id)
            ->first(['id', 'current_version_id', 'status', 'publish_status']);

        if (! $content || (string) $content->current_version_id !== (string) $version->id) {
            return;
        }

        MarkdownGenerationDispatcher::dispatch((string) $content->id);

        if ((string) $content->status !== 'published' || (string) ($content->publish_status ?? '') !== 'published') {
            return;
        }

        $dispatch = function () use ($content): void {
            $fresh = $content->fresh([
                'clientSite',
                'translationSourceContent',
                'localizedVariants',
                'currentVersion:id,content_id,body,meta',
                'featuredImage',
            ]);

            if (! $fresh instanceof Content) {
                return;
            }

            app(PublicBlogPerformanceDataService::class)->syncContent($fresh);
            app(ContentCacheInvalidationService::class)->invalidateContent($fresh, 'content_version.saved');
        };

        if (app()->runningUnitTests()) {
            $dispatch();

            return;
        }

        DB::afterCommit($dispatch);
    }
}
