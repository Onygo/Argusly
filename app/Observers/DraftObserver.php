<?php

namespace App\Observers;

use App\Enums\SupportedLanguage;
use App\Events\Onboarding\DraftGenerated;
use App\Events\LinkIntelligence\ArticleSignalsRequested;
use App\Jobs\AnalyzeDraftJob;
use App\Models\Draft;
use RuntimeException;

class DraftObserver
{
    public function saving(Draft $draft): void
    {
        $resolvedLocale = SupportedLanguage::normalizeLocale(
            ($draft->language instanceof SupportedLanguage ? $draft->language->value : (string) $draft->language)
            ?: $draft->getRawOriginal('language')
        );

        if ($resolvedLocale === null) {
            $draft->loadMissing('brief', 'content', 'clientSite.workspace');

            $resolvedLocale = SupportedLanguage::normalizeLocale(
                (string) ($draft->brief?->language
                    ?: $draft->content?->localeCode()
                    ?: $draft->clientSite?->workspace?->defaultContentLanguageCode()
                    ?: '')
            );
        }

        if ($resolvedLocale === null) {
            throw new RuntimeException('Locale is required');
        }

        $draft->language = $resolvedLocale;

        $meta = is_array($draft->meta) ? $draft->meta : [];
        $meta['language'] = $resolvedLocale;
        $draft->meta = $meta;
    }

    public function saved(Draft $draft): void
    {
        if ($this->isDraftGeneratedState($draft)) {
            DraftGenerated::dispatch((string) $draft->id);
        }

        if (! $draft->content_html) {
            return;
        }

        if (
            $draft->wasRecentlyCreated ||
            $draft->wasChanged('content_html') ||
            $draft->wasChanged('title') ||
            $draft->wasChanged('status') ||
            $draft->wasChanged('seo_title') ||
            $draft->wasChanged('seo_meta_description') ||
            $draft->wasChanged('seo_h1')
        ) {
            ArticleSignalsRequested::dispatch((string) $draft->id);
            AnalyzeDraftJob::dispatch((string) $draft->id)
                ->onQueue((string) config('draft_intelligence.queue', 'ai-low'))
                ->afterCommit();
        }
    }

    private function isDraftGeneratedState(Draft $draft): bool
    {
        $generatedStates = ['ready', 'ready_to_deliver', 'delivered', 'published'];
        $status = (string) $draft->status;

        if ($draft->wasRecentlyCreated && in_array($status, $generatedStates, true)) {
            return true;
        }

        return $draft->wasChanged('status') && in_array($status, $generatedStates, true);
    }
}
