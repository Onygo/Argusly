<?php

namespace App\Services\Publication;

use App\Enums\ContentDestinationType;
use App\Enums\ContentLifecycleStatus;
use App\Enums\PublicationDeliveryStatus;
use App\Enums\RemotePublishStatus;
use App\Models\Content;
use App\Models\ContentPublication;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

class ContentPublicationStateService
{
    public function resolveCanonicalPublication(
        Content $content,
        ?string $destinationId = null,
        ?string $provider = null,
    ): ?ContentPublication {
        $publications = $content->relationLoaded('publications')
            ? $content->publications
            : $content->publications()->get();

        return $this->selectCanonicalPublication(
            $publications,
            $content,
            $destinationId,
            $provider,
        );
    }

    /**
     * @param  EloquentCollection<int,ContentPublication>  $publications
     */
    public function selectCanonicalPublication(
        EloquentCollection $publications,
        Content $content,
        ?string $destinationId = null,
        ?string $provider = null,
    ): ?ContentPublication {
        if ($publications->isEmpty()) {
            return null;
        }

        $expectedDestinationId = trim((string) ($destinationId ?: $content->content_destination_id ?: ''));
        $expectedProvider = trim((string) ($provider ?: $this->providerForContent($content) ?: ''));
        $expectedLocale = $content->localeCode();
        $matchedDestination = false;

        $candidates = $publications;

        if ($expectedDestinationId !== '') {
            $destinationMatches = $candidates
                ->filter(fn (ContentPublication $candidate): bool => (string) ($candidate->destination_id ?? '') === $expectedDestinationId)
                ->values();

            if ($destinationMatches->isNotEmpty()) {
                $candidates = $destinationMatches;
                $matchedDestination = true;
            }
        }

        if ($expectedProvider !== '') {
            $providerMatches = $candidates
                ->filter(fn (ContentPublication $candidate): bool => (string) ($candidate->provider ?? '') === $expectedProvider)
                ->values();

            if ($providerMatches->isNotEmpty()) {
                $candidates = $providerMatches;
            } elseif (! $matchedDestination) {
                return null;
            }
        }

        if ($expectedLocale !== '') {
            $localeMatches = $candidates
                ->filter(fn (ContentPublication $candidate): bool => $this->publicationLocale($candidate) === $expectedLocale)
                ->values();

            if ($localeMatches->isNotEmpty()) {
                $candidates = $localeMatches;
            }
        }

        /** @var ContentPublication|null $publication */
        $publication = $candidates
            ->sortBy(fn (ContentPublication $candidate): array => [
                $this->destinationScore($candidate, $expectedDestinationId),
                $this->providerScore($candidate, $expectedProvider),
                $this->localeScore($candidate, $expectedLocale),
                -1 * $this->publicationTimestamp($candidate),
            ])
            ->first();

        return $publication;
    }

    public function deliveryStatus(
        Content $content,
        ?ContentPublication $publication = null,
        bool $allowLegacyFallback = true,
    ): PublicationDeliveryStatus {
        $publication ??= $this->resolveCanonicalPublication($content);

        if ($publication instanceof ContentPublication) {
            return PublicationDeliveryStatus::fromLegacyStatus((string) $publication->delivery_status);
        }

        if (! $allowLegacyFallback) {
            return PublicationDeliveryStatus::PENDING;
        }

        return PublicationDeliveryStatus::fromLegacyStatus((string) ($content->delivery_status ?? 'pending'));
    }

    public function remotePublishStatus(
        Content $content,
        ?ContentPublication $publication = null,
        bool $allowLegacyFallback = true,
    ): ?RemotePublishStatus {
        $publication ??= $this->resolveCanonicalPublication($content);

        if ($publication instanceof ContentPublication) {
            $remoteStatus = trim((string) ($publication->remote_status ?? ''));
            if ($remoteStatus !== '') {
                return RemotePublishStatus::fromRemoteStatus($remoteStatus);
            }

            $deliveryStatus = PublicationDeliveryStatus::fromLegacyStatus((string) $publication->delivery_status);

            if ($deliveryStatus->isSuccess()) {
                return RemotePublishStatus::PUBLISHED;
            }

            if ($deliveryStatus->isInProgress()) {
                return null;
            }
        }

        if (! $allowLegacyFallback) {
            return null;
        }

        $publishStatus = trim((string) ($content->publish_status ?? ''));

        return $publishStatus !== ''
            ? RemotePublishStatus::fromRemoteStatus($publishStatus)
            : null;
    }

    public function isPublished(
        Content $content,
        ?ContentPublication $publication = null,
        bool $allowLegacyFallback = true,
    ): bool {
        $publication ??= $this->resolveCanonicalPublication($content);
        $remotePublishStatus = $this->remotePublishStatus($content, $publication, $allowLegacyFallback);
        $deliveryStatus = $this->deliveryStatus($content, $publication, $allowLegacyFallback);

        if ($publication instanceof ContentPublication) {
            return $deliveryStatus->isSuccess()
                && $remotePublishStatus instanceof RemotePublishStatus
                && $remotePublishStatus->isVisible();
        }

        if (! $allowLegacyFallback) {
            return false;
        }

        return (string) ($content->status ?? '') === 'published'
            && (string) ($content->publish_status ?? '') === 'published';
    }

    /**
     * @return array{
     *   delivery_status:string,
     *   publish_status:string,
     *   publish_error:?string,
     *   status:string,
     *   published_url:?string,
     *   wp_post_id:?string
     * }
     */
    public function legacyShadowAttributes(Content $content, ?ContentPublication $publication = null): array
    {
        $publication ??= $this->resolveCanonicalPublication($content);

        if (! $publication instanceof ContentPublication) {
            return [
                'delivery_status' => (string) ($content->delivery_status ?? 'pending'),
                'publish_status' => (string) ($content->publish_status ?? 'draft'),
                'publish_error' => $content->publish_error,
                'status' => (string) ($content->status ?? 'draft'),
                'published_url' => $content->published_url,
                'wp_post_id' => $content->wp_post_id,
            ];
        }

        $deliveryStatus = PublicationDeliveryStatus::fromLegacyStatus((string) $publication->delivery_status);
        $remotePublishStatus = $this->remotePublishStatus($content, $publication, false);

        $publishStatus = match (true) {
            $deliveryStatus->isInProgress() => 'publishing',
            $remotePublishStatus === RemotePublishStatus::SCHEDULED => 'scheduled',
            $remotePublishStatus instanceof RemotePublishStatus && $remotePublishStatus->isVisible() => 'published',
            $deliveryStatus->isFailure() => 'failed',
            $deliveryStatus->isSuccess() => 'published',
            default => 'draft',
        };

        $status = $this->legacyLifecycleStatus($content, $deliveryStatus, $remotePublishStatus);
        $lifecycleStage = match ($status) {
            'published' => ContentLifecycleStatus::PUBLISHED->value,
            'scheduled' => ContentLifecycleStatus::SCHEDULED->value,
            'ready_to_deliver', 'approved' => ContentLifecycleStatus::APPROVED->value,
            'review' => ContentLifecycleStatus::REVIEW->value,
            'archived' => ContentLifecycleStatus::ARCHIVED->value,
            'draft', 'generated', 'generating' => ContentLifecycleStatus::DRAFT->value,
            'brief', 'brief_received' => ContentLifecycleStatus::BRIEF->value,
            default => $content->lifecycleStageEnum()->value,
        };

        return [
            'delivery_status' => $deliveryStatus->value,
            'publish_status' => $publishStatus,
            'publish_error' => $deliveryStatus->needsAttention() ? $publication->last_error_message : null,
            'status' => $status,
            'lifecycle_stage' => $lifecycleStage,
            'published_url' => $publication->remote_url ?: $content->published_url,
            'wp_post_id' => $publication->provider === ContentPublication::PROVIDER_WORDPRESS
                ? ($publication->remote_id ?: $content->wp_post_id)
                : null,
        ];
    }

    private function legacyLifecycleStatus(
        Content $content,
        PublicationDeliveryStatus $deliveryStatus,
        ?RemotePublishStatus $remotePublishStatus,
    ): string {
        if (
            $remotePublishStatus?->isVisible()
            || ($remotePublishStatus === null && $deliveryStatus->isSuccess())
            || $deliveryStatus === PublicationDeliveryStatus::MISSING_REMOTE
        ) {
            return 'published';
        }

        return (string) ($content->status ?? 'draft');
    }

    private function providerForContent(Content $content): ?string
    {
        $destinationType = $content->contentDestination?->resolvedType()
            ?? ContentDestinationType::fromNormalized($content->clientSite?->type);

        return match ($destinationType) {
            ContentDestinationType::WORDPRESS => ContentPublication::PROVIDER_WORDPRESS,
            ContentDestinationType::LARAVEL => ContentPublication::PROVIDER_LARAVEL,
            ContentDestinationType::API => ContentPublication::PROVIDER_API,
            default => null,
        };
    }

    private function destinationScore(ContentPublication $publication, string $expectedDestinationId): int
    {
        if ($expectedDestinationId === '') {
            return 1;
        }

        return (string) ($publication->destination_id ?? '') === $expectedDestinationId ? 0 : 1;
    }

    private function providerScore(ContentPublication $publication, string $expectedProvider): int
    {
        if ($expectedProvider === '') {
            return 1;
        }

        return (string) ($publication->provider ?? '') === $expectedProvider ? 0 : 1;
    }

    private function localeScore(ContentPublication $publication, string $expectedLocale): int
    {
        if ($expectedLocale === '') {
            return 1;
        }

        $publicationLocale = $this->publicationLocale($publication);

        return $publicationLocale === $expectedLocale ? 0 : 1;
    }

    private function publicationLocale(ContentPublication $publication): string
    {
        return (string) ($publication->locale?->value ?? $publication->getRawOriginal('locale') ?? '');
    }

    private function publicationTimestamp(ContentPublication $publication): int
    {
        return max(
            (int) ($publication->last_delivered_at?->timestamp ?? 0),
            (int) ($publication->updated_at?->timestamp ?? 0),
            (int) ($publication->created_at?->timestamp ?? 0),
        );
    }
}
