<?php

namespace App\Models;

use App\Enums\ContentDestinationType;
use App\Enums\SupportedLanguage;
use App\Enums\PublicationDeliveryStatus;
use App\Enums\RemoteExistenceStatus;
use App\Enums\RemotePublishStatus;
use App\View\Presenters\ContentStatusPresenter;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;

/**
 * Represents a content publication to a remote destination.
 *
 * ## Phase 1 Refactor: Single Source of Truth for Remote Publications
 *
 * This is the canonical source for tracking remote publications. It replaces
 * scattered WordPress ID storage across multiple tables with a unified,
 * provider-agnostic publication record.
 *
 * ### Remote ID - ContentPublication.remote_id is Canonical
 *
 * The remote_id field is the single source of truth for remote identifiers:
 * - WordPress: stores the wp_post_id
 * - Laravel: stores the model ID
 * - API/Webhook: stores the external reference
 *
 * Legacy locations (deprecated, read-only fallbacks):
 * - Content.wp_post_id - @deprecated use ContentPublication.remote_id
 * - ContentPublishTarget.wp_post_id - @deprecated transitional
 * - Draft.meta.client_refs.wp_post_id - @deprecated transitional
 *
 * ### Delivery Status - ContentPublication.delivery_status is Authoritative
 *
 * The delivery_status field is the authoritative source for delivery state:
 * - Uses PublicationDeliveryStatus enum for type-safe status handling
 * - Content.delivery_status is a shadow/sync field for backwards compatibility
 * - Draft.delivery_status tracks per-draft attempts (ephemeral)
 *
 * ### Status Separation
 *
 * - delivery_status: The state of delivery to the remote (pending, delivered, failed, etc.)
 * - remote_status: The publication state on the remote (draft, published, scheduled)
 * - remote_existence: Whether the remote resource exists (exists, missing, trashed)
 *
 * @see \App\Models\Content::getCanonicalRemoteId() for backwards-compatible resolution
 * @see \App\Models\Content::resolveDeliveryStatus() for status resolution
 * @see \App\Enums\PublicationDeliveryStatus for delivery status enum
 * @see \App\Enums\RemotePublishStatus for remote publication state enum
 */
class ContentPublication extends Model
{
    use HasFactory;
    use HasUuids;

    // Provider types
    public const PROVIDER_WORDPRESS = 'wordpress';
    public const PROVIDER_LARAVEL = 'laravel';
    public const PROVIDER_API = 'api';
    public const PROVIDER_WEBHOOK = 'webhook';

    // Legacy delivery statuses (use PublicationDeliveryStatus enum for new code)
    public const STATUS_PENDING = 'pending';
    public const STATUS_DELIVERED = 'delivered';
    public const STATUS_FAILED = 'failed';
    public const STATUS_MISSING_REMOTE = 'missing_remote';
    public const STATUS_CANCELLED = 'cancelled';

    // Legacy remote statuses (use RemotePublishStatus enum for new code)
    public const REMOTE_DRAFT = 'draft';
    public const REMOTE_PUBLISHED = 'published';
    public const REMOTE_SCHEDULED = 'scheduled';
    public const REMOTE_TRASH = 'trash';

    protected $fillable = [
        'content_id',
        'destination_id',
        'client_site_id',
        'locale',
        'provider',
        'remote_id',
        'remote_type',
        'remote_url',
        'remote_status',
        'delivery_status',
        'payload_checksum',
        'last_verified_at',
        'last_delivered_at',
        'scheduled_publish_at',
        'last_error_at',
        'last_error_code',
        'last_error_message',
        'meta',
    ];

    protected $casts = [
        'meta' => 'array',
        'last_verified_at' => 'datetime',
        'last_delivered_at' => 'datetime',
        'scheduled_publish_at' => 'datetime',
        'last_error_at' => 'datetime',
        'locale' => SupportedLanguage::class,
    ];

    // Relationships

    public function content(): BelongsTo
    {
        return $this->belongsTo(Content::class);
    }

    public function destination(): BelongsTo
    {
        return $this->belongsTo(ContentDestination::class, 'destination_id');
    }

    public function clientSite(): BelongsTo
    {
        return $this->belongsTo(ClientSite::class);
    }

    public function deliveryEvents(): HasMany
    {
        return $this->hasMany(ContentDeliveryEvent::class)->orderByDesc('created_at');
    }

    // Query scopes

    public function scopeForContent($query, string $contentId)
    {
        return $query->where('content_id', $contentId);
    }

    public function scopeForDestination($query, string $destinationId)
    {
        return $query->where('destination_id', $destinationId);
    }

    public function scopeForClientSite($query, string $clientSiteId)
    {
        return $query->where('client_site_id', $clientSiteId);
    }

    public function scopeForProvider($query, string $provider)
    {
        return $query->where('provider', $provider);
    }

    public function scopeForLocale($query, string $locale)
    {
        return $query->where('locale', static::normalizeLocale($locale));
    }

    public function scopeDelivered($query)
    {
        return $query->where('delivery_status', self::STATUS_DELIVERED);
    }

    public function scopeFailed($query)
    {
        return $query->where('delivery_status', self::STATUS_FAILED);
    }

    public function scopeMissingRemote($query)
    {
        return $query->where('delivery_status', self::STATUS_MISSING_REMOTE);
    }

    public function scopeScheduledForPublication($query)
    {
        return $query->whereNotNull('scheduled_publish_at');
    }

    // Status helpers

    public function isDelivered(): bool
    {
        return $this->delivery_status === self::STATUS_DELIVERED;
    }

    public function isFailed(): bool
    {
        return $this->delivery_status === self::STATUS_FAILED;
    }

    public function isMissingRemote(): bool
    {
        return $this->delivery_status === self::STATUS_MISSING_REMOTE;
    }

    public function isPending(): bool
    {
        return $this->delivery_status === self::STATUS_PENDING;
    }

    public function isTerminalForProgrammaticScheduling(): bool
    {
        return in_array((string) $this->delivery_status, [
            self::STATUS_DELIVERED,
            self::STATUS_FAILED,
            self::STATUS_MISSING_REMOTE,
            'failed_delivered',
            'partial_success',
            'out_of_sync',
        ], true) || in_array((string) $this->remote_status, [
            self::REMOTE_PUBLISHED,
            'live',
        ], true);
    }

    public function hasRemoteId(): bool
    {
        return trim((string) $this->remote_id) !== '';
    }

    public function isWordPress(): bool
    {
        return $this->type === self::PROVIDER_WORDPRESS;
    }

    public function getTypeAttribute(): ?string
    {
        return ContentDestinationType::normalize($this->provider);
    }

    // Status mutations

    public function markDelivered(string $remoteId, ?string $remoteUrl = null, ?string $remoteType = null): void
    {
        $this->forceFill([
            'remote_id' => $remoteId,
            'remote_url' => $remoteUrl ?: $this->remote_url,
            'remote_type' => $remoteType ?: $this->remote_type,
            'remote_status' => self::REMOTE_PUBLISHED,
            'delivery_status' => self::STATUS_DELIVERED,
            'last_delivered_at' => now(),
            'last_error_at' => null,
            'last_error_code' => null,
            'last_error_message' => null,
        ])->save();
    }

    public function markFailed(string $errorCode, string $errorMessage): void
    {
        $this->forceFill([
            'delivery_status' => self::STATUS_FAILED,
            'last_error_at' => now(),
            'last_error_code' => $errorCode,
            'last_error_message' => $errorMessage,
        ])->save();
    }

    public function markMissingRemote(?string $previousRemoteId = null): void
    {
        $meta = is_array($this->meta) ? $this->meta : [];

        if ($previousRemoteId) {
            $previousIds = $meta['previous_remote_ids'] ?? [];
            if (! is_array($previousIds)) {
                $previousIds = [];
            }
            $previousIds[] = $previousRemoteId;
            $meta['previous_remote_ids'] = array_values(array_unique($previousIds));
        }

        $this->forceFill([
            'remote_id' => null,
            'remote_status' => null,
            'delivery_status' => self::STATUS_MISSING_REMOTE,
            'meta' => $meta,
        ])->save();
    }

    public function markVerified(): void
    {
        $this->forceFill([
            'last_verified_at' => now(),
        ])->save();
    }

    public function updatePayloadChecksum(string $checksum): void
    {
        $this->forceFill([
            'payload_checksum' => $checksum,
        ])->save();
    }

    // Factory methods

    /**
     * Resolve or create a publication for content + destination.
     */
    public static function resolveForDelivery(
        string $contentId,
        ?string $destinationId = null,
        ?string $clientSiteId = null,
        string $provider = self::PROVIDER_WORDPRESS,
        mixed $locale = null,
    ): self {
        $resolvedLocale = static::resolveLocale($contentId, $locale);
        $query = self::query()
            ->where('content_id', $contentId)
            ->where('provider', $provider);

        if ($destinationId) {
            $query->where('destination_id', $destinationId);
        } elseif ($clientSiteId) {
            $query->where('client_site_id', $clientSiteId)
                ->whereNull('destination_id');
        }

        if ($resolvedLocale !== null) {
            $query->where(function ($localeQuery) use ($resolvedLocale): void {
                $localeQuery->where('locale', $resolvedLocale)
                    ->orWhereNull('locale');
            });
        }

        $publication = $query
            ->orderByRaw('CASE WHEN remote_id IS NULL OR remote_id = "" THEN 1 ELSE 0 END')
            ->orderByRaw('CASE WHEN locale IS NULL OR locale = "" THEN 1 ELSE 0 END')
            ->orderByDesc('last_delivered_at')
            ->orderByDesc('updated_at')
            ->first();

        if ($publication) {
            $updates = [];

            $publicationLocale = static::normalizeLocale(
                $publication->locale instanceof SupportedLanguage
                    ? $publication->locale->value
                    : $publication->getRawOriginal('locale')
            );

            if ($resolvedLocale !== null && $publicationLocale !== $resolvedLocale) {
                $updates['locale'] = $resolvedLocale;
            }

            if ($destinationId && (string) ($publication->destination_id ?? '') !== $destinationId) {
                $updates['destination_id'] = $destinationId;
            }

            if ($clientSiteId && (string) ($publication->client_site_id ?? '') !== $clientSiteId) {
                $updates['client_site_id'] = $clientSiteId;
            }

            if ($updates !== []) {
                $publication->forceFill($updates)->save();
            }

            return $publication;
        }

        if ($destinationId && $clientSiteId) {
            $legacyPublication = self::query()
                ->where('content_id', $contentId)
                ->where('client_site_id', $clientSiteId)
                ->where('provider', $provider)
                ->whereNull('destination_id')
                ->when($resolvedLocale !== null, function ($legacyQuery) use ($resolvedLocale): void {
                    $legacyQuery->where(function ($localeQuery) use ($resolvedLocale): void {
                        $localeQuery->where('locale', $resolvedLocale)
                            ->orWhereNull('locale');
                    });
                })
                ->orderByRaw('CASE WHEN locale IS NULL OR locale = "" THEN 1 ELSE 0 END')
                ->orderByDesc('last_delivered_at')
                ->first();

            if ($legacyPublication) {
                $legacyPublication->forceFill([
                    'destination_id' => $destinationId,
                    'locale' => $resolvedLocale ?? $legacyPublication->locale,
                ])->save();

                return $legacyPublication;
            }
        }

        if ($resolvedLocale !== null) {
            $localeAgnosticCandidates = self::query()
                ->where('content_id', $contentId)
                ->where('provider', $provider)
                ->when($destinationId, fn ($candidateQuery) => $candidateQuery->where('destination_id', $destinationId))
                ->when(! $destinationId && $clientSiteId, function ($candidateQuery) use ($clientSiteId): void {
                    $candidateQuery->whereNull('destination_id')
                        ->where('client_site_id', $clientSiteId);
                })
                ->orderByRaw('CASE WHEN remote_id IS NULL OR remote_id = "" THEN 1 ELSE 0 END')
                ->orderByDesc('last_delivered_at')
                ->orderByDesc('updated_at')
                ->get();

            if ($localeAgnosticCandidates->count() === 1) {
                $fallbackPublication = $localeAgnosticCandidates->first();

                if ($fallbackPublication) {
                    $fallbackPublication->forceFill([
                        'locale' => $resolvedLocale,
                        'destination_id' => $destinationId ?: $fallbackPublication->destination_id,
                        'client_site_id' => $clientSiteId ?: $fallbackPublication->client_site_id,
                    ])->save();

                    return $fallbackPublication;
                }
            }
        }

        try {
            $publication = self::create([
                'content_id' => $contentId,
                'destination_id' => $destinationId,
                'client_site_id' => $clientSiteId,
                'locale' => $resolvedLocale,
                'provider' => $provider,
                'delivery_status' => self::STATUS_PENDING,
            ]);
        } catch (QueryException $exception) {
            if (! self::isUniqueConstraintViolation($exception)) {
                throw $exception;
            }

            $publication = self::query()
                ->where('content_id', $contentId)
                ->where('provider', $provider)
                ->when($destinationId, fn ($duplicateQuery) => $duplicateQuery->where('destination_id', $destinationId))
                ->when(! $destinationId && $clientSiteId, function ($duplicateQuery) use ($clientSiteId): void {
                    $duplicateQuery->whereNull('destination_id')
                        ->where('client_site_id', $clientSiteId);
                })
                ->when($resolvedLocale !== null, function ($duplicateQuery) use ($resolvedLocale): void {
                    $duplicateQuery->where(function ($localeQuery) use ($resolvedLocale): void {
                        $localeQuery->where('locale', $resolvedLocale)
                            ->orWhereNull('locale');
                    });
                })
                ->orderByRaw('CASE WHEN remote_id IS NULL OR remote_id = "" THEN 1 ELSE 0 END')
                ->orderByDesc('last_delivered_at')
                ->orderByDesc('updated_at')
                ->first();

            if (! $publication instanceof self) {
                throw $exception;
            }

            Log::notice('publication.mapping.duplicate_prevented', [
                'publication_id' => (string) $publication->id,
                'content_id' => $contentId,
                'destination_id' => $destinationId,
                'client_site_id' => $clientSiteId,
                'provider' => $provider,
                'locale' => $resolvedLocale,
            ]);
        }

        $duplicates = self::query()
            ->where('content_id', $contentId)
            ->where('provider', $provider)
            ->when($destinationId, fn ($duplicateQuery) => $duplicateQuery->where('destination_id', $destinationId))
            ->when(! $destinationId && $clientSiteId, function ($duplicateQuery) use ($clientSiteId): void {
                $duplicateQuery->whereNull('destination_id')
                    ->where('client_site_id', $clientSiteId);
            })
            ->when($resolvedLocale !== null, fn ($duplicateQuery) => $duplicateQuery->where('locale', $resolvedLocale))
            ->count();

        if ($duplicates > 1) {
            Log::warning('publication.mapping.duplicate_detected', [
                'content_id' => $contentId,
                'destination_id' => $destinationId,
                'client_site_id' => $clientSiteId,
                'provider' => $provider,
                'locale' => $resolvedLocale,
                'duplicate_count' => $duplicates,
            ]);
        }

        return $publication;
    }

    public static function normalizeLocale(mixed $locale): ?string
    {
        if ($locale instanceof SupportedLanguage) {
            $locale = $locale->value;
        }

        $normalized = strtolower(trim((string) $locale));

        return $normalized !== '' ? $normalized : null;
    }

    private static function resolveLocale(string $contentId, mixed $locale): ?string
    {
        $resolved = static::normalizeLocale($locale);
        if ($resolved !== null) {
            return $resolved;
        }

        return static::normalizeLocale(
            Content::query()->whereKey($contentId)->value('language')
        );
    }

    private static function isUniqueConstraintViolation(QueryException $exception): bool
    {
        $sqlState = (string) ($exception->errorInfo[0] ?? '');
        $driverCode = (string) ($exception->errorInfo[1] ?? '');

        return in_array($sqlState, ['23000', '23505'], true)
            || in_array($driverCode, ['1062', '19'], true);
    }

    /**
     * Get the WordPress post ID if this is a WordPress publication.
     * Convenience method for backwards compatibility.
     */
    public function getWpPostId(): ?string
    {
        if (! $this->isWordPress()) {
            return null;
        }

        $remoteId = trim((string) $this->remote_id);

        return $remoteId !== '' ? $remoteId : null;
    }

    // =========================================================================
    // Enum Accessors
    // =========================================================================

    /**
     * Get the delivery status as an enum.
     */
    public function deliveryStatusEnum(): PublicationDeliveryStatus
    {
        return PublicationDeliveryStatus::fromLegacyStatus($this->delivery_status);
    }

    /**
     * Get the remote publish status as an enum.
     */
    public function remotePublishStatusEnum(): ?RemotePublishStatus
    {
        if (! $this->remote_status) {
            return null;
        }

        return RemotePublishStatus::fromWordPressStatus($this->remote_status);
    }

    /**
     * Get the remote existence status as an enum.
     */
    public function remoteExistenceEnum(): RemoteExistenceStatus
    {
        if ($this->delivery_status === self::STATUS_MISSING_REMOTE) {
            return RemoteExistenceStatus::MISSING;
        }

        if ($this->remote_status === self::REMOTE_TRASH) {
            return RemoteExistenceStatus::TRASHED;
        }

        if ($this->hasRemoteId()) {
            return RemoteExistenceStatus::EXISTS;
        }

        return RemoteExistenceStatus::UNKNOWN;
    }

    // =========================================================================
    // Presentation Helpers
    // =========================================================================

    /**
     * Get a status presenter for this publication's content.
     */
    public function presenter(): ContentStatusPresenter
    {
        $this->loadMissing('content');

        return ContentStatusPresenter::for($this->content);
    }

    /**
     * Check if the publication needs attention (failed, missing, or out of sync).
     */
    public function needsAttention(): bool
    {
        return $this->deliveryStatusEnum()->needsAttention();
    }

    /**
     * Check if the remote resource is healthy (exists and accessible).
     */
    public function isRemoteHealthy(): bool
    {
        return $this->isDelivered() && $this->remoteExistenceEnum()->isHealthy();
    }

    /**
     * Get a human-readable summary of the current state.
     */
    public function statusSummary(): string
    {
        $delivery = $this->deliveryStatusEnum();
        $existence = $this->remoteExistenceEnum();

        if ($delivery->isSuccess() && $existence->isHealthy()) {
            return 'Successfully published to ' . $this->providerLabel();
        }

        if ($delivery->isFailure()) {
            return 'Delivery failed: ' . ($this->last_error_message ?? 'Unknown error');
        }

        if ($existence->isGone()) {
            return 'Remote resource is ' . strtolower($existence->label());
        }

        return $delivery->label();
    }

    /**
     * Get a human-readable label for the provider.
     */
    public function providerLabel(): string
    {
        return match ($this->provider) {
            self::PROVIDER_WORDPRESS => 'WordPress',
            self::PROVIDER_LARAVEL => 'Laravel',
            self::PROVIDER_API => 'API',
            self::PROVIDER_WEBHOOK => 'Webhook',
            default => ucfirst($this->provider ?? 'Unknown'),
        };
    }

    // =========================================================================
    // Remote ID Resolution (Phase 1 Refactor - This is the Canonical Source)
    // =========================================================================

    /**
     * Get the remote identifier, regardless of provider.
     *
     * This is the canonical method for getting the remote ID.
     * For WordPress publications, this returns the wp_post_id.
     * For other providers, this returns the provider-specific identifier.
     *
     * @return string|null The remote identifier
     */
    public function getRemoteId(): ?string
    {
        $remoteId = trim((string) $this->remote_id);

        return $remoteId !== '' ? $remoteId : null;
    }

    /**
     * Set the remote identifier after successful delivery.
     *
     * This is the canonical method for recording a remote ID.
     * Should be called instead of directly setting Content.wp_post_id.
     *
     * @param string $remoteId The remote identifier
     * @param string|null $remoteUrl The URL on the remote system
     * @param string|null $remoteType The content type on the remote (post, page, etc.)
     */
    public function setRemoteId(string $remoteId, ?string $remoteUrl = null, ?string $remoteType = null): void
    {
        $this->forceFill([
            'remote_id' => $remoteId,
            'remote_url' => $remoteUrl ?: $this->remote_url,
            'remote_type' => $remoteType ?: $this->remote_type,
        ])->save();
    }

    /**
     * Clear the remote identifier (e.g., when post is deleted remotely).
     *
     * Moves the current remote_id to previous_remote_ids in meta for audit trail.
     */
    public function clearRemoteId(): void
    {
        $previousId = $this->remote_id;

        if ($previousId) {
            $meta = is_array($this->meta) ? $this->meta : [];
            $previousIds = $meta['previous_remote_ids'] ?? [];
            if (! is_array($previousIds)) {
                $previousIds = [];
            }
            $previousIds[] = $previousId;
            $meta['previous_remote_ids'] = array_values(array_unique($previousIds));

            $this->forceFill([
                'remote_id' => null,
                'meta' => $meta,
            ])->save();
        }
    }

    /**
     * Sync the remote ID to legacy storage locations for backwards compatibility.
     *
     * This writes to Content.wp_post_id and ContentPublishTarget.wp_post_id
     * to maintain compatibility during the transition period.
     *
     * TODO: Phase 2 - Remove this method once all code uses ContentPublication
     */
    public function syncToLegacyStorage(): void
    {
        if (! $this->hasRemoteId() || ! $this->isWordPress()) {
            return;
        }

        $remoteId = $this->remote_id;

        // Sync to Content.wp_post_id (legacy)
        if ($this->content_id) {
            Content::where('id', $this->content_id)
                ->whereNull('wp_post_id')
                ->update(['wp_post_id' => $remoteId]);
        }

        // Sync to ContentPublishTarget.wp_post_id (legacy)
        if ($this->content_id && ($this->destination_id || $this->client_site_id)) {
            ContentPublishTarget::where('content_id', $this->content_id)
                ->where(function ($query) {
                    if ($this->destination_id) {
                        $query->where('content_destination_id', $this->destination_id);
                    } elseif ($this->client_site_id) {
                        $query->where('client_site_id', $this->client_site_id);
                    }
                })
                ->whereNull('wp_post_id')
                ->update(['wp_post_id' => $remoteId]);
        }
    }

    // =========================================================================
    // Delivery Status Helpers (Phase 1 Refactor - This is Authoritative)
    // =========================================================================

    /**
     * Check if this publication is successfully delivered (delivered or partial_success).
     */
    public function isSuccessfullyDelivered(): bool
    {
        return in_array($this->delivery_status, [
            self::STATUS_DELIVERED,
            'delivered',
            'partial_success',
        ], true);
    }

    /**
     * Check if this publication can be retried.
     */
    public function canRetry(): bool
    {
        return $this->deliveryStatusEnum()->canRetry();
    }

    /**
     * Sync delivery status to Content for backwards compatibility.
     *
     * TODO: Phase 2 - Consider removing this sync
     */
    public function syncStatusToContent(): void
    {
        if ($this->content_id) {
            Content::where('id', $this->content_id)
                ->update(['delivery_status' => $this->delivery_status]);
        }
    }
}
