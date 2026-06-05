<?php

namespace App\Observers;

use App\Models\Content;
use App\Models\ContentPublication;
use App\Services\Content\ContentCacheInvalidationService;
use App\Services\ContentAutomation\AutomationRunItemStateService;
use App\Services\Performance\PerformanceCacheService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ContentPublicationObserver
{
    public function saved(ContentPublication $publication): void
    {
        if ($publication->wasRecentlyCreated || $publication->wasChanged([
            'content_id',
            'destination_id',
            'client_site_id',
            'locale',
            'provider',
            'remote_id',
            'remote_url',
            'remote_status',
            'delivery_status',
            'last_delivered_at',
            'last_error_at',
            'last_error_code',
            'last_error_message',
            'updated_at',
        ])) {
            app(PerformanceCacheService::class)->bustForPublication(
                $publication->loadMissing('content.workspace:id,organization_id', 'clientSite:id,workspace_id')
            );
        }

        $this->logDuplicateActivePublication($publication);
        $this->invalidatePublicCacheWhenNeeded($publication);

        if (! $publication->wasChanged('delivery_status')) {
            return;
        }

        if ($publication->delivery_status !== ContentPublication::STATUS_DELIVERED) {
            return;
        }

        $contentId = $publication->content_id;
        if (! $contentId) {
            return;
        }

        try {
            $content = Content::query()->find($contentId);
            if (! $content) {
                return;
            }

            if (filled($content->automation_run_id) && filled($content->automation_id)) {
                app(AutomationRunItemStateService::class)->syncFromContent(
                    $content->fresh(['drafts', 'publications']) ?? $content
                );
            }

            if ($content->first_published_at !== null) {
                return;
            }

            $deliveredAt = $publication->last_delivered_at ?? now();
            $content->update(['first_published_at' => $deliveredAt]);
        } catch (\Throwable $exception) {
            Log::error('publication.observer.first_published_sync_failed', [
                'publication_id' => (string) $publication->id,
                'content_id' => (string) $publication->content_id,
                'locale' => (string) ($publication->locale?->value ?? $publication->getRawOriginal('locale') ?? ''),
                'exception_class' => $exception::class,
                'exception_message' => $exception->getMessage(),
            ]);
        }
    }

    private function invalidatePublicCacheWhenNeeded(ContentPublication $publication): void
    {
        if (! $publication->wasRecentlyCreated && ! $publication->wasChanged([
            'content_id',
            'destination_id',
            'client_site_id',
            'locale',
            'provider',
            'remote_id',
            'remote_url',
            'remote_status',
            'delivery_status',
            'last_delivered_at',
        ])) {
            return;
        }

        $dispatch = function () use ($publication): void {
            $fresh = $publication->fresh(['content.clientSite', 'content.translationSourceContent', 'content.localizedVariants', 'clientSite']);

            if (! $fresh instanceof ContentPublication) {
                return;
            }

            try {
                app(ContentCacheInvalidationService::class)->invalidatePublication(
                    $fresh,
                    'publication.saved'
                );
            } catch (\Throwable $exception) {
                Log::error('publication.observer.cache_invalidation_failed', [
                    'publication_id' => (string) $fresh->id,
                    'content_id' => (string) $fresh->content_id,
                    'locale' => (string) ($fresh->locale?->value ?? $fresh->getRawOriginal('locale') ?? ''),
                    'exception_class' => $exception::class,
                    'exception_message' => $exception->getMessage(),
                ]);
            }
        };

        if (app()->runningUnitTests()) {
            $dispatch();

            return;
        }

        DB::afterCommit($dispatch);
    }

    private function logDuplicateActivePublication(ContentPublication $publication): void
    {
        if ((string) $publication->provider !== ContentPublication::PROVIDER_LARAVEL) {
            return;
        }

        if ((string) $publication->delivery_status !== ContentPublication::STATUS_DELIVERED) {
            return;
        }

        if (! in_array((string) ($publication->remote_status ?? ContentPublication::REMOTE_PUBLISHED), [
            '',
            ContentPublication::REMOTE_PUBLISHED,
        ], true)) {
            return;
        }

        $locale = ContentPublication::normalizeLocale(
            $publication->locale instanceof \App\Enums\SupportedLanguage
                ? $publication->locale->value
                : $publication->getRawOriginal('locale')
        );

        $duplicates = ContentPublication::query()
            ->where('content_id', (string) $publication->content_id)
            ->where('provider', ContentPublication::PROVIDER_LARAVEL)
            ->where('delivery_status', ContentPublication::STATUS_DELIVERED)
            ->where(function ($query): void {
                $query->where('remote_status', ContentPublication::REMOTE_PUBLISHED)
                    ->orWhereNull('remote_status');
            })
            ->when($locale !== null, fn ($query) => $query->where('locale', $locale))
            ->count();

        if ($duplicates <= 1) {
            return;
        }

        Log::warning('publication.integrity.duplicate_active_detected', [
            'content_id' => (string) $publication->content_id,
            'provider' => (string) $publication->provider,
            'locale' => $locale,
            'active_count' => $duplicates,
        ]);
    }
}
