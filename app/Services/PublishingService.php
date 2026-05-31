<?php

namespace App\Services;

use App\Jobs\PublishContentAssetJob;
use App\Models\AnswerBlock;
use App\Models\ConnectorInstallation;
use App\Models\ContentAsset;
use App\Models\PublishingAction;
use App\Models\PublishingChannel;
use App\Models\User;
use App\Services\Signals\SignalManager;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

class PublishingService
{
    public function __construct(
        private readonly ActivityLogger $activity,
        private readonly SignalManager $signals,
        private readonly CreditService $credits,
        private readonly ApprovalService $approvals,
    ) {}

    /**
     * @param  array{action?: string|null, publishing_channel_id?: int|null, scheduled_at?: string|null}  $attributes
     */
    public function request(ContentAsset $contentAsset, User $user, array $attributes = []): PublishingAction
    {
        $action = $attributes['action'] ?? 'publish';
        $this->ensureAction($action);
        $this->approvals->assertApprovedForPublish($contentAsset, $user);

        $channel = $this->channelFor($contentAsset, $attributes['publishing_channel_id'] ?? $contentAsset->channel_id);

        $creditTransaction = $this->credits->consume(
            $contentAsset->account,
            $user,
            'publishing_action',
            'Publishing action requested.',
            $contentAsset,
            ['content_asset_id' => $contentAsset->id, 'action' => $action, 'publishing_channel_id' => $channel?->id],
        );

        $publishingAction = PublishingAction::query()->create([
            'account_id' => $contentAsset->account_id,
            'brand_id' => $contentAsset->brand_id,
            'content_asset_id' => $contentAsset->id,
            'publishing_channel_id' => $channel?->id,
            'language' => $contentAsset->language,
            'locale' => $contentAsset->locale,
            'action' => $action,
            'status' => 'queued',
            'scheduled_at' => $attributes['scheduled_at'] ?? null,
            'request_payload' => $this->connectorPayload($contentAsset, $channel, $action, $attributes['scheduled_at'] ?? null),
            'created_by' => $user->id,
        ]);

        $creditTransaction->update([
            'subject_type' => $publishingAction->getMorphClass(),
            'subject_id' => $publishingAction->id,
        ]);

        $connectorQueue = $this->usesConnectorQueue($channel);

        if (! $connectorQueue) {
            PublishContentAssetJob::dispatch($publishingAction->id);
        }

        app(OutboxService::class)->enqueue(
            $contentAsset->account,
            $contentAsset->brand,
            $this->outboxType($channel?->provider, $action),
            [
                'idempotency_key' => "publishing-action:{$publishingAction->id}",
                'publishing_action_id' => $publishingAction->id,
                'content_asset_id' => $contentAsset->id,
                'publishing_channel_id' => $channel?->id,
                'provider' => $channel?->provider ?? 'manual',
                'action' => $action,
                'language' => $contentAsset->language,
                'locale' => $contentAsset->locale,
                'market' => $contentAsset->brand->market,
                'canonical_url' => $contentAsset->canonical_url,
                'hreflang' => $this->hreflangPayload($contentAsset),
                'translated_from' => $this->translatedFromPayload($contentAsset),
                'translation_group_id' => $this->translationGroupId($contentAsset),
                'seo_metadata' => $contentAsset->seo_metadata ?? [],
                'connector_queue' => $connectorQueue,
                'content' => $this->contentPayload($contentAsset),
                'prepared_for_external_call' => true,
            ],
            $publishingAction->scheduled_at,
            dispatch: ! $connectorQueue,
        );

        if ($publishingAction->scheduled_at !== null) {
            app(MarketingCalendarService::class)->syncPublishingAction($publishingAction->refresh());
        }

        return $publishingAction;
    }

    public function process(PublishingAction $publishingAction): PublishingAction
    {
        if (! in_array($publishingAction->status, ['queued', 'processing'], true)) {
            return $publishingAction;
        }

        $publishingAction->forceFill(['status' => 'processing'])->save();

        $contentAsset = $publishingAction->contentAsset;
        $channel = $publishingAction->publishingChannel;
        $publishedAt = now();
        $externalId = $this->externalId($publishingAction);
        $externalUrl = $this->externalUrl($contentAsset, $publishingAction, $externalId);

        $publishingAction->forceFill([
            'status' => 'completed',
            'published_at' => in_array($publishingAction->action, ['publish', 'update'], true) ? $publishedAt : null,
            'external_id' => $externalId,
            'external_url' => $externalUrl,
            'response_payload' => [
                'fake' => true,
                'provider' => $channel?->provider ?? 'manual',
                'action' => $publishingAction->action,
                'message' => 'Fake publishing provider completed the action.',
            ],
        ])->save();

        $this->updateContentAsset($contentAsset, $publishingAction, $publishedAt);
        $this->logCompleted($publishingAction);

        if (in_array($publishingAction->action, ['publish', 'update'], true)) {
            $this->signals->produce($contentAsset);
        }

        if ($publishingAction->action === 'publish') {
            app(DomainEventService::class)->recordForSubject('ContentAssetPublished', $contentAsset, $publishingAction->creator, [
                'publishing_action_id' => $publishingAction->id,
                'publishing_channel_id' => $publishingAction->publishing_channel_id,
                'external_url' => $publishingAction->external_url,
                'published_at' => $contentAsset->published_at?->toDateTimeString(),
            ], $publishedAt);
        }

        $this->signals->produce($publishingAction);

        return $publishingAction->refresh();
    }

    /**
     * @param  array{external_id?: string|null, external_url?: string|null, published_at?: mixed, response?: array<string, mixed>|null}  $attributes
     */
    public function completeFromConnector(PublishingAction $publishingAction, array $attributes = [], ?ConnectorInstallation $installation = null): PublishingAction
    {
        if (! in_array($publishingAction->status, ['queued', 'processing'], true)) {
            return $publishingAction;
        }

        $contentAsset = $publishingAction->contentAsset;
        $completedAt = isset($attributes['published_at']) ? Carbon::parse($attributes['published_at']) : now();

        $publishingAction->forceFill([
            'status' => 'completed',
            'published_at' => in_array($publishingAction->action, ['publish', 'update'], true) ? $completedAt : null,
            'external_id' => $attributes['external_id'] ?? $publishingAction->external_id,
            'external_url' => $attributes['external_url'] ?? $publishingAction->external_url,
            'response_payload' => $this->connectorResponsePayload($attributes),
            'error_message' => null,
        ])->save();

        $this->updateContentAsset($contentAsset, $publishingAction, $completedAt);

        $canonicalUrl = $attributes['external_canonical_url'] ?? $attributes['external_url'] ?? null;

        if ($canonicalUrl && in_array($publishingAction->action, ['publish', 'update'], true)) {
            $contentAsset->forceFill(['canonical_url' => $canonicalUrl])->save();
        }

        $this->logCompleted($publishingAction);

        if (in_array($publishingAction->action, ['publish', 'update'], true)) {
            app(DomainEventService::class)->recordForSubject('ContentAssetPublished', $contentAsset->refresh(), null, [
                'connector_installation_id' => $installation?->id,
                'publishing_action_id' => $publishingAction->id,
                'publishing_channel_id' => $publishingAction->publishing_channel_id,
                'external_url' => $publishingAction->external_url,
                'external_canonical_url' => $attributes['external_canonical_url'] ?? null,
                'language' => $attributes['language'] ?? $publishingAction->language,
                'locale' => $attributes['locale'] ?? $publishingAction->locale,
                'external_locale' => $attributes['external_locale'] ?? null,
                'external_translation_group' => $attributes['external_translation_group'] ?? null,
                'published_at' => $contentAsset->published_at?->toDateTimeString(),
            ], $completedAt);

            $this->signals->produce($contentAsset);
        }

        $this->signals->produce($publishingAction);

        return $publishingAction->refresh();
    }

    /**
     * @param  array{message: string, response?: array<string, mixed>|null}  $attributes
     */
    public function failFromConnector(PublishingAction $publishingAction, array $attributes, ?ConnectorInstallation $installation = null): PublishingAction
    {
        if (! in_array($publishingAction->status, ['queued', 'processing'], true)) {
            return $publishingAction;
        }

        $publishingAction->forceFill([
            'status' => 'failed',
            'response_payload' => $this->connectorResponsePayload($attributes),
            'error_message' => $attributes['message'],
        ])->save();

        $contentAsset = $publishingAction->contentAsset;
        $contentAsset->forceFill(['status' => 'failed'])->save();

        app(DomainEventService::class)->recordForSubject('ContentPublishingFailed', $publishingAction->refresh(), null, [
            'connector_installation_id' => $installation?->id,
            'content_asset_id' => $publishingAction->content_asset_id,
            'publishing_action_id' => $publishingAction->id,
            'publishing_channel_id' => $publishingAction->publishing_channel_id,
            'action' => $publishingAction->action,
            'language' => $attributes['language'] ?? $publishingAction->language,
            'locale' => $attributes['locale'] ?? $publishingAction->locale,
            'external_locale' => $attributes['external_locale'] ?? null,
            'external_translation_group' => $attributes['external_translation_group'] ?? null,
            'external_canonical_url' => $attributes['external_canonical_url'] ?? null,
            'error_message' => $publishingAction->error_message,
        ]);

        $this->signals->produce($publishingAction);

        return $publishingAction->refresh();
    }

    private function ensureAction(string $action): void
    {
        if (! in_array($action, PublishingAction::ACTIONS, true)) {
            throw new InvalidArgumentException("Invalid publishing action [{$action}].");
        }
    }

    private function channelFor(ContentAsset $contentAsset, ?int $channelId): ?PublishingChannel
    {
        if ($channelId === null) {
            return null;
        }

        $channel = PublishingChannel::query()->findOrFail($channelId);

        if ($channel->account_id !== $contentAsset->account_id || $channel->brand_id !== $contentAsset->brand_id) {
            throw new InvalidArgumentException('Publishing channel must belong to the same account and brand as the content asset.');
        }

        $this->assertPublishableConnector($channel);

        return $channel;
    }

    private function assertPublishableConnector(PublishingChannel $channel): void
    {
        if (! in_array($channel->provider, array_keys(config('connectors.types', [])), true)) {
            return;
        }

        $installation = $this->activeConnectorFor($channel);

        if (! $installation) {
            throw new InvalidArgumentException('Publishing channel has no active connector installation.');
        }

        if (! in_array('publish_content', $installation->enabled_capabilities ?? [], true)) {
            throw new InvalidArgumentException('Connector installation does not have the publish_content capability.');
        }
    }

    private function updateContentAsset(ContentAsset $contentAsset, PublishingAction $publishingAction, Carbon $publishedAt): void
    {
        match ($publishingAction->action) {
            'publish', 'update' => $contentAsset->forceFill([
                'status' => 'published',
                'published_at' => $contentAsset->published_at ?? $publishedAt,
                'first_published_at' => $contentAsset->first_published_at ?? $publishedAt,
            ])->save(),
            'schedule' => $contentAsset->forceFill(['status' => 'scheduled'])->save(),
            'unpublish' => $contentAsset->forceFill(['status' => 'archived'])->save(),
            default => null,
        };
    }

    private function logCompleted(PublishingAction $publishingAction): void
    {
        $this->activity->log(
            event: 'content.publishing.completed',
            description: "Publishing action {$publishingAction->action} completed for {$publishingAction->contentAsset->title}.",
            account: $publishingAction->account,
            brand: $publishingAction->brand,
            user: $publishingAction->creator,
            subject: $publishingAction,
            properties: [
                'content_asset_id' => $publishingAction->content_asset_id,
                'publishing_channel_id' => $publishingAction->publishing_channel_id,
                'action' => $publishingAction->action,
                'external_url' => $publishingAction->external_url,
            ],
        );
    }

    private function externalId(PublishingAction $publishingAction): string
    {
        return 'fake-'.$publishingAction->action.'-'.$publishingAction->uuid;
    }

    private function externalUrl(ContentAsset $contentAsset, PublishingAction $publishingAction, string $externalId): string
    {
        $base = $contentAsset->canonical_url
            ?: $publishingAction->publishingChannel?->property?->url
            ?: $contentAsset->brand->website_url
            ?: ($contentAsset->brand->domain ? 'https://'.$contentAsset->brand->domain : 'https://argusly.local');

        return rtrim($base, '/').'/fake-published/'.$externalId;
    }

    private function outboxType(?string $provider, string $action): string
    {
        return match ($provider) {
            'wordpress' => 'wordpress_publishing',
            'laravel' => 'laravel_connector',
            'linkedin' => 'linkedin_publishing',
            'email' => 'email_newsletter_dispatch',
            default => 'external_webhook',
        };
    }

    private function usesConnectorQueue(?PublishingChannel $channel): bool
    {
        if (! $channel || ! in_array($channel->provider, array_keys(config('connectors.types', [])), true)) {
            return false;
        }

        return $this->activeConnectorFor($channel) !== null;
    }

    private function activeConnectorFor(PublishingChannel $channel): ?ConnectorInstallation
    {
        if (! $channel->connector_installation_id) {
            return null;
        }

        return ConnectorInstallation::query()
            ->where('account_id', $channel->account_id)
            ->where('brand_id', $channel->brand_id)
            ->whereKey($channel->connector_installation_id)
            ->where('channel_id', $channel->id)
            ->where('status', 'active')
            ->whereNull('revoked_at')
            ->first();
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function connectorResponsePayload(array $attributes): array
    {
        return [
            'response' => $attributes['response'] ?? [],
            'language' => $attributes['language'] ?? null,
            'locale' => $attributes['locale'] ?? null,
            'external_locale' => $attributes['external_locale'] ?? null,
            'external_translation_group' => $attributes['external_translation_group'] ?? null,
            'external_canonical_url' => $attributes['external_canonical_url'] ?? null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function connectorPayload(ContentAsset $contentAsset, ?PublishingChannel $channel, string $action, mixed $scheduledAt = null): array
    {
        return [
            'action' => $action,
            'scheduled_at' => $scheduledAt,
            'language' => $contentAsset->language,
            'locale' => $contentAsset->locale,
            'market' => $contentAsset->brand->market,
            'canonical_url' => $contentAsset->canonical_url,
            'hreflang' => $this->hreflangPayload($contentAsset),
            'translated_from' => $this->translatedFromPayload($contentAsset),
            'translation_group_id' => $this->translationGroupId($contentAsset),
            'content' => $this->contentPayload($contentAsset),
            'publishing_channel' => $channel ? [
                'id' => $channel->id,
                'provider' => $channel->provider,
                'name' => $channel->name,
                'language' => $contentAsset->language,
                'locale' => $contentAsset->locale,
                'connector_installation_id' => $channel->connector_installation_id,
            ] : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function contentPayload(ContentAsset $contentAsset): array
    {
        $contentAsset->loadMissing('answerBlocks');

        return [
            'id' => $contentAsset->id,
            'uuid' => $contentAsset->uuid,
            'type' => $contentAsset->type,
            'status' => $contentAsset->status,
            'title' => $contentAsset->title,
            'slug' => $contentAsset->slug,
            'language' => $contentAsset->language,
            'locale' => $contentAsset->locale,
            'market' => $contentAsset->brand->market,
            'canonical_url' => $contentAsset->canonical_url,
            'hreflang' => $this->hreflangPayload($contentAsset),
            'translated_from' => $this->translatedFromPayload($contentAsset),
            'translation_group_id' => $this->translationGroupId($contentAsset),
            'excerpt' => $contentAsset->excerpt,
            'body' => $contentAsset->body,
            'metadata' => $contentAsset->metadata ?? [],
            'seo_metadata' => $contentAsset->seo_metadata ?? [],
            'answer_blocks' => $contentAsset->answerBlocks
                ->sortBy('position')
                ->values()
                ->map(fn (AnswerBlock $block) => [
                    'id' => $block->id,
                    'uuid' => $block->uuid,
                    'type' => $block->type,
                    'status' => $block->status,
                    'question' => $block->question,
                    'answer' => $block->answer,
                    'language' => $block->language,
                    'position' => $block->position,
                    'metadata' => $block->metadata ?? [],
                ])
                ->all(),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function hreflangPayload(ContentAsset $contentAsset): array
    {
        $contentAsset->loadMissing([
            'sourceTranslations.translatedContentAsset',
            'translatedFrom.sourceContentAsset.sourceTranslations.translatedContentAsset',
        ]);

        $source = $contentAsset->translatedFrom->first()?->sourceContentAsset ?? $contentAsset;
        $assets = collect([$source])
            ->merge($source->sourceTranslations->pluck('translatedContentAsset')->filter())
            ->unique('id')
            ->values();

        return $assets
            ->map(fn (ContentAsset $asset) => [
                'content_id' => $asset->id,
                'content_uuid' => $asset->uuid,
                'language' => $asset->language,
                'locale' => $asset->locale,
                'url' => $asset->canonical_url,
            ])
            ->all();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function translatedFromPayload(ContentAsset $contentAsset): ?array
    {
        $contentAsset->loadMissing('translatedFrom.sourceContentAsset');
        $translation = $contentAsset->translatedFrom->first();
        $source = $translation?->sourceContentAsset;

        if (! $translation || ! $source) {
            return null;
        }

        return [
            'content_id' => $source->id,
            'content_uuid' => $source->uuid,
            'language' => $translation->source_language,
            'locale' => $translation->source_locale,
        ];
    }

    private function translationGroupId(ContentAsset $contentAsset): ?int
    {
        $contentAsset->loadMissing('sourceTranslations', 'translatedFrom');

        if ($contentAsset->translatedFrom->isNotEmpty()) {
            return $contentAsset->translatedFrom->first()->source_content_asset_id;
        }

        return $contentAsset->sourceTranslations->isNotEmpty() ? $contentAsset->id : null;
    }
}
