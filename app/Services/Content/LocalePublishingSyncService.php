<?php

namespace App\Services\Content;

use App\Models\ClientSite;
use App\Models\Content;
use App\Models\ContentDestination;
use App\Models\Draft;
use App\Services\Integrations\LaravelConnectorDestinationResolver;
use App\Services\Integrations\LaravelConnectorPublishingService;
use App\Services\Publication\ContentPublicationService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class LocalePublishingSyncService
{
    public function __construct(
        private readonly ContentLocalizationService $localizations,
        private readonly ContentPublicationService $publicationService,
        private readonly LaravelConnectorDestinationResolver $laravelDestinationResolver,
        private readonly LaravelConnectorPublishingService $laravelPublishingService,
    ) {}

    public function syncSourceSchedule(Content $content, ?Carbon $scheduledAt): void
    {
        $source = $this->resolveSource($content);
        if (! $source) {
            return;
        }

        foreach ($this->eligibleTranslations($source) as $translation) {
            if ($this->translationIsPublishedOrPublishing($translation)) {
                continue;
            }

            if (! $this->translationHasReadyDraft($translation)) {
                $this->clearPendingSchedule($translation);

                continue;
            }

            if (! $scheduledAt) {
                $this->clearPendingSchedule($translation);

                continue;
            }

            $translation->forceFill([
                'scheduled_publish_at' => $scheduledAt,
                'publish_status' => 'scheduled',
                'publish_error' => null,
            ])->saveQuietly();
        }
    }

    public function syncSourceImmediatePublish(Content $content): void
    {
        $source = $this->resolveSource($content);
        if (! $source) {
            return;
        }

        foreach ($this->eligibleTranslations($source) as $translation) {
            if (! $this->translationHasReadyDraft($translation)) {
                $this->clearPendingSchedule($translation);

                continue;
            }

            $this->publishTranslationNow($translation, 'content.locale_sync.source_publish');
        }
    }

    public function syncReadyTranslation(Content $content): void
    {
        $content->loadMissing('translationSourceContent.clientSite', 'clientSite', 'contentDestination');

        if (! $content->isTranslationVariant() || ! $this->translationSyncEnabled($content)) {
            return;
        }

        $source = $content->translationSourceContent;
        if (! $source instanceof Content || ! $this->sourceAutoPublishEnabled($source)) {
            return;
        }

        if (! $this->translationHasReadyDraft($content)) {
            return;
        }

        if ($source->scheduled_publish_at instanceof Carbon && $source->scheduled_publish_at->isFuture()) {
            $content->forceFill([
                'scheduled_publish_at' => $source->scheduled_publish_at->copy(),
                'publish_status' => 'scheduled',
                'publish_error' => null,
            ])->saveQuietly();

            return;
        }

        if ($this->sourceIsAlreadyLive($source)) {
            $this->publishTranslationNow($content, 'content.locale_sync.translation_ready');
        }
    }

    private function resolveSource(Content $content): ?Content
    {
        $source = $this->localizations->source($content);
        $source->loadMissing('clientSite', 'contentDestination');

        if ($source->isTranslationVariant() || ! (bool) $source->is_source_locale || ! $this->sourceAutoPublishEnabled($source)) {
            return null;
        }

        return $source;
    }

    /**
     * @return Collection<int,Content>
     */
    private function eligibleTranslations(Content $source): Collection
    {
        return $this->localizations->family($source)
            ->filter(fn (Content $variant): bool => (string) $variant->id !== (string) $source->id)
            ->filter(fn (Content $variant): bool => $this->translationSyncEnabled($variant))
            ->values();
    }

    private function sourceAutoPublishEnabled(Content $content): bool
    {
        return (bool) ($content->auto_publish ?? true);
    }

    private function translationSyncEnabled(Content $content): bool
    {
        return (bool) ($content->sync_with_source ?? true)
            && (bool) ($content->auto_publish ?? true);
    }

    private function clearPendingSchedule(Content $translation): void
    {
        if ($this->translationIsPublishedOrPublishing($translation)) {
            return;
        }

        $translation->forceFill([
            'scheduled_publish_at' => null,
            'publish_status' => 'draft',
            'publish_error' => null,
        ])->saveQuietly();
    }

    private function publishTranslationNow(Content $translation, string $source): void
    {
        if ($this->translationIsPublishedOrPublishing($translation)) {
            return;
        }

        $translation->loadMissing('clientSite', 'contentDestination');
        $draft = $this->latestReadyDraft($translation);
        if (! $draft instanceof Draft) {
            return;
        }

        $siteType = ClientSite::normalizeType((string) ($translation->clientSite?->type ?? ''));

        if ($siteType === ClientSite::TYPE_WORDPRESS) {
            $translation->forceFill([
                'scheduled_publish_at' => null,
                'publish_error' => null,
            ])->saveQuietly();

            $this->publicationService->dispatchWordPressPublication($translation, $draft, [
                'source' => $source,
                'allow_stale_reclaim' => true,
            ]);

            return;
        }

        if ($siteType !== ClientSite::TYPE_LARAVEL) {
            return;
        }

        /** @var ContentDestination|null $destination */
        $destination = $this->laravelDestinationResolver->resolveForContent($translation);

        if ($destination) {
            $this->publicationService->dispatchLaravelPublication($translation, $draft, [
                'source' => $source,
                'allow_stale_reclaim' => true,
                'allow_outdated_republish' => true,
            ]);

            return;
        }

        try {
            $this->laravelPublishingService->publish($translation, $draft, 'linked_locale_auto_publish', $source);
        } catch (\Throwable $exception) {
            Log::error('content.locale_sync.publish_failed', [
                'content_id' => (string) $translation->id,
                'locale' => $translation->localeCode(),
                'source' => $source,
                'exception_class' => $exception::class,
                'exception_message' => $exception->getMessage(),
            ]);
        }
    }

    private function translationHasReadyDraft(Content $translation): bool
    {
        return $this->latestReadyDraft($translation) instanceof Draft;
    }

    private function latestReadyDraft(Content $translation): ?Draft
    {
        return Draft::query()
            ->where('content_id', (string) $translation->id)
            ->whereNotNull('content_html')
            ->where('content_html', '!=', '')
            ->whereIn('status', ['ready', 'ready_to_deliver', 'generated', 'delivered', 'published'])
            ->latest('created_at')
            ->first();
    }

    private function translationIsPublishedOrPublishing(Content $translation): bool
    {
        return (string) ($translation->publish_status ?? '') === 'published'
            || (string) $translation->status === 'published'
            || (string) ($translation->publish_status ?? '') === 'publishing';
    }

    private function sourceIsAlreadyLive(Content $source): bool
    {
        return (string) ($source->publish_status ?? '') === 'published'
            || (string) $source->status === 'published'
            || (
                $source->scheduled_publish_at instanceof Carbon
                && $source->scheduled_publish_at->lessThanOrEqualTo(now())
            );
    }
}
