<?php

namespace App\Observers;

use App\Models\Content;
use App\Models\ContentImage;
use App\Services\Content\ContentCacheInvalidationService;
use App\Services\PublicBlog\PublicBlogPerformanceDataService;
use Illuminate\Support\Facades\DB;

class ContentImageObserver
{
    public function saved(ContentImage $image): void
    {
        if (! $this->shouldSync($image)) {
            return;
        }

        $this->dispatchSync($image);
    }

    public function deleted(ContentImage $image): void
    {
        if (! $this->shouldSync($image)) {
            return;
        }

        $this->dispatchSync($image);
    }

    private function shouldSync(ContentImage $image): bool
    {
        return (string) $image->type === 'featured';
    }

    private function dispatchSync(ContentImage $image): void
    {
        $dispatch = function () use ($image): void {
            $content = Content::query()
                ->with([
                    'currentVersion:id,content_id,body,meta',
                    'featuredImage',
                    'clientSite',
                    'translationSourceContent',
                    'localizedVariants',
                ])
                ->find((string) $image->content_id);

            if (! $content) {
                return;
            }

            app(PublicBlogPerformanceDataService::class)->syncContent($content);

            if ((string) $content->status === 'published' && (string) ($content->publish_status ?? '') === 'published') {
                app(ContentCacheInvalidationService::class)->invalidateContent($content, 'content_image.saved');
            }
        };

        if (app()->runningUnitTests()) {
            $dispatch();

            return;
        }

        DB::afterCommit($dispatch);
    }
}
