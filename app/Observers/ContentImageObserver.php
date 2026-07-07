<?php

namespace App\Observers;

use App\Models\Content;
use App\Models\ContentImage;
use App\Services\Content\ContentCacheInvalidationService;
use App\Services\Markdown\MarkdownArtifactService;
use App\Services\PublicBlog\PublicBlogPerformanceDataService;
use Illuminate\Support\Facades\DB;

class ContentImageObserver
{
    public function saved(ContentImage $image): void
    {
        $this->markVisualArtifactsStale($image);

        if (! $this->shouldSync($image)) {
            return;
        }

        $this->dispatchSync($image);
    }

    public function deleted(ContentImage $image): void
    {
        $this->markVisualArtifactsStale($image);

        if (! $this->shouldSync($image)) {
            return;
        }

        $this->dispatchSync($image);
    }

    private function shouldSync(ContentImage $image): bool
    {
        if ((string) $image->type === 'featured' || (string) $image->getOriginal('type') === 'featured') {
            return true;
        }

        if ((bool) $image->display_on_website
            || (bool) $image->getOriginal('display_on_website')
            || (bool) $image->display_as_featured_image
            || (bool) $image->getOriginal('display_as_featured_image')) {
            return true;
        }

        return $image->wasChanged([
            'is_active',
            'display_on_website',
            'display_as_featured_image',
            'image_path',
            'image_url',
            'medium_path',
            'medium_webp_path',
        ]);
    }

    private function markVisualArtifactsStale(ContentImage $image): void
    {
        if (! in_array((string) $image->type, ['inline', 'diagram', 'chart'], true)) {
            return;
        }

        $dispatch = function () use ($image): void {
            $content = Content::query()
                ->with(['workspace', 'renderArtifacts', 'publications'])
                ->find((string) $image->content_id);

            if ($content) {
                app(MarkdownArtifactService::class)->markStaleForContent($content);
            }
        };

        if (app()->runningUnitTests()) {
            $dispatch();

            return;
        }

        DB::afterCommit($dispatch);
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
