<?php

namespace App\Services\Publication;

use App\Models\Content;
use App\Models\ContentPublication;
use App\Models\ContentPublishTarget;
use App\Models\Draft;
use App\Services\Content\ContentCacheInvalidationService;
use Illuminate\Support\Facades\DB;

class PublicationLegacyCompatibilityService
{
    public function __construct(
        private readonly ContentPublicationStateService $publicationState,
        private readonly ContentCacheInvalidationService $cacheInvalidation,
    ) {}

    public function sync(ContentPublication $publication): void
    {
        if (! $publication->content_id) {
            return;
        }

        $content = Content::query()
            ->with('publications')
            ->find($publication->content_id);

        if (! $content instanceof Content) {
            return;
        }

        $contentUpdates = $this->publicationState->legacyShadowAttributes($content, $publication);

        Content::query()
            ->whereKey($publication->content_id)
            ->update($contentUpdates);

        $dispatch = function () use ($publication): void {
            $freshPublication = $publication->fresh(['content.clientSite', 'clientSite']);
            if ($freshPublication instanceof ContentPublication) {
                $this->cacheInvalidation->invalidatePublication($freshPublication, 'publication.legacy_sync');
            }
        };

        if (app()->runningUnitTests()) {
            $dispatch();
        } else {
            DB::afterCommit($dispatch);
        }

        if ($publication->provider !== ContentPublication::PROVIDER_WORDPRESS) {
            return;
        }

        ContentPublishTarget::query()
            ->where('content_id', $publication->content_id)
            ->when($publication->locale, fn ($query) => $query->where('language', $publication->locale->value))
            ->when($publication->destination_id, fn ($query) => $query->where('content_destination_id', $publication->destination_id))
            ->when(! $publication->destination_id && $publication->client_site_id, fn ($query) => $query->where('client_site_id', $publication->client_site_id))
            ->update([
                'wp_post_id' => $publication->remote_id,
            ]);
    }

    public function hydrateWordPressPublication(ContentPublication $publication, Content $content, ?Draft $draft = null): ContentPublication
    {
        if ($publication->provider !== ContentPublication::PROVIDER_WORDPRESS) {
            return $publication;
        }

        $draft?->loadMissing('brief');

        $remoteId = trim((string) ($publication->remote_id ?? ''));
        $remoteUrl = trim((string) ($publication->remote_url ?? ''));
        $resolvedFrom = '';

        if ($remoteId === '') {
            $remoteId = trim((string) ($content->wp_post_id ?? ''));
            if ($remoteId !== '') {
                $resolvedFrom = 'content.wp_post_id';
            }
        }

        if ($remoteId === '' && $draft) {
            $draftMeta = is_array($draft->meta) ? $draft->meta : [];
            $draftRefs = is_array(data_get($draftMeta, 'client_refs')) ? data_get($draftMeta, 'client_refs') : [];
            $briefRefs = is_array($draft->brief?->client_refs) ? $draft->brief->client_refs : [];
            $refs = array_replace($briefRefs, $draftRefs);

            $remoteId = trim((string) ($refs['wp_post_id'] ?? ''));
            if ($remoteId !== '') {
                $resolvedFrom = 'draft.client_refs';
            }
        }

        $publishTarget = null;
        if ($remoteId === '' || $remoteUrl === '') {
            $publishTarget = ContentPublishTarget::query()
                ->where('content_id', (string) $content->id)
                ->where('target_type', 'wp')
                ->when(
                    $publication->locale,
                    fn ($query) => $query->where('language', $publication->locale->value)
                )
                ->when(
                    $publication->destination_id,
                    fn ($query) => $query->where('content_destination_id', (string) $publication->destination_id),
                    fn ($query) => $query->where('client_site_id', (string) ($publication->client_site_id ?: $content->client_site_id))
                )
                ->latest('updated_at')
                ->first();
        }

        if ($remoteId === '' && $publishTarget) {
            $remoteId = trim((string) (
                $publishTarget->wp_post_id
                ?? data_get($publishTarget->meta, 'wp_post_id')
                ?? $publishTarget->target_identifier
                ?? ''
            ));

            if ($remoteId !== '') {
                $resolvedFrom = 'content_publish_targets';
            }
        }

        if ($remoteUrl === '') {
            $remoteUrl = trim((string) ($content->published_url ?? ''));

            if ($remoteUrl === '' && $publishTarget) {
                $remoteUrl = trim((string) (
                    data_get($publishTarget->meta, 'published_url')
                    ?? data_get($publishTarget->meta, 'url')
                    ?? ''
                ));
            }
        }

        if ($remoteId === '' && $remoteUrl === '') {
            return $publication;
        }

        $meta = is_array($publication->meta) ? $publication->meta : [];
        $legacyContext = is_array($meta['legacy_context'] ?? null) ? $meta['legacy_context'] : [];

        $updates = [];

        if ($remoteId !== '' && trim((string) $publication->remote_id) === '') {
            $updates['remote_id'] = $remoteId;
            $updates['remote_type'] = $publication->remote_type ?: 'post';
        }

        if ($remoteUrl !== '' && trim((string) $publication->remote_url) === '') {
            $updates['remote_url'] = $remoteUrl;
        }

        if ($resolvedFrom !== '') {
            $legacyContext['recovered_remote_context_from'] = $resolvedFrom;
            $legacyContext['recovered_at'] = now()->toIso8601String();
            $meta['legacy_context'] = $legacyContext;
            $updates['meta'] = $meta;
        }

        if ($updates === []) {
            return $publication;
        }

        $publication->forceFill($updates)->save();

        return $publication->fresh(['destination']);
    }
}
