<?php

namespace App\Listeners\Marketing;

use App\Events\Agents\ContentPublished;
use App\Models\Content;
use App\Models\MarketingBlogRedirect;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class InvalidateCrossLocaleRedirectsOnPublish
{
    public function handle(ContentPublished $event): void
    {
        $content = Content::query()
            ->with(['translationSourceContent'])
            ->find($event->contentId);

        if (! $content) {
            return;
        }

        $locale = $content->localeCode();
        $source = $content->localizationSource();
        $sourceId = (string) $source->id;

        // Find any cross-locale redirects targeting this family where source_locale matches this content's locale
        $invalidatedCount = 0;

        MarketingBlogRedirect::query()
            ->where('target_content_id', $sourceId)
            ->where('redirect_kind', 'legacy_locale_mismatch')
            ->where('source_locale', $locale)
            ->where('target_locale', '!=', $locale)
            ->where('is_active', true)
            ->each(function (MarketingBlogRedirect $redirect) use ($content, &$invalidatedCount): void {
                $meta = is_array($redirect->meta) ? $redirect->meta : [];
                $meta['superseded_reason'] = 'published_locale_translation';
                $meta['superseded_at'] = now()->toIso8601String();
                $meta['superseded_by_content_id'] = (string) $content->id;

                $redirect->forceFill([
                    'is_active' => false,
                    'meta' => $meta,
                ])->save();

                $invalidatedCount++;
            });

        if ($invalidatedCount > 0) {
            Log::info('marketing.cross_locale_redirects_invalidated', [
                'content_id' => (string) $content->id,
                'source_id' => $sourceId,
                'locale' => $locale,
                'invalidated_count' => $invalidatedCount,
                'trigger' => 'content_published_event',
            ]);
        }

        // Invalidate the cache for redirect locale checks
        Cache::forget(sprintf('redirect_locale_check.%s.%s', $sourceId, $locale));
    }
}
