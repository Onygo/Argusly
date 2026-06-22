<?php

namespace App\View\Presenters;

use App\Enums\ContentDestinationType;
use App\Enums\ContentLifecycleStatus;
use App\Enums\PublicationDeliveryStatus;
use App\Enums\RemoteExistenceStatus;
use App\Enums\RemotePublishStatus;
use App\Models\Content;
use App\Models\ContentDestination;
use App\Models\ContentDeliveryEvent;
use App\Models\ContentPublication;
use App\Services\Publication\ContentPublicationStateService;
use App\Services\Seo\CanonicalUrlService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Presenter for content status display.
 *
 * Separates Argusly internal state from remote delivery state,
 * providing clean computed properties for UI presentation.
 */
class ContentStatusPresenter
{
    private ?ContentPublication $publication = null;

    private ?ContentDestination $destination = null;

    private ?ContentDestinationType $destinationType = null;

    private ?PublicationDestinationPresenter $destinationPresenter = null;

    private ContentPublicationStateService $publicationState;

    public function __construct(
        private readonly Content $content
    ) {
        $content->loadMissing('contentDestination', 'clientSite');
        $this->publicationState = app(ContentPublicationStateService::class);

        $this->destination = $content->contentDestination;
        $this->destinationType = $this->destination
            ? $this->destination->resolvedType()
            : ContentDestinationType::fromNormalized($content->clientSite?->type);
        $this->publication = $this->resolvePublication();
        $this->destinationPresenter = PublicationDestinationPresenter::for($this->destination, $this->publication, $content);
    }

    private function resolvePublication(): ?ContentPublication
    {
        $provider = match ($this->destinationType) {
            ContentDestinationType::WORDPRESS => ContentPublication::PROVIDER_WORDPRESS,
            ContentDestinationType::LARAVEL => ContentPublication::PROVIDER_LARAVEL,
            ContentDestinationType::API => ContentPublication::PROVIDER_API,
            default => null,
        };

        return $this->publicationState->resolveCanonicalPublication(
            $this->content,
            $this->destination?->id ? (string) $this->destination->id : null,
            $provider,
        );
    }

    public function destinationType(): ?string
    {
        return $this->destinationType?->value;
    }

    public function destinationLabel(): string
    {
        return $this->destinationPresenter?->label() ?? ContentDestinationType::label($this->destinationType);
    }

    public function remoteStatusLabel(): string
    {
        return $this->destinationPresenter?->statusLabel() ?? 'Unknown destination';
    }

    // =========================================================================
    // Argusly Lifecycle Status
    // =========================================================================

    public function lifecycleStatus(): ContentLifecycleStatus
    {
        if ($this->publicationState->isPublished($this->content, $this->publication, false)) {
            return ContentLifecycleStatus::PUBLISHED;
        }

        return ContentLifecycleStatus::fromLegacyStatus($this->content->status);
    }

    public function lifecycleLabel(): string
    {
        return $this->lifecycleStatus()->label();
    }

    public function lifecycleColor(): string
    {
        return $this->lifecycleStatus()->color();
    }

    public function lifecycleIcon(): string
    {
        return $this->lifecycleStatus()->icon();
    }

    // =========================================================================
    // Remote Delivery Status
    // =========================================================================

    public function deliveryStatus(): PublicationDeliveryStatus
    {
        return $this->publicationState->deliveryStatus($this->content, $this->publication);
    }

    public function deliveryLabel(): string
    {
        return $this->deliveryStatus()->label();
    }

    public function deliveryColor(): string
    {
        return $this->deliveryStatus()->color();
    }

    public function deliveryIcon(): string
    {
        return $this->deliveryStatus()->icon();
    }

    public function hasDeliveryError(): bool
    {
        return $this->deliveryStatus()->isFailure();
    }

    // =========================================================================
    // Remote Existence Status
    // =========================================================================

    public function existenceStatus(): RemoteExistenceStatus
    {
        if (! $this->publication) {
            if ($this->content->delivery_status === 'missing_remote') {
                return RemoteExistenceStatus::MISSING;
            }

            if ($this->remoteId() || $this->publishedUrl()) {
                return RemoteExistenceStatus::EXISTS;
            }

            return RemoteExistenceStatus::UNKNOWN;
        }

        if ($this->publication->delivery_status === 'missing_remote') {
            return RemoteExistenceStatus::MISSING;
        }

        if ($this->publication->hasRemoteId() || trim((string) $this->publication->remote_url) !== '') {
            return RemoteExistenceStatus::EXISTS;
        }

        return RemoteExistenceStatus::UNKNOWN;
    }

    public function existenceLabel(): string
    {
        return $this->existenceStatus()->label();
    }

    // =========================================================================
    // Remote Publish Status
    // =========================================================================

    public function remotePublishStatus(): ?RemotePublishStatus
    {
        return $this->publicationState->remotePublishStatus($this->content, $this->publication);
    }

    public function remotePublishLabel(): string
    {
        return $this->remotePublishStatus()?->label() ?? 'Unknown';
    }

    public function remotePublishColor(): string
    {
        return $this->remotePublishStatus()?->color() ?? 'slate';
    }

    // =========================================================================
    // Computed Presentation Properties
    // =========================================================================

    /**
     * Primary status badge info for content listings.
     * Shows Argusly lifecycle status.
     */
    public function primaryBadge(): array
    {
        return [
            'label' => $this->lifecycleLabel(),
            'color' => $this->lifecycleColor(),
            'icon' => $this->lifecycleIcon(),
        ];
    }

    /**
     * Secondary status badge for remote state.
     * Only shown when relevant (has publication or needs attention).
     */
    public function secondaryBadge(): ?array
    {
        // Only show secondary badge if there's a publication or delivery issue
        if (! $this->hasPublication() && ! $this->hasDeliveryError()) {
            return null;
        }

        $delivery = $this->deliveryStatus();

        // Show attention-grabbing badge for issues
        if ($delivery->needsAttention()) {
            return [
                'label' => $delivery->label(),
                'color' => $delivery->color(),
                'icon' => $delivery->icon(),
                'tooltip' => $this->lastErrorMessage(),
            ];
        }

        // Show success badge for delivered content
        if ($delivery->isSuccess()) {
            return [
                'label' => 'Synced',
                'color' => 'green',
                'icon' => 'check-circle',
                'tooltip' => $this->lastSyncFormatted(),
            ];
        }

        return null;
    }

    /**
     * Full status summary for detail views.
     */
    public function fullStatus(): array
    {
        return [
            'argusly' => [
                'label' => 'Argusly Status',
                'value' => $this->lifecycleLabel(),
                'color' => $this->lifecycleColor(),
                'icon' => $this->lifecycleIcon(),
            ],
            'delivery' => [
                'label' => 'Delivery Status',
                'value' => $this->deliveryLabel(),
                'color' => $this->deliveryColor(),
                'icon' => $this->deliveryIcon(),
            ],
            'remote' => [
                'label' => $this->remoteStatusLabel(),
                'value' => $this->remotePublishLabel(),
                'color' => $this->remotePublishColor(),
                'icon' => $this->remotePublishStatus()?->icon() ?? 'question-mark-circle',
                'url' => $this->publishedUrl(),
            ],
            'sync' => [
                'label' => 'Last Sync',
                'value' => $this->lastSyncFormatted(),
                'timestamp' => $this->lastSyncTimestamp(),
            ],
            'error' => $this->hasDeliveryError() ? [
                'label' => 'Last Error',
                'value' => $this->lastErrorMessage(),
                'timestamp' => $this->lastErrorTimestamp(),
            ] : null,
        ];
    }

    // =========================================================================
    // Timestamps and Metadata
    // =========================================================================

    public function lastSyncTimestamp(): ?Carbon
    {
        return $this->publication?->last_delivered_at;
    }

    public function lastSyncFormatted(): ?string
    {
        $timestamp = $this->lastSyncTimestamp();

        return $timestamp?->diffForHumans();
    }

    public function lastErrorTimestamp(): ?Carbon
    {
        return $this->publication?->last_error_at;
    }

    public function lastErrorMessage(): ?string
    {
        if ($this->publication) {
            $message = trim((string) ($this->publication->publicErrorMessage() ?? ''));

            return $message !== '' ? $message : null;
        }

        return $this->content->publish_error;
    }

    public function lastErrorCode(): ?string
    {
        return $this->publication?->last_error_code;
    }

    public function publishedUrl(): ?string
    {
        return app(CanonicalUrlService::class)->liveUrlForContent(
            $this->content,
            $this->publication?->remote_url ?? $this->content->published_url
        );
    }

    public function remoteId(): ?string
    {
        if ($this->publication?->remote_id) {
            return $this->publication->remote_id;
        }

        if ($this->destinationType === ContentDestinationType::WORDPRESS) {
            return $this->content->wp_post_id;
        }

        return null;
    }

    // =========================================================================
    // State Helpers
    // =========================================================================

    public function hasPublication(): bool
    {
        return $this->publication !== null;
    }

    public function isFullyPublished(): bool
    {
        // Content is fully published only when:
        // 1. Argusly status is delivered
        // 2. Delivery was successful
        // 3. Remote resource exists (or UNKNOWN for native destinations)
        $destinationType = $this->destinationType
            ? ContentDestinationType::fromNormalized($this->destinationType)
            : null;

        return $this->lifecycleStatus()->normalized() === ContentLifecycleStatus::PUBLISHED
            && $this->deliveryStatus()->isSuccess()
            && $this->existenceStatus()->isHealthyFor($destinationType);
    }

    public function isPartiallyPublished(): bool
    {
        // Published in Argusly but remote has issues
        $destinationType = $this->destinationType
            ? ContentDestinationType::fromNormalized($this->destinationType)
            : null;

        return $this->lifecycleStatus()->normalized() === ContentLifecycleStatus::PUBLISHED
            && ! $this->existenceStatus()->isHealthyFor($destinationType);
    }

    public function needsAttention(): bool
    {
        return $this->deliveryStatus()->needsAttention()
            || $this->existenceStatus()->isGone();
    }

    public function canDeliver(): bool
    {
        return $this->lifecycleStatus()->isDeliverable()
            && $this->deliveryStatus()->canDeliver();
    }

    public function canEdit(): bool
    {
        return $this->lifecycleStatus()->isEditable();
    }

    /**
     * Get the publication record if available.
     */
    public function getPublication(): ?ContentPublication
    {
        return $this->publication;
    }

    // =========================================================================
    // Delivery Actions and Recovery
    // =========================================================================

    /**
     * Get available delivery actions based on current state.
     *
     * @return array<string, array{label: string, route: string, confirm: bool, icon: string, external?: bool}>
     */
    public function deliveryActions(): array
    {
        $actions = [];
        $delivery = $this->deliveryStatus();
        $existence = $this->existenceStatus();
        $destinationType = $this->destinationType();

        if ($destinationType !== null && ($this->canDeliver() || $delivery->isSuccess())) {
            $actions['republish'] = [
                'label' => $this->republishActionLabel($existence->needsRecreation()),
                'route' => 'app.content.republish',
                'confirm' => $existence->needsRecreation(),
                'icon' => $existence->needsRecreation() ? 'plus-circle' : 'refresh-cw',
            ];
        }

        // Retry Delivery: Available when delivery failed
        if ($delivery->canRetry() && ! $existence->needsRecreation()) {
            $actions['retry'] = [
                'label' => 'Retry Delivery',
                'route' => 'app.content.republish',
                'confirm' => false,
                'icon' => 'rotate-ccw',
            ];
        }

        // Verify Remote: Only when we have a remote ID to check
        if ($this->canVerifyRemote()) {
            $actions['verify'] = [
                'label' => $this->verifyActionLabel(),
                'route' => 'app.content.verify-remote',
                'confirm' => false,
                'icon' => 'search',
            ];
        }

        if ($this->canOpenOnSite()) {
            $actions['open_remote'] = [
                'label' => $this->openRemoteActionLabel(),
                'route' => $this->publishedUrl(),
                'confirm' => false,
                'icon' => 'external-link',
                'external' => true,
            ];
        }

        return $actions;
    }

    /**
     * Categorize the error type for UI display and recovery guidance.
     */
    public function errorCategory(): ?string
    {
        if (! $this->hasDeliveryError() && ! $this->existenceStatus()->isGone()) {
            return null;
        }

        // Missing remote takes precedence
        if ($this->existenceStatus()->isGone()) {
            return 'missing';
        }

        $errorCode = $this->lastErrorCode();
        $errorMessage = strtolower((string) $this->lastErrorMessage());

        // Authentication errors
        if (in_array($errorCode, ['401', '403'], true)
            || str_contains($errorMessage, 'unauthorized')
            || str_contains($errorMessage, 'forbidden')) {
            return 'auth';
        }

        // Validation errors
        if ($errorCode === '422'
            || str_contains($errorMessage, 'invalid')
            || str_contains($errorMessage, 'validation')) {
            return 'validation';
        }

        // Transport/connectivity errors
        if (str_contains($errorMessage, 'timeout')
            || str_contains($errorMessage, 'connection')
            || str_contains($errorMessage, 'curl')
            || in_array($errorCode, ['500', '502', '503', '504'], true)) {
            return 'transport';
        }

        return 'unknown';
    }

    /**
     * Get user-friendly recovery message explaining what happened and what to do.
     */
    public function recoveryMessage(): ?string
    {
        $existence = $this->existenceStatus();

        // Handle missing remote specially
        if ($existence->isGone()) {
            $destinationLabel = $this->destinationLabel();

            return match ($existence) {
                RemoteExistenceStatus::MISSING =>
                    sprintf('This content exists in Argusly, but the linked %s resource no longer exists. Republishing will recreate it.', strtolower($destinationLabel)),
                RemoteExistenceStatus::TRASHED =>
                    sprintf('The %s resource is in the trash. Restore it on the destination, or republish to create a new resource.', strtolower($destinationLabel)),
                RemoteExistenceStatus::DELETED =>
                    sprintf('The %s resource was permanently deleted. Republishing will create a new resource.', strtolower($destinationLabel)),
                default => null,
            };
        }

        $category = $this->errorCategory();
        if ($category === null) {
            return null;
        }

        return match ($category) {
            'auth' => sprintf('%s rejected the connection. Check the destination credentials and retry the delivery.', $this->destinationLabel()),
            'validation' => sprintf('%s rejected the content payload. Check required fields and destination-specific constraints.', $this->destinationLabel()),
            'transport' => sprintf('Could not reach %s. Check if the destination is online and retry delivery.', $this->destinationLabel()),
            default => 'An unexpected error occurred. Try republishing or contact support if the issue persists.',
        };
    }

    /**
     * Check if we can verify remote existence.
     */
    public function canVerifyRemote(): bool
    {
        $destinationType = ContentDestinationType::fromNormalized($this->destinationType());

        // Only allow verification for destinations that support it
        if (! in_array($this->destinationType(), [
            ContentDestinationType::WORDPRESS->value,
            ContentDestinationType::LARAVEL->value,
        ], true)) {
            return false;
        }

        // Must have something to verify
        if ($this->remoteId() === null && $this->publishedUrl() === null && ! $this->deliveryStatus()->isSuccess()) {
            return false;
        }

        // For native destinations (Laravel), only show verify if there's actually a problem
        // This makes it informational/debugging rather than required workflow
        if ($destinationType?->isNativeDestination() === true) {
            return $this->deliveryStatus()->needsAttention()
                || $this->existenceStatus()->isGone();
        }

        // For remote destinations (WordPress), always allow verification
        return true;
    }

    /**
     * Check if we can open on the destination.
     */
    public function canOpenOnSite(): bool
    {
        $url = $this->publishedUrl();

        return $url !== null && $url !== '';
    }

    public function canOpenInWordPress(): bool
    {
        return $this->canOpenOnSite();
    }

    private function republishActionLabel(bool $isRecreation): string
    {
        if ($isRecreation && $this->destinationType === ContentDestinationType::WORDPRESS) {
            return 'Recreate in WordPress';
        }

        return $this->destinationPresenter?->republishLabel() ?? match ($this->destinationType) {
            ContentDestinationType::WORDPRESS => $isRecreation ? 'Recreate in WordPress' : 'Republish to WordPress',
            ContentDestinationType::LARAVEL => 'Republish to Laravel',
            ContentDestinationType::API => 'Republish to API',
            default => 'Republish',
        };
    }

    private function verifyActionLabel(): string
    {
        if ($this->destinationPresenter?->verifyLabel()) {
            return $this->destinationPresenter->verifyLabel();
        }

        $destinationType = ContentDestinationType::fromNormalized($this->destinationType());

        return match ($destinationType) {
            ContentDestinationType::LARAVEL => 'Check Route Availability',
            ContentDestinationType::WORDPRESS => 'Verify WordPress Post',
            ContentDestinationType::API => 'Verify API Resource',
            default => 'Verify destination',
        };
    }

    private function openRemoteActionLabel(): string
    {
        return $this->destinationPresenter?->openRemoteLabel() ?? 'Open destination';
    }

    /**
     * Get recent delivery events for timeline display.
     *
     * @return Collection<int, ContentDeliveryEvent>
     */
    public function recentDeliveryEvents(int $limit = 10): Collection
    {
        if (! $this->publication) {
            return collect();
        }

        return $this->publication->deliveryEvents()
            ->latest('created_at')
            ->limit($limit)
            ->get();
    }

    /**
     * Get the underlying content model.
     */
    public function getContent(): Content
    {
        return $this->content;
    }

    /**
     * Create a presenter for a content model.
     */
    public static function for(Content $content): self
    {
        return new self($content);
    }
}
