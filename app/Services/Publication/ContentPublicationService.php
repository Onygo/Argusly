<?php

namespace App\Services\Publication;

use App\Contracts\Connectors\ConnectorContract;
use App\Enums\ContentDestinationType;
use App\Enums\PublicationDeliveryStatus;
use App\Enums\SupportedLanguage;
use App\Events\Agents\ContentPublished;
use App\Jobs\PublishContentJob;
use App\Jobs\PublishToWordPressJob;
use App\Models\AgenticActionRun;
use App\Models\ClientSite;
use App\Models\Content;
use App\Models\ContentDestination;
use App\Models\ContentPublication;
use App\Models\Draft;
use App\Services\Content\ContentLifecycleService;
use App\Support\Connectors\ConnectorRegistry;
use App\Support\Connectors\Results\HealthCheckResult;
use App\Support\Connectors\Results\PublicationResult;
use App\Support\Connectors\Results\VerificationResult;
use App\Support\Webhooks\WebhookDispatcher;
use InvalidArgumentException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Orchestration service for content publication.
 *
 * This service provides a unified interface for publishing content to
 * any destination type (WordPress, Laravel, API, etc.) using the
 * connector abstraction layer.
 *
 * ## Single Source of Truth
 *
 * This service is the ONLY authority for publication state changes.
 * All publish flows MUST go through this service. No exceptions.
 *
 * ContentPublication.delivery_status is authoritative for delivery state.
 * Content.publish_status is synchronized for backwards compatibility.
 *
 * ## Responsibilities
 *
 * - Resolve appropriate connector for destination type
 * - Manage ContentPublication records (canonical delivery tracking)
 * - Orchestrate publish/update/unpublish operations
 * - Synchronize Content.publish_status for backwards compatibility
 * - Handle verification and health checks
 * - Dispatch webhook events for publication lifecycle
 *
 * ## Usage
 *
 * ```php
 * $service = app(ContentPublicationService::class);
 *
 * // Mark content as publishing (before delivery)
 * $service->markPublishing($content);
 *
 * // Publish content
 * $result = $service->publish($content, $destination);
 *
 * // Update existing publication
 * $result = $service->update($content, $destination);
 *
 * // Verify publication still exists
 * $result = $service->verify($publication);
 *
 * // Check destination health
 * $result = $service->healthCheck($destination);
 * ```
 */
class ContentPublicationService
{
    public function __construct(
        private readonly ConnectorRegistry $connectorRegistry,
        private readonly PublicationDestinationDriverResolver $driverResolver,
        private readonly WebhookDispatcher $webhookDispatcher,
        private readonly PublicationLegacyCompatibilityService $legacyCompatibility,
        private readonly WordPressPublicationDestinationResolver $wordPressDestinationResolver,
        private readonly LaravelPublicationDestinationResolver $laravelDestinationResolver,
    ) {}

    /**
     * Mark content as "publishing" before delivery begins.
     *
     * This sets Content.publish_status to 'publishing' and clears any
     * previous publish errors. Should be called before publish() to
     * indicate delivery is in progress.
     */
    public function markPublishing(Content $content): void
    {
        $content->forceFill([
            'publish_status' => 'publishing',
            'publish_error' => null,
        ])->save();
    }

    /**
     * Mark content as failed before publication could begin.
     *
     * Use this for early failures where we can't create a publication record
     * (e.g., wrong site type, no destination found, no draft).
     */
    public function markFailed(Content $content, string $errorCode, string $errorMessage): void
    {
        $content->forceFill([
            'publish_status' => 'failed',
            'publish_error' => $errorMessage,
        ])->save();

        // Dispatch webhook for pre-publication failure
        $draft = $this->resolveDraft($content);
        $this->webhookDispatcher->publicationFailed($content, $errorMessage, $draft, 'pre_publish');
    }

    /**
     * Publish content to a destination.
     *
     * Creates or updates the content on the remote destination and records
     * the outcome in a ContentPublication record.
     *
     * @param array<string, mixed> $options Additional options (status, scheduled_at, etc.)
     */
    public function publish(
        Content $content,
        ContentDestination $destination,
        ?Draft $draft = null,
        array $options = [],
        ?ContentPublication $publication = null,
    ): PublicationResult {
        $connector = $this->resolveConnector($destination);

        // Resolve or create the publication record
        $publication ??= ContentPublication::resolveForDelivery(
            contentId: (string) $content->id,
            destinationId: (string) $destination->id,
            clientSiteId: $content->client_site_id,
            provider: $connector->type(),
            locale: $this->publicationLocaleForContent($content),
        );

        // Dispatch webhook for publication started
        $this->webhookDispatcher->publicationStarted($content, $draft ?? $this->resolveDraft($content), $connector->type());

        // Check capabilities
        if (! $connector->capabilities()->canPublish()) {
            return $this->handleFailure($publication, PublicationResult::failure(
                errorCode: 'CAPABILITY_NOT_SUPPORTED',
                errorMessage: "Connector '{$connector->type()}' does not support publishing",
                retryable: false,
            ), $content, $draft);
        }

        // Determine if this is a create or update
        $hasRemoteId = $publication->hasRemoteId();

        try {
            if ($hasRemoteId && $connector->capabilities()->canUpdate()) {
                $result = $connector->update($content, $destination, $publication, $draft, $options);
            } else {
                $result = $connector->publish($content, $destination, $publication, $draft, $options);
            }
        } catch (\Throwable $exception) {
            $result = PublicationResult::failure(
                errorCode: 'CONNECTOR_EXCEPTION',
                errorMessage: $exception->getMessage() !== '' ? $exception->getMessage() : 'Publication failed',
                retryable: true,
                meta: ['exception' => $exception::class],
            );
        }

        return $this->processResult($result, $publication, $content, $draft);
    }

    /**
     * @param  array<string,mixed>  $context
     * @return array{publication:?ContentPublication,queued:bool,skip_reason:?string}
     */
    public function dispatchPublication(Content $content, ?Draft $draft = null, array $context = []): array
    {
        $content->loadMissing('contentDestination', 'clientSite');

        $destinationType = $content->contentDestination?->resolvedType()
            ?? ContentDestinationType::fromNormalized($content->clientSite?->type);

        return match ($destinationType) {
            ContentDestinationType::WORDPRESS => $this->dispatchWordPressPublication($content, $draft, $context),
            ContentDestinationType::LARAVEL => $this->dispatchLaravelPublication($content, $draft, $context),
            ContentDestinationType::API => [
                'publication' => null,
                'queued' => false,
                'skip_reason' => 'unsupported_destination_type',
            ],
            default => [
                'publication' => null,
                'queued' => false,
                'skip_reason' => 'destination_unresolved',
            ],
        };
    }

    /**
     * @param  array<string,mixed>  $context
     * @return array{publication:?ContentPublication,queued:bool,skip_reason:?string}
     */
    public function dispatchWordPressPublication(Content $content, ?Draft $draft = null, array $context = []): array
    {
        $publication = $this->prepareWordPressPublication($content, $draft, $context);
        if (! $publication) {
            Log::warning('publication.wordpress.dispatch_skipped', array_merge($context, [
                'reason' => 'publication_unavailable',
                'content_id' => (string) $content->id,
                'draft_id' => (string) ($draft?->id ?? ''),
                'target_id' => (string) ($content->content_destination_id ?? ''),
            ]));

            return [
                'publication' => null,
                'queued' => false,
                'skip_reason' => 'publication_unavailable',
            ];
        }

        $status = PublicationDeliveryStatus::fromLegacyStatus((string) $publication->delivery_status);
        $content->refresh();
        $dispatchState = is_array(data_get($publication->meta, 'dispatch')) ? data_get($publication->meta, 'dispatch') : [];

        if ($status->isInProgress()) {
            Log::warning('publication.wordpress.dispatch_skipped', $this->publicationLogContext($publication, $content, array_merge($context, [
                'reason' => 'publication_already_processing',
                'draft_id' => (string) ($draft?->id ?? ''),
            ])));

            return [
                'publication' => $publication,
                'queued' => false,
                'skip_reason' => 'publication_already_processing',
            ];
        }

        if (
            $status === PublicationDeliveryStatus::PENDING
            && trim((string) data_get($dispatchState, 'queued_at')) !== ''
            && in_array((string) $content->publish_status, ['scheduled', 'publishing'], true)
        ) {
            Log::warning('publication.wordpress.dispatch_skipped', $this->publicationLogContext($publication, $content, array_merge($context, [
                'reason' => 'publication_already_queued',
                'draft_id' => (string) ($draft?->id ?? ''),
            ])));

            return [
                'publication' => $publication,
                'queued' => false,
                'skip_reason' => 'publication_already_queued',
            ];
        }

        $publication = DB::transaction(function () use ($publication, $content): ContentPublication {
            /** @var ContentPublication $locked */
            $locked = ContentPublication::query()->lockForUpdate()->findOrFail($publication->id);
            $meta = is_array($locked->meta) ? $locked->meta : [];
            $dispatch = is_array($meta['dispatch'] ?? null) ? $meta['dispatch'] : [];
            $dispatch['queued_at'] = now()->toIso8601String();
            $dispatch['count'] = (int) ($dispatch['count'] ?? 0) + 1;
            $meta['dispatch'] = $dispatch;

            $locked->forceFill([
                'delivery_status' => PublicationDeliveryStatus::PENDING->value,
                'last_error_at' => null,
                'last_error_code' => null,
                'last_error_message' => null,
                'meta' => $meta,
            ])->save();

            Content::query()->whereKey($content->id)->update([
                'publish_status' => 'publishing',
                'publish_error' => null,
            ]);

            return $locked->fresh(['destination']);
        });

        Log::info('publication.wordpress.job_dispatch', $this->publicationLogContext($publication, $content, array_merge($context, [
            'draft_id' => (string) ($draft?->id ?? ''),
            'force' => (bool) ($context['force'] ?? false),
        ])));

        PublishToWordPressJob::dispatch((string) $content->id, (string) $publication->id)
            ->afterCommit()
            ->onQueue('deliveries');

        return [
            'publication' => $publication,
            'queued' => true,
            'skip_reason' => null,
        ];
    }

    /**
     * @param  array<string,mixed>  $context
     * @return array{publication:?ContentPublication,queued:bool,skip_reason:?string}
     */
    public function dispatchLaravelPublication(Content $content, ?Draft $draft = null, array $context = []): array
    {
        $destination = $this->laravelDestinationResolver->resolveForContent($content);

        if (! $destination) {
            Log::warning('publication.laravel.dispatch_skipped', array_merge($context, [
                'reason' => 'destination_unavailable',
                'content_id' => (string) $content->id,
                'draft_id' => (string) ($draft?->id ?? ''),
                'target_id' => (string) ($content->content_destination_id ?? ''),
            ]));

            return [
                'publication' => null,
                'queued' => false,
                'skip_reason' => 'destination_unavailable',
            ];
        }

        return $this->dispatchQueuedPublication($content, $destination, $draft, array_merge($context, [
            'provider' => ContentPublication::PROVIDER_LARAVEL,
        ]));
    }

    /**
     * @param  array<string,mixed>  $context
     * @return array{
     *   content:Content,
     *   publication:?ContentPublication,
     *   queued:bool,
     *   skip_reason:?string,
     *   locale:string
     * }
     */
    public function publishVariantNow(Content $content, string $locale, array $context = []): array
    {
        $variant = $this->resolveLocaleVariant($content, $locale);
        $siteType = ClientSite::normalizeType((string) ($variant->clientSite?->type ?? ''));

        if ($siteType !== ClientSite::TYPE_LARAVEL) {
            throw new RuntimeException('Variant publish-now is only available for Laravel destinations.');
        }

        $draft = $this->resolveDraft($variant);
        if (! $draft) {
            throw new RuntimeException(sprintf(
                'No draft found for %s variant.',
                strtoupper($variant->localeCode())
            ));
        }

        if (! $this->laravelDestinationResolver->resolveForContent($variant)) {
            throw new RuntimeException(sprintf(
                'No Laravel destination is configured for %s variant.',
                strtoupper($variant->localeCode())
            ));
        }

        $dispatch = $this->dispatchLaravelPublication($variant, $draft, array_merge([
            'source' => 'app.content.publish-now.variant',
            'allow_stale_reclaim' => true,
            'allow_outdated_republish' => true,
        ], $context));

        return [
            'content' => $variant->fresh(['clientSite', 'contentDestination', 'drafts', 'publications']) ?? $variant,
            'publication' => $dispatch['publication'] ?? null,
            'queued' => (bool) ($dispatch['queued'] ?? false),
            'skip_reason' => $dispatch['skip_reason'] ?? null,
            'locale' => SupportedLanguage::fromStringOrDefault($variant->localeCode())->value,
        ];
    }

    /**
     * @param  array<string,mixed>  $context
     * @return array{publication:?ContentPublication,queued:bool,skip_reason:?string}
     */
    private function dispatchQueuedPublication(
        Content $content,
        ContentDestination $destination,
        ?Draft $draft = null,
        array $context = [],
    ): array {
        $publication = $this->prepareQueuedPublication($content, $destination);
        if (! $publication) {
            Log::warning('publication.driver.dispatch_skipped', array_merge($context, [
                'reason' => 'publication_unavailable',
                'content_id' => (string) $content->id,
                'draft_id' => (string) ($draft?->id ?? ''),
                'target_id' => (string) $destination->id,
                'destination_type' => $destination->typeValue(),
            ]));

            return [
                'publication' => null,
                'queued' => false,
                'skip_reason' => 'publication_unavailable',
            ];
        }

        $status = PublicationDeliveryStatus::fromLegacyStatus((string) $publication->delivery_status);
        $content->refresh();
        $dispatchState = is_array(data_get($publication->meta, 'dispatch')) ? data_get($publication->meta, 'dispatch') : [];
        $canReclaim = $this->canReclaimQueuedPublication($publication, $context);
        $canRepublishOutdated = $this->canRepublishOutdatedContent($content, $draft, $publication, $context);

        if ($status->isInProgress() && ! $canReclaim) {
            Log::warning('publication.driver.dispatch_skipped', $this->publicationLogContext($publication, $content, array_merge($context, [
                'reason' => 'publication_already_processing',
                'draft_id' => (string) ($draft?->id ?? ''),
            ])));

            return [
                'publication' => $publication,
                'queued' => false,
                'skip_reason' => 'publication_already_processing',
            ];
        }

        if (
            $status === PublicationDeliveryStatus::PENDING
            && trim((string) data_get($dispatchState, 'queued_at')) !== ''
            && in_array((string) $content->publish_status, ['scheduled', 'publishing'], true)
            && ! $canReclaim
        ) {
            Log::warning('publication.driver.dispatch_skipped', $this->publicationLogContext($publication, $content, array_merge($context, [
                'reason' => 'publication_already_queued',
                'draft_id' => (string) ($draft?->id ?? ''),
            ])));

            return [
                'publication' => $publication,
                'queued' => false,
                'skip_reason' => 'publication_already_queued',
            ];
        }

        $canRedeliverExistingRemote = (
            ($status->isSuccess() || $status->isPartialSuccess() || $status->isUncertain())
            && $this->publicationHasRemoteReference($publication)
        );

        if (! $status->canDeliver() && ! ($status->isInProgress() && $canReclaim) && ! $canRedeliverExistingRemote && ! $canRepublishOutdated) {
            Log::warning('publication.driver.dispatch_skipped', $this->publicationLogContext($publication, $content, array_merge($context, [
                'reason' => 'publication_status_not_deliverable',
                'draft_id' => (string) ($draft?->id ?? ''),
            ])));

            return [
                'publication' => $publication,
                'queued' => false,
                'skip_reason' => 'publication_status_not_deliverable',
            ];
        }

        $publication = DB::transaction(function () use ($publication, $content, $draft, $context): ContentPublication {
            /** @var ContentPublication $locked */
            $locked = ContentPublication::query()->lockForUpdate()->findOrFail($publication->id);
            /** @var Content $lockedContent */
            $lockedContent = Content::query()->lockForUpdate()->findOrFail($content->id);
            $meta = is_array($locked->meta) ? $locked->meta : [];
            $dispatch = is_array($meta['dispatch'] ?? null) ? $meta['dispatch'] : [];
            $recovered = $this->canReclaimQueuedPublication($locked, $context);

            $dispatch['queued_at'] = now()->toIso8601String();
            $dispatch['count'] = (int) ($dispatch['count'] ?? 0) + 1;
            $dispatch['source'] = (string) ($context['source'] ?? $dispatch['source'] ?? 'publication.dispatch');
            $meta['dispatch'] = $dispatch;

            $shouldResetDeliveryState = $recovered
                || $this->canRepublishOutdatedContent($lockedContent, $draft, $locked, $context)
                || (
                    ($locked->deliveryStatusEnum()->isSuccess() || $locked->deliveryStatusEnum()->isPartialSuccess() || $locked->deliveryStatusEnum()->isUncertain())
                    && $this->publicationHasRemoteReference($locked)
                );

            if ($shouldResetDeliveryState) {
                $meta['recovery'] = array_merge(is_array($meta['recovery'] ?? null) ? $meta['recovery'] : [], [
                    'requeued_at' => now()->toIso8601String(),
                    'reason' => match (true) {
                        $recovered => 'stale_publication_recovered',
                        $this->canRepublishOutdatedContent($lockedContent, $draft, $locked, $context) => 'outdated_publication_update',
                        default => 'existing_remote_update',
                    },
                ]);

                $locked->forceFill([
                    'delivery_status' => PublicationDeliveryStatus::PENDING->value,
                    'last_error_at' => null,
                    'last_error_code' => null,
                    'last_error_message' => null,
                    'meta' => $meta,
                ])->save();
            } else {
                $locked->forceFill(['meta' => $meta])->save();
            }

            $lockedContent->forceFill([
                'publish_status' => 'publishing',
                'publish_error' => null,
            ])->save();

            return $locked->fresh(['destination']);
        });

        Log::info('publication.driver.job_dispatch', $this->publicationLogContext($publication, $content, array_merge($context, [
            'draft_id' => (string) ($draft?->id ?? ''),
            'force' => (bool) ($context['force'] ?? false),
            'recovery_requeue' => $this->canReclaimQueuedPublication($publication, $context),
        ])));

        PublishContentJob::dispatch((string) $content->id, (string) $publication->id)
            ->afterCommit()
            ->onQueue('deliveries');

        return [
            'publication' => $publication,
            'queued' => true,
            'skip_reason' => null,
        ];
    }

    private function prepareQueuedPublication(Content $content, ContentDestination $destination): ?ContentPublication
    {
        return DB::transaction(function () use ($content, $destination): ?ContentPublication {
            /** @var Content|null $lockedContent */
            $lockedContent = Content::query()
                ->with('clientSite', 'contentDestination')
                ->lockForUpdate()
                ->find($content->id);

            if (! $lockedContent) {
                return null;
            }

            $connector = $this->resolveConnector($destination);

            $publication = ContentPublication::resolveForDelivery(
                contentId: (string) $lockedContent->id,
                destinationId: (string) $destination->id,
                clientSiteId: $lockedContent->client_site_id,
                provider: $connector->type(),
                locale: $this->publicationLocaleForContent($lockedContent),
            );

            return ContentPublication::query()
                ->with('destination')
                ->lockForUpdate()
                ->find($publication->id);
        });
    }

    /**
     * @param  array<string,mixed>  $context
     */
    public function prepareWordPressPublication(Content $content, ?Draft $draft = null, array $context = []): ?ContentPublication
    {
        $draft ??= $this->resolveDraft($content);

        return DB::transaction(function () use ($content, $draft, $context): ?ContentPublication {
            /** @var Content|null $lockedContent */
            $lockedContent = Content::query()
                ->with('clientSite.workspace')
                ->lockForUpdate()
                ->find($content->id);

            if (! $lockedContent) {
                return null;
            }

            $destination = $this->wordPressDestinationResolver->resolveForContent($lockedContent, $draft);

            $query = ContentPublication::query()
                ->where('content_id', (string) $lockedContent->id)
                ->where('provider', ContentPublication::PROVIDER_WORDPRESS)
                ->where(function ($localeQuery) use ($lockedContent): void {
                    $locale = $this->publicationLocaleForContent($lockedContent);

                    if ($locale === null) {
                        $localeQuery->whereNull('locale');

                        return;
                    }

                    $localeQuery->where('locale', $locale)
                        ->orWhereNull('locale');
                });

            if ($destination) {
                $query->where('destination_id', (string) $destination->id);
            } else {
                $query->whereNull('destination_id')
                    ->where('client_site_id', (string) $lockedContent->client_site_id);
            }

            $publication = $query
                ->orderByDesc('last_delivered_at')
                ->orderBy('created_at')
                ->lockForUpdate()
                ->first();

            if (! $publication) {
                $publication = ContentPublication::create([
                    'content_id' => (string) $lockedContent->id,
                    'destination_id' => $destination?->id ? (string) $destination->id : null,
                    'client_site_id' => $lockedContent->client_site_id,
                    'locale' => $this->publicationLocaleForContent($lockedContent),
                    'provider' => ContentPublication::PROVIDER_WORDPRESS,
                    'delivery_status' => ContentPublication::STATUS_PENDING,
                ]);

                Log::info('publication.wordpress.lookup_or_create', $this->publicationLogContext($publication, $lockedContent, array_merge($context, [
                    'draft_id' => (string) ($draft?->id ?? ''),
                    'result' => 'created',
                ])));
            } else {
                $publication->forceFill([
                    'destination_id' => $destination?->id ? (string) $destination->id : $publication->destination_id,
                    'client_site_id' => $lockedContent->client_site_id ?: $publication->client_site_id,
                    'locale' => $this->publicationLocaleForContent($lockedContent) ?? $publication->locale,
                ])->save();

                Log::info('publication.wordpress.lookup_or_create', $this->publicationLogContext($publication, $lockedContent, array_merge($context, [
                    'draft_id' => (string) ($draft?->id ?? ''),
                    'result' => 'reused',
                ])));
            }

            $publication = $this->legacyCompatibility->hydrateWordPressPublication($publication, $lockedContent, $draft);

            return $publication->fresh(['destination']);
        });
    }

    /**
     * @param  array<string,mixed>  $context
     * @return array{
     *   claimed:bool,
     *   invalid:bool,
     *   reason:string,
     *   publication:?ContentPublication,
     *   content:?Content
     * }
     */
    public function claimWordPressPublicationForDelivery(string $publicationId, array $context = []): array
    {
        Log::info('publication.wordpress.claim_attempt', array_merge($context, [
            'publication_id' => $publicationId,
        ]));

        return DB::transaction(function () use ($publicationId, $context): array {
            /** @var ContentPublication|null $publication */
            $publication = ContentPublication::query()
                ->with(['content.clientSite', 'destination'])
                ->lockForUpdate()
                ->find($publicationId);

            if (! $publication) {
                Log::error('publication.wordpress.claim_failed', array_merge($context, [
                    'publication_id' => $publicationId,
                    'reason' => 'publication_missing',
                ]));

                return [
                    'claimed' => false,
                    'invalid' => true,
                    'reason' => 'publication_missing',
                    'publication' => null,
                    'content' => null,
                ];
            }

            $content = $publication->content;
            if (! $content) {
                Log::error('publication.wordpress.claim_failed', $this->publicationLogContext($publication, null, array_merge($context, [
                    'reason' => 'content_missing',
                ])));

                return [
                    'claimed' => false,
                    'invalid' => true,
                    'reason' => 'content_missing',
                    'publication' => $publication,
                    'content' => null,
                ];
            }

            if ($content->scheduled_publish_at && $content->scheduled_publish_at->isFuture()) {
                Log::warning('publication.wordpress.claim_failed', $this->publicationLogContext($publication, $content, array_merge($context, [
                    'reason' => 'scheduled_for_future',
                ])));

                return [
                    'claimed' => false,
                    'invalid' => false,
                    'reason' => 'scheduled_for_future',
                    'publication' => $publication,
                    'content' => $content,
                ];
            }

            $deliveryStatus = PublicationDeliveryStatus::fromLegacyStatus((string) $publication->delivery_status);
            if ($deliveryStatus->isInProgress()) {
                Log::warning('publication.wordpress.claim_failed', $this->publicationLogContext($publication, $content, array_merge($context, [
                    'reason' => 'publication_already_claimed',
                ])));

                return [
                    'claimed' => false,
                    'invalid' => false,
                    'reason' => 'publication_already_claimed',
                    'publication' => $publication,
                    'content' => $content,
                ];
            }

            if (
                ($deliveryStatus->isSuccess() || $deliveryStatus->isPartialSuccess() || $deliveryStatus->isUncertain())
                && $this->publicationHasRemoteReference($publication)
            ) {
                Log::warning('publication.wordpress.claim_failed', $this->publicationLogContext($publication, $content, array_merge($context, [
                    'reason' => 'publication_already_processed',
                ])));

                return [
                    'claimed' => false,
                    'invalid' => false,
                    'reason' => 'publication_already_processed',
                    'publication' => $publication,
                    'content' => $content,
                ];
            }

            if (! $deliveryStatus->canDeliver()) {
                Log::error('publication.wordpress.claim_failed', $this->publicationLogContext($publication, $content, array_merge($context, [
                    'reason' => 'publication_status_invalid',
                ])));

                return [
                    'claimed' => false,
                    'invalid' => true,
                    'reason' => 'publication_status_invalid',
                    'publication' => $publication,
                    'content' => $content,
                ];
            }

            $meta = is_array($publication->meta) ? $publication->meta : [];
            $meta['claim'] = array_merge(is_array($meta['claim'] ?? null) ? $meta['claim'] : [], [
                'claimed_at' => now()->toIso8601String(),
                'job_content_id' => (string) ($context['job_content_id'] ?? $content->id),
                'previous_delivery_status' => $publication->delivery_status,
            ]);

            $publication->forceFill([
                'delivery_status' => PublicationDeliveryStatus::PROCESSING->value,
                'last_error_at' => null,
                'last_error_code' => null,
                'last_error_message' => null,
                'meta' => $meta,
            ])->save();

            $content->forceFill([
                'publish_status' => 'publishing',
                'publish_error' => null,
            ])->save();

            Log::info('publication.wordpress.claimed', $this->publicationLogContext($publication, $content, $context));

            return [
                'claimed' => true,
                'invalid' => false,
                'reason' => 'claimed',
                'publication' => $publication->fresh(['destination', 'content.clientSite']),
                'content' => $content->fresh(['clientSite']),
            ];
        });
    }

    /**
     * @param  array<string,mixed>  $context
     * @return array{
     *   claimed:bool,
     *   invalid:bool,
     *   reason:string,
     *   publication:?ContentPublication,
     *   content:?Content
     * }
     */
    public function claimPublicationForDelivery(string $publicationId, array $context = []): array
    {
        $publication = ContentPublication::query()->with('destination')->find($publicationId);

        if (! $publication) {
            return [
                'claimed' => false,
                'invalid' => true,
                'reason' => 'publication_missing',
                'publication' => null,
                'content' => null,
            ];
        }

        if ((string) $publication->provider === ContentPublication::PROVIDER_WORDPRESS) {
            return $this->claimWordPressPublicationForDelivery($publicationId, $context);
        }

        return DB::transaction(function () use ($publicationId, $context): array {
            /** @var ContentPublication|null $publication */
            $publication = ContentPublication::query()
                ->with(['content.clientSite', 'destination'])
                ->lockForUpdate()
                ->find($publicationId);

            if (! $publication) {
                return [
                    'claimed' => false,
                    'invalid' => true,
                    'reason' => 'publication_missing',
                    'publication' => null,
                    'content' => null,
                ];
            }

            $content = $publication->content;
            if (! $content) {
                Log::error('publication.driver.claim_failed', $this->publicationLogContext($publication, null, array_merge($context, [
                    'reason' => 'content_missing',
                ])));

                return [
                    'claimed' => false,
                    'invalid' => true,
                    'reason' => 'content_missing',
                    'publication' => $publication,
                    'content' => null,
                ];
            }

            if ($content->scheduled_publish_at && $content->scheduled_publish_at->isFuture()) {
                Log::warning('publication.driver.claim_failed', $this->publicationLogContext($publication, $content, array_merge($context, [
                    'reason' => 'scheduled_for_future',
                ])));

                return [
                    'claimed' => false,
                    'invalid' => false,
                    'reason' => 'scheduled_for_future',
                    'publication' => $publication,
                    'content' => $content,
                ];
            }

            $deliveryStatus = PublicationDeliveryStatus::fromLegacyStatus((string) $publication->delivery_status);
            $reclaimStale = $deliveryStatus->isInProgress() && $this->canReclaimQueuedPublication($publication, $context);

            if ($deliveryStatus->isInProgress() && ! $reclaimStale) {
                Log::warning('publication.driver.claim_failed', $this->publicationLogContext($publication, $content, array_merge($context, [
                    'reason' => 'publication_already_claimed',
                ])));

                return [
                    'claimed' => false,
                    'invalid' => false,
                    'reason' => 'publication_already_claimed',
                    'publication' => $publication,
                    'content' => $content,
                ];
            }

            if (
                ($deliveryStatus->isSuccess() || $deliveryStatus->isPartialSuccess() || $deliveryStatus->isUncertain())
                && $this->publicationHasRemoteReference($publication)
            ) {
                Log::warning('publication.driver.claim_failed', $this->publicationLogContext($publication, $content, array_merge($context, [
                    'reason' => 'publication_already_processed',
                ])));

                return [
                    'claimed' => false,
                    'invalid' => false,
                    'reason' => 'publication_already_processed',
                    'publication' => $publication,
                    'content' => $content,
                ];
            }

            if (! $deliveryStatus->canDeliver() && ! $reclaimStale) {
                Log::error('publication.driver.claim_failed', $this->publicationLogContext($publication, $content, array_merge($context, [
                    'reason' => 'publication_status_invalid',
                ])));

                return [
                    'claimed' => false,
                    'invalid' => true,
                    'reason' => 'publication_status_invalid',
                    'publication' => $publication,
                    'content' => $content,
                ];
            }

            $meta = is_array($publication->meta) ? $publication->meta : [];
            $meta['claim'] = array_merge(is_array($meta['claim'] ?? null) ? $meta['claim'] : [], [
                'claimed_at' => now()->toIso8601String(),
                'job_content_id' => (string) ($context['job_content_id'] ?? $content->id),
                'recovered_stale_claim' => $reclaimStale,
                'previous_delivery_status' => $publication->delivery_status,
            ]);

            if ($reclaimStale) {
                $meta['recovery'] = array_merge(is_array($meta['recovery'] ?? null) ? $meta['recovery'] : [], [
                    'reclaimed_at' => now()->toIso8601String(),
                    'reason' => 'stale_in_progress_claim',
                ]);
            }

            $publication->forceFill([
                'delivery_status' => PublicationDeliveryStatus::PROCESSING->value,
                'last_error_at' => null,
                'last_error_code' => null,
                'last_error_message' => null,
                'meta' => $meta,
            ])->save();

            $content->forceFill([
                'publish_status' => 'publishing',
                'publish_error' => null,
            ])->save();

            Log::info('publication.driver.claimed', $this->publicationLogContext($publication, $content, array_merge($context, [
                'recovered_stale_claim' => $reclaimStale,
            ])));

            return [
                'claimed' => true,
                'invalid' => false,
                'reason' => 'claimed',
                'publication' => $publication->fresh(['destination', 'content.clientSite']),
                'content' => $content->fresh(['clientSite']),
            ];
        });
    }

    /**
     * Update content on a destination.
     *
     * Updates existing content on the remote destination. Requires an
     * existing publication with a remote_id.
     *
     * @param array<string, mixed> $options Additional options
     */
    public function update(
        Content $content,
        ContentDestination $destination,
        ?Draft $draft = null,
        array $options = [],
    ): PublicationResult {
        $connector = $this->resolveConnector($destination);

        // Find existing publication
        $publication = ContentPublication::query()
            ->where('content_id', $content->id)
            ->where('destination_id', $destination->id)
            ->first();

        if (! $publication) {
            // No existing publication, delegate to publish
            return $this->publish($content, $destination, $draft, $options);
        }

        if (! $connector->capabilities()->canUpdate()) {
            return $this->handleFailure($publication, PublicationResult::failure(
                errorCode: 'CAPABILITY_NOT_SUPPORTED',
                errorMessage: "Connector '{$connector->type()}' does not support updates",
                retryable: false,
            ), $content, $draft);
        }

        try {
            $result = $connector->update($content, $destination, $publication, $draft, $options);
        } catch (\Throwable $exception) {
            $result = PublicationResult::failure(
                errorCode: 'CONNECTOR_EXCEPTION',
                errorMessage: $exception->getMessage() !== '' ? $exception->getMessage() : 'Publication failed',
                retryable: true,
                meta: ['exception' => $exception::class],
            );
        }

        return $this->processResult($result, $publication, $content, $draft);
    }

    /**
     * Unpublish (delete/trash) content from a destination.
     *
     * @param array<string, mixed> $options Additional options (soft_delete, etc.)
     */
    public function unpublish(
        Content $content,
        ContentDestination $destination,
        array $options = [],
    ): PublicationResult {
        $connector = $this->resolveConnector($destination);

        // Find existing publication
        $publication = ContentPublication::query()
            ->where('content_id', $content->id)
            ->where('destination_id', $destination->id)
            ->first();

        if (! $publication || ! $publication->hasRemoteId()) {
            return PublicationResult::skipped(
                reason: 'No existing publication to unpublish',
                meta: ['content_id' => $content->id, 'destination_id' => $destination->id],
            );
        }

        if (! $connector->capabilities()->canDelete()) {
            return $this->handleFailure($publication, PublicationResult::failure(
                errorCode: 'CAPABILITY_NOT_SUPPORTED',
                errorMessage: "Connector '{$connector->type()}' does not support unpublishing",
                retryable: false,
            ), $content, $this->resolveDraft($content));
        }

        try {
            $result = $connector->unpublish($content, $destination, $publication, $options);
        } catch (\Throwable $exception) {
            $result = PublicationResult::failure(
                errorCode: 'CONNECTOR_EXCEPTION',
                errorMessage: $exception->getMessage() !== '' ? $exception->getMessage() : 'Publication failed',
                retryable: true,
                meta: ['exception' => $exception::class],
            );
        }

        if ($result->isSuccess()) {
            $publication->forceFill([
                'remote_status' => 'deleted',
                'delivery_status' => ContentPublication::STATUS_DELIVERED,
                'last_delivered_at' => now(),
                'last_error_at' => null,
                'last_error_code' => null,
                'last_error_message' => null,
            ])->save();

            $this->legacyCompatibility->sync($publication);

            // Dispatch webhook for successful unpublish
            // Note: Using publication.succeeded as there's no specific unpublish event
        }

        return $result;
    }

    /**
     * Verify that a publication still exists on the remote destination.
     */
    public function verify(ContentPublication $publication): VerificationResult
    {
        $publication->loadMissing('destination');
        $destination = $publication->destination;

        if (! $destination) {
            return VerificationResult::error(
                errorCode: 'DESTINATION_NOT_FOUND',
                errorMessage: 'Publication destination not found',
            );
        }

        $connector = $this->resolveConnector($destination);

        if (! $connector->capabilities()->canVerify()) {
            return VerificationResult::unknown(
                reason: "Connector '{$connector->type()}' does not support verification",
                meta: ['publication_id' => $publication->id],
            );
        }

        $result = $connector->verify($publication, $destination);

        // Update publication based on verification result
        if ($result->isSuccess()) {
            $publication->markVerified();

            if ($result->isMissing()) {
                $publication->markMissingRemote($publication->remote_id);
            } elseif ($result->isTrashed()) {
                $publication->forceFill([
                    'remote_status' => 'trash',
                ])->save();
            } elseif ($result->doesExist()) {
                if ($result->remoteUrl) {
                    $publication->forceFill([
                        'remote_url' => $result->remoteUrl,
                    ])->save();
                }
            }

            $this->legacyCompatibility->sync($publication);

            // Dispatch webhook for verification
            $publication->loadMissing('content');
            if ($publication->content) {
                $this->webhookDispatcher->publicationVerified($publication->content, $publication);
            }
        }

        return $result;
    }

    /**
     * Check the health of a destination.
     */
    public function healthCheck(ContentDestination $destination): HealthCheckResult
    {
        $connector = $this->resolveConnector($destination);

        return $connector->healthCheck($destination);
    }

    public function resolveDestinationForContent(Content $content, ?Draft $draft = null): ContentDestination
    {
        $content->loadMissing('contentDestination', 'clientSite');

        $destinationType = $content->contentDestination
            ? $content->contentDestination->resolvedType()
            : ContentDestinationType::fromNormalized($content->clientSite?->type);

        if ($content->contentDestination && $destinationType === null) {
            throw new RuntimeException('Unknown destination type for this content.');
        }

        return match ($destinationType) {
            ContentDestinationType::WORDPRESS => $this->wordPressDestinationResolver->resolveForContent($content, $draft)
                ?? throw new RuntimeException('No WordPress publication destination could be resolved.'),
            ContentDestinationType::LARAVEL => $this->laravelDestinationResolver->resolveForContent($content)
                ?? throw new RuntimeException('No Laravel publication destination could be resolved.'),
            ContentDestinationType::API => throw new RuntimeException('API destinations do not support direct content publishing actions.'),
            default => throw new RuntimeException('Unknown destination type for this content.'),
        };
    }

    /**
     * Get the connector for a destination.
     */
    public function resolveConnector(ContentDestination $destination): ConnectorContract
    {
        try {
            return $this->connectorRegistry->resolveForDestination($destination);
        } catch (InvalidArgumentException $exception) {
            throw new RuntimeException($exception->getMessage(), previous: $exception);
        }
    }

    public function resolveDriverForDestination(ContentDestination $destination)
    {
        return $this->driverResolver->resolveForDestination($destination);
    }

    public function resolveDriverForPublication(ContentPublication $publication)
    {
        return $this->driverResolver->resolveForPublication($publication);
    }

    /**
     * Process publication result and update records.
     */
    private function processResult(
        PublicationResult $result,
        ContentPublication $publication,
        Content $content,
        ?Draft $draft,
    ): PublicationResult {
        if ($result->isSkipped()) {
            return $this->handleSkipped($result, $publication, $content, $draft);
        }

        if ($result->isSuccess()) {
            return $this->handleSuccess($result, $publication, $content, $draft);
        }

        return $this->handleFailure($publication, $result, $content, $draft);
    }

    /**
     * Handle successful publication.
     */
    private function handleSuccess(
        PublicationResult $result,
        ContentPublication $publication,
        Content $content,
        ?Draft $draft,
    ): PublicationResult {
        // Update publication record (canonical source of truth)
        $publication->forceFill([
            'remote_id' => $result->remoteId ?? $publication->remote_id,
            'remote_url' => $result->remoteUrl ?? $publication->remote_url,
            'remote_type' => $result->remoteType ?? $publication->remote_type,
            'remote_status' => $result->remoteStatus ?? 'published',
            'delivery_status' => (string) ($result->meta['delivery_status'] ?? ContentPublication::STATUS_DELIVERED),
            'payload_checksum' => (string) ($result->meta['payload_checksum'] ?? $publication->payload_checksum),
            'last_delivered_at' => now(),
            'last_error_at' => null,
            'last_error_code' => null,
            'last_error_message' => null,
            'meta' => array_merge(
                is_array($publication->meta) ? $publication->meta : [],
                [
                    'last_result' => $result->toArray(),
                    'last_publish_action' => $result->meta['sync_action'] ?? null,
                    'last_publish_reason' => $result->meta['sync_reason'] ?? null,
                ],
            ),
        ])->save();
        $this->storeAgenticConnectorFeedback($publication, $result, 'completed');

        $contentUpdates = [
            'publish_status' => 'published',
            'publish_error' => null,
            'scheduled_publish_at' => null,
            'published_url' => $result->remoteUrl ?: $content->published_url,
            'status' => 'published',
        ];

        if ($content->isTranslationVariant()) {
            $contentUpdates['translation_generated_at'] = now();
            $contentUpdates['translation_source_updated_at'] = $this->latestTranslationSourceTimestamp($content) ?? now();
        }

        // Update Content publish status (backwards compatibility)
        $content->forceFill($contentUpdates)->save();

        if ($draft && $publication->provider === ContentPublication::PROVIDER_LARAVEL) {
            $draft->forceFill([
                'status' => 'delivered',
                'delivery_status' => 'delivered',
                'delivery_last_error' => null,
                'delivered_at' => $draft->delivered_at ?: now(),
                'acked_at' => $draft->acked_at ?: now(),
            ])->save();
        }

        if ($draft instanceof Draft) {
            app(ContentLifecycleService::class)->synchronizePublishedSnapshotFromDraft($draft);
        }

        // Sync to legacy storage locations (temporary, remove in Phase 6)
        $this->legacyCompatibility->sync($publication);

        // Dispatch webhook for successful publication
        $this->webhookDispatcher->publicationSucceeded($content, $publication, $draft);

        ContentPublished::dispatch(
            contentId: (string) $content->id,
            draftId: $draft?->id ? (string) $draft->id : null,
            source: 'publication.'.(string) $publication->provider,
        );

        return $result;
    }

    /**
     * Handle skipped publication without mutating the canonical state incorrectly.
     */
    private function handleSkipped(
        PublicationResult $result,
        ContentPublication $publication,
        Content $content,
        ?Draft $draft = null,
    ): PublicationResult {
        $meta = is_array($publication->meta) ? $publication->meta : [];
        $meta['last_result'] = $result->toArray();
        $meta['last_skipped_at'] = now()->toIso8601String();

        $shouldPreservePublishedState = $this->publicationHasRemoteReference($publication)
            && in_array((string) ($result->meta['skip_reason'] ?? $result->errorMessage ?? ''), [
                'checksum_unchanged',
                'payload_unchanged',
                'existing_remote_current',
            ], true);

        $publicationUpdates = [
            'meta' => $meta,
        ];

        if ($shouldPreservePublishedState) {
            $publicationUpdates = array_merge($publicationUpdates, [
                'delivery_status' => ContentPublication::STATUS_DELIVERED,
                'last_error_at' => null,
                'last_error_code' => null,
                'last_error_message' => null,
            ]);
        }

        $publication->forceFill($publicationUpdates)->save();
        $this->storeAgenticConnectorFeedback($publication, $result, 'skipped');

        $freshPublication = $publication->fresh();

        if ($freshPublication && trim((string) $freshPublication->remote_id) !== '') {
            $this->legacyCompatibility->sync($freshPublication);
        }

        if ($shouldPreservePublishedState) {
            $content->forceFill([
                'publish_status' => 'published',
                'publish_error' => null,
                'scheduled_publish_at' => null,
                'published_url' => $freshPublication?->remote_url ?: $content->published_url,
                'status' => 'published',
            ])->save();

            if ($draft instanceof Draft) {
                app(ContentLifecycleService::class)->synchronizePublishedSnapshotFromDraft($draft);
            }
        }

        Log::info('publication.wordpress.result_skipped', $this->publicationLogContext($publication, $content, [
            'result' => $result->toArray(),
        ]));

        return $result;
    }

    /**
     * Handle failed publication.
     */
    private function handleFailure(
        ContentPublication $publication,
        PublicationResult $result,
        Content $content,
        ?Draft $draft = null,
    ): PublicationResult {
        $errorMessage = $result->errorMessage ?? 'Publication failed';

        // Update publication record (canonical source of truth)
        $publication->markFailed(
            errorCode: $result->errorCode ?? 'UNKNOWN_ERROR',
            errorMessage: $errorMessage,
        );
        $this->storeAgenticConnectorFeedback($publication, $result, 'failed');

        // Update Content publish status (backwards compatibility)
        $content->forceFill([
            'publish_status' => 'failed',
            'publish_error' => $errorMessage,
        ])->save();

        // Sync to legacy storage locations (temporary, remove in Phase 6)
        $this->legacyCompatibility->sync($publication);

        // Dispatch webhook for failed publication
        $this->webhookDispatcher->publicationFailed(
            $content,
            $errorMessage,
            $draft,
            $publication->provider ?? 'unknown',
        );

        return $result;
    }

    /**
     * Resolve draft for content.
     */
    private function publicationHasRemoteReference(ContentPublication $publication): bool
    {
        return trim((string) $publication->remote_id) !== ''
            || trim((string) $publication->remote_url) !== '';
    }

    private function storeAgenticConnectorFeedback(ContentPublication $publication, PublicationResult $result, string $status): void
    {
        $policy = (array) data_get($result->meta, 'policy', data_get($publication->meta, 'agentic_policy', []));
        $runId = trim((string) data_get($policy, 'action_run_id', ''));

        if ($runId === '') {
            return;
        }

        $run = AgenticActionRun::query()->find($runId);
        if (! $run) {
            return;
        }

        $feedback = [
            'accepted' => $result->isSuccess(),
            'rejected' => $result->isFailure(),
            'draft_created' => data_get($result->meta, 'connector_feedback.draft_created', false),
            'preview_ready' => data_get($result->meta, 'connector_feedback.preview_ready', false),
            'published' => $result->remoteStatus === 'published',
            'updated' => in_array($result->remoteStatus, ['updated', 'published'], true),
            'failed' => $result->isFailure(),
            'blocked' => data_get($result->meta, 'connector_feedback.blocked', false),
            'remote_content_id' => $result->remoteId,
            'remote_url' => $result->remoteUrl,
            'preview_url' => data_get($result->meta, 'connector_feedback.preview_url'),
            'warnings' => data_get($result->meta, 'connector_feedback.warnings', []),
            'errors' => $result->errorMessage ? [$result->errorMessage] : data_get($result->meta, 'connector_feedback.errors', []),
            'connector_version' => data_get($result->meta, 'connector_feedback.connector_version'),
            'processed_idempotency_key' => data_get($result->meta, 'idempotency_key', data_get($policy, 'idempotency_key')),
            'publication_id' => (string) $publication->id,
            'status' => $status,
        ];

        $run->forceFill([
            'status' => match ($status) {
                'completed', 'skipped' => AgenticActionRun::STATUS_COMPLETED,
                default => AgenticActionRun::STATUS_FAILED,
            },
            'output_snapshot' => array_replace_recursive(is_array($run->output_snapshot) ? $run->output_snapshot : [], [
                'connector_feedback' => $feedback,
            ]),
            'error_message' => $result->isFailure() ? $result->errorMessage : null,
        ])->save();
    }

    /**
     * @param  array<string,mixed>  $context
     */
    private function canRepublishOutdatedContent(
        Content $content,
        ?Draft $draft,
        ContentPublication $publication,
        array $context = [],
    ): bool {
        if (! (bool) ($context['allow_outdated_republish'] ?? false)) {
            return false;
        }

        if ((string) ($content->publish_status ?? '') !== 'published'
            && ! $publication->deliveryStatusEnum()->isSuccess()
        ) {
            return false;
        }

        if ($content->isTranslationOutdated()) {
            return true;
        }

        if (! $draft instanceof Draft) {
            return false;
        }

        $draftUpdatedAt = $draft->updated_at ?? $draft->created_at;
        if (! $draftUpdatedAt instanceof Carbon) {
            return false;
        }

        $publishedAt = $publication->last_delivered_at
            ?? $content->first_published_at
            ?? $content->updated_at;

        return ! $publishedAt instanceof Carbon || $draftUpdatedAt->gt($publishedAt);
    }

    private function latestTranslationSourceTimestamp(Content $content): ?Carbon
    {
        $source = $content->translationSourceContent;

        if (! $source instanceof Content) {
            return null;
        }

        $source->loadMissing('currentVersion');

        return collect([
            $source->currentVersion?->updated_at,
            $source->currentVersion?->created_at,
            $source->updated_at,
        ])->filter()
            ->sortDesc()
            ->first();
    }

    /**
     * @param  array<string,mixed>  $context
     */
    private function canReclaimQueuedPublication(ContentPublication $publication, array $context = []): bool
    {
        if (! (bool) ($context['allow_stale_reclaim'] ?? false)) {
            return false;
        }

        $staleAfterMinutes = max(1, (int) ($context['stale_after_minutes'] ?? 15));
        $staleAt = $this->publicationStaleAt($publication);

        return $staleAt instanceof Carbon
            && $staleAt->lte(now()->subMinutes($staleAfterMinutes));
    }

    private function publicationStaleAt(ContentPublication $publication): ?Carbon
    {
        $candidates = [
            $this->parsePublicationTimestamp(data_get($publication->meta, 'claim.claimed_at')),
            $this->parsePublicationTimestamp(data_get($publication->meta, 'dispatch.queued_at')),
            $publication->updated_at,
        ];

        foreach ($candidates as $candidate) {
            if ($candidate instanceof Carbon) {
                return $candidate;
            }
        }

        return null;
    }

    private function parsePublicationTimestamp(mixed $value): ?Carbon
    {
        if ($value instanceof Carbon) {
            return $value;
        }

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    private function resolveLocaleVariant(Content $content, string $locale): Content
    {
        $resolvedLocale = SupportedLanguage::fromStringOrDefault($locale)->value;
        $root = $content->localizationSource();
        $rawFamily = $root->localizationFamily();
        $duplicateCount = $rawFamily
            ->filter(fn (Content $variant): bool => $variant->localeCode() === $resolvedLocale)
            ->count();

        if ($duplicateCount > 1) {
            throw new RuntimeException(sprintf(
                'Locale %s has duplicate content variants in this family. Repair the family before publishing.',
                strtoupper($resolvedLocale)
            ));
        }

        $variant = $root->localizedVariantFor($resolvedLocale);
        if (! $variant) {
            throw new RuntimeException(sprintf(
                'No %s variant exists in this content family.',
                strtoupper($resolvedLocale)
            ));
        }

        $variant->loadMissing('clientSite', 'contentDestination', 'drafts', 'publications');

        return $variant;
    }

    private function resolveDraft(Content $content): ?Draft
    {
        $content->loadMissing([
            'drafts' => fn ($query) => $query->latest('created_at')->limit(1),
        ]);

        return $content->drafts->first();
    }

    /**
     * @param  array<string,mixed>  $extra
     * @return array<string,mixed>
     */
    private function publicationLogContext(?ContentPublication $publication, ?Content $content = null, array $extra = []): array
    {
        return array_merge([
            'publication_id' => (string) ($publication?->id ?? ''),
            'content_id' => (string) ($content?->id ?? $publication?->content_id ?? ''),
            'target_id' => (string) ($publication?->destination_id ?? ''),
            'client_site_id' => (string) ($content?->client_site_id ?? $publication?->client_site_id ?? ''),
            'publication_status' => (string) ($publication?->delivery_status ?? ''),
            'content_status' => (string) ($content?->publish_status ?? ''),
            'destination_type' => ContentDestinationType::normalize(
                $publication?->destination?->rawTypeValue()
                ?? $content?->contentDestination?->rawTypeValue()
                ?? $content?->clientSite?->type
                ?? $publication?->provider
            ),
            'locale' => (string) ContentPublication::normalizeLocale(
                $publication?->locale?->value
                ?? $publication?->getRawOriginal('locale')
                ?? $content?->language
                ?? ''
            ),
            'driver_class' => $publication?->destination
                ? $this->safeResolveDriverClass($publication->destination)
                : null,
        ], $extra);
    }

    private function publicationLocaleForContent(Content $content): ?string
    {
        return ContentPublication::normalizeLocale($content->language ?? null);
    }

    private function safeResolveDriverClass(ContentDestination $destination): ?string
    {
        try {
            return $this->resolveDriverForDestination($destination)::class;
        } catch (\Throwable) {
            return null;
        }
    }
}
