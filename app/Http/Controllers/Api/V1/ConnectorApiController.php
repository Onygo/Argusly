<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Brand;
use App\Models\ConnectorInstallation;
use App\Models\ConnectorLog;
use App\Models\ContentAsset;
use App\Models\DomainEvent;
use App\Models\PublishingAction;
use App\Models\PublishingChannel;
use App\Services\ActivityLogger;
use App\Services\DomainEventService;
use App\Services\PublishingService;
use App\Services\Signals\SignalManager;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ConnectorApiController extends Controller
{
    public function manifest(Request $request): JsonResponse
    {
        $installation = $this->installation($request);
        $installation->loadMissing('manifest', 'version');

        return response()->json([
            'data' => [
                'connector' => $this->connectorPayload($installation),
                'manifest' => [
                    'key' => $installation->manifest->key,
                    'type' => $installation->manifest->type,
                    'name' => $installation->manifest->name,
                    'description' => $installation->manifest->description,
                    'version' => $installation->version->version,
                    'api_base_path' => '/api/v1',
                ],
            ],
        ]);
    }

    public function register(Request $request): JsonResponse
    {
        $installation = $this->installation($request);

        $validated = $request->validate([
            'endpoint_url' => ['nullable', 'url', 'max:255'],
            'external_connector_id' => ['nullable', 'string', 'max:255'],
            'connector_version' => ['nullable', 'string', 'max:255'],
            'capabilities' => ['nullable', 'array'],
            'capabilities.*' => ['string', Rule::in(config('connectors.capabilities', []))],
            'metadata' => ['nullable', 'array'],
        ]);

        $installation->update([
            'status' => 'active',
            'endpoint_url' => $validated['endpoint_url'] ?? $installation->endpoint_url,
            'enabled_capabilities' => $this->allowedCapabilities($installation, $validated['capabilities'] ?? $installation->enabled_capabilities ?? []),
            'settings' => array_filter([
                ...($installation->settings ?? []),
                'external_connector_id' => $validated['external_connector_id'] ?? null,
                'reported_connector_version' => $validated['connector_version'] ?? null,
            ], fn ($value) => $value !== null),
            'metadata' => array_filter([
                ...($installation->metadata ?? []),
                'registration' => $validated['metadata'] ?? null,
                'registered_at' => now()->toDateTimeString(),
            ], fn ($value) => $value !== null),
        ]);

        $this->log($installation, 'connector.api_registered', 'info', 'registered', 'Connector registered through API.');

        return response()->json([
            'data' => [
                'connector' => $this->connectorPayload($installation->refresh()),
            ],
        ]);
    }

    public function health(Request $request): JsonResponse
    {
        $installation = $this->installation($request);

        $validated = $request->validate([
            'status' => ['required', 'string', Rule::in(['ok', 'degraded', 'failed'])],
            'message' => ['nullable', 'string', 'max:1000'],
            'checked_at' => ['nullable', 'date'],
            'metrics' => ['nullable', 'array'],
        ]);

        $installation->update([
            'status' => $validated['status'] === 'failed' ? 'unhealthy' : 'active',
            'last_health_check' => [
                'status' => $validated['status'],
                'message' => $validated['message'] ?? null,
                'metrics' => $validated['metrics'] ?? [],
                'checked_at' => $validated['checked_at'] ?? now()->toDateTimeString(),
            ],
            'last_health_checked_at' => now(),
        ]);

        $this->log($installation, 'connector.health_checked', $validated['status'] === 'failed' ? 'warning' : 'info', $validated['status'], $validated['message'] ?? null);

        return response()->json([
            'data' => [
                'status' => $validated['status'],
                'connector_status' => $installation->status,
                'checked_at' => $installation->last_health_checked_at?->toISOString(),
            ],
        ]);
    }

    public function capabilities(Request $request): JsonResponse
    {
        $installation = $this->installation($request);

        return response()->json([
            'data' => [
                'connector' => $this->connectorPayload($installation),
                'capabilities' => $installation->enabled_capabilities ?? [],
                'available_capabilities' => $this->availableCapabilities($installation),
            ],
        ]);
    }

    public const CONNECTOR_EVENT_TYPES = [
        'content.created',
        'content.updated',
        'content.deleted',
        'content.published',
        'content.failed',
        'health.ok',
        'health.warning',
        'health.failed',
        'taxonomy.synced',
        'author.synced',
        'media.uploaded',
    ];

    public function events(
        Request $request,
        DomainEventService $events,
        ActivityLogger $activity,
        SignalManager $signals,
    ): JsonResponse {
        $installation = $this->installation($request);

        $validated = $request->validate([
            'type' => ['required', 'string', Rule::in(self::CONNECTOR_EVENT_TYPES)],
            'status' => ['nullable', 'string', 'max:255'],
            'message' => ['nullable', 'string', 'max:1000'],
            'payload' => ['required', 'array'],
            'idempotency_key' => ['nullable', 'string', 'max:255'],
            'occurred_at' => ['nullable', 'date'],
        ]);

        $payloadErrors = $this->validateConnectorEventPayload($validated['type'], $validated['payload'], $validated['message'] ?? null);

        if ($payloadErrors !== []) {
            return response()->json([
                'error' => [
                    'code' => 'invalid_connector_event_payload',
                    'message' => 'Connector event payload is invalid.',
                    'details' => $payloadErrors,
                ],
            ], 422);
        }

        $idempotencyKey = $validated['idempotency_key'] ?? null;
        $existing = $idempotencyKey ? DomainEvent::query()
            ->where('account_id', $this->account($request)->id)
            ->where('event_type', 'ConnectorEventReceived')
            ->where('payload->connector_installation_id', $installation->id)
            ->where('payload->idempotency_key', $idempotencyKey)
            ->first() : null;

        if ($existing) {
            $this->log($installation, 'connector.event_duplicate', 'info', $validated['type'], $validated['message'] ?? $validated['type'], [
                'domain_event_id' => $existing->id,
                'idempotency_key' => $idempotencyKey,
            ]);

            return response()->json([
                'data' => [
                    'accepted' => true,
                    'duplicate' => true,
                    'domain_event_id' => $existing->id,
                    'domain_event_uuid' => $existing->uuid,
                ],
            ], 202);
        }

        $eventPayload = [
            'connector_installation_id' => $installation->id,
            'publishing_channel_id' => $this->channel($request)->id,
            'connector_event_type' => $validated['type'],
            'status' => $validated['status'] ?? null,
            'message' => $validated['message'] ?? null,
            'language' => $validated['payload']['language'] ?? null,
            'locale' => $validated['payload']['locale'] ?? null,
            'external_locale' => $validated['payload']['external_locale'] ?? null,
            'external_translation_group' => $validated['payload']['external_translation_group'] ?? null,
            'external_canonical_url' => $validated['payload']['external_canonical_url'] ?? null,
            'payload' => $validated['payload'],
            'idempotency_key' => $idempotencyKey,
        ];

        $event = $events->record(
            'ConnectorEventReceived',
            $this->account($request),
            $this->brand($request),
            $installation,
            null,
            $eventPayload,
            $validated['occurred_at'] ?? now(),
        );

        $activity->log(
            event: 'connector.event.received',
            description: "Connector event {$validated['type']} received.",
            account: $this->account($request),
            brand: $this->brand($request),
            subject: $installation,
            properties: [
                'domain_event_uuid' => $event->uuid,
                'connector_installation_id' => $installation->id,
                'connector_event_type' => $validated['type'],
                'idempotency_key' => $idempotencyKey,
            ],
            request: $request,
        );

        if ($this->shouldSignalConnectorEvent($validated['type'])) {
            $signals->record($this->account($request), [
                'source' => 'connector_event',
                'type' => $validated['type'] === 'content.failed' ? 'publishing_failed' : 'integration_event',
                'category' => $validated['type'] === 'content.failed' ? 'system' : 'integration',
                'priority' => str_ends_with($validated['type'], '.failed') ? 'critical' : 'high',
                'dedupe_key' => "connector-event:{$event->uuid}:signal",
                'title' => 'Connector '.str($validated['type'])->replace('.', ' ')->headline(),
                'summary' => $validated['message'] ?? 'A connector reported a warning or error event.',
                'impact_score' => str_ends_with($validated['type'], '.failed') ? 90 : 70,
                'confidence_score' => 96,
                'status' => 'new',
                'recommended_action' => 'Review connector health, recent publishing activity and channel configuration.',
                'payload' => $eventPayload,
            ], $this->brand($request), generateRecommendations: false);
        }

        $this->log($installation, 'connector.event_received', 'info', $validated['status'] ?? 'received', $validated['message'] ?? $validated['type'], [
            'domain_event_id' => $event->id,
            'domain_event_uuid' => $event->uuid,
            'type' => $validated['type'],
            'idempotency_key' => $idempotencyKey,
        ]);

        return response()->json([
            'data' => [
                'accepted' => true,
                'duplicate' => false,
                'domain_event_id' => $event->id,
                'domain_event_uuid' => $event->uuid,
            ],
        ], 202);
    }

    public function pendingContent(Request $request): JsonResponse
    {
        $actions = PublishingAction::query()
            ->where('account_id', $this->account($request)->id)
            ->where('brand_id', $this->brand($request)->id)
            ->where('publishing_channel_id', $this->channel($request)->id)
            ->whereIn('status', ['queued', 'processing'])
            ->with([
                'brand',
                'contentAsset.answerBlocks',
                'contentAsset.brand',
                'contentAsset.sourceTranslations.translatedContentAsset',
                'contentAsset.translatedFrom.sourceContentAsset.sourceTranslations.translatedContentAsset',
            ])
            ->latest('created_at')
            ->limit(min((int) $request->integer('limit', 25), 100))
            ->get();

        return response()->json([
            'data' => $actions->map(fn (PublishingAction $action) => $this->pendingPayload($action))->values(),
        ]);
    }

    public function published(Request $request, string $content, PublishingService $publishing): JsonResponse
    {
        $asset = $this->contentAsset($request, $content);

        if (! $asset) {
            return $this->error('content_not_found', 'Content was not found for this connector.', 404);
        }

        $action = $this->publishingAction($request, $asset);

        if (! $action) {
            return $this->error('publishing_action_not_found', 'No pending publishing action was found for this content.', 404);
        }

        $validated = $request->validate([
            'external_id' => ['nullable', 'string', 'max:255'],
            'external_url' => ['nullable', 'url', 'max:2048'],
            'language' => ['nullable', 'string', 'max:16'],
            'locale' => ['nullable', 'string', 'max:32'],
            'external_locale' => ['nullable', 'string', 'max:64'],
            'external_translation_group' => ['nullable', 'string', 'max:255'],
            'external_canonical_url' => ['nullable', 'url', 'max:2048'],
            'published_at' => ['nullable', 'date'],
            'response' => ['nullable', 'array'],
        ]);
        $action = $publishing->completeFromConnector($action, $validated, $this->installation($request));

        $this->log($this->installation($request), 'connector.content_published', 'info', 'published', $asset->title, [
            'content_asset_id' => $asset->id,
            'publishing_action_id' => $action->id,
        ]);

        return response()->json([
            'data' => [
                'content' => $this->contentPayload($asset->refresh()),
                'publishing_action' => $this->publishingActionPayload($action->refresh()),
            ],
        ]);
    }

    public function failed(Request $request, string $content, PublishingService $publishing): JsonResponse
    {
        $asset = $this->contentAsset($request, $content);

        if (! $asset) {
            return $this->error('content_not_found', 'Content was not found for this connector.', 404);
        }

        $action = $this->publishingAction($request, $asset);

        if (! $action) {
            return $this->error('publishing_action_not_found', 'No pending publishing action was found for this content.', 404);
        }

        $validated = $request->validate([
            'message' => ['required', 'string', 'max:2000'],
            'language' => ['nullable', 'string', 'max:16'],
            'locale' => ['nullable', 'string', 'max:32'],
            'external_locale' => ['nullable', 'string', 'max:64'],
            'external_translation_group' => ['nullable', 'string', 'max:255'],
            'external_canonical_url' => ['nullable', 'url', 'max:2048'],
            'response' => ['nullable', 'array'],
        ]);

        $action = $publishing->failFromConnector($action, $validated, $this->installation($request));

        $this->log($this->installation($request), 'connector.content_failed', 'error', 'failed', $validated['message'], [
            'content_asset_id' => $asset->id,
            'publishing_action_id' => $action->id,
        ]);

        return response()->json([
            'data' => [
                'content' => $this->contentPayload($asset->refresh()),
                'publishing_action' => $this->publishingActionPayload($action->refresh()),
            ],
        ]);
    }

    private function installation(Request $request): ConnectorInstallation
    {
        return $request->attributes->get('connector_installation');
    }

    private function account(Request $request): Account
    {
        return $request->attributes->get('connector_account');
    }

    private function brand(Request $request): Brand
    {
        return $request->attributes->get('connector_brand');
    }

    private function channel(Request $request): PublishingChannel
    {
        return $request->attributes->get('connector_channel');
    }

    private function contentAsset(Request $request, string $content): ?ContentAsset
    {
        return ContentAsset::query()
            ->where('account_id', $this->account($request)->id)
            ->where('brand_id', $this->brand($request)->id)
            ->where('channel_id', $this->channel($request)->id)
            ->where(fn (Builder $query) => $query->whereKey($content)->orWhere('uuid', $content))
            ->with([
                'answerBlocks',
                'brand',
                'sourceTranslations.translatedContentAsset',
                'translatedFrom.sourceContentAsset.sourceTranslations.translatedContentAsset',
            ])
            ->first();
    }

    private function publishingAction(Request $request, ContentAsset $asset): ?PublishingAction
    {
        return PublishingAction::query()
            ->where('account_id', $this->account($request)->id)
            ->where('brand_id', $this->brand($request)->id)
            ->where('publishing_channel_id', $this->channel($request)->id)
            ->where('content_asset_id', $asset->id)
            ->whereIn('status', ['queued', 'processing'])
            ->latest()
            ->first();
    }

    /**
     * @param  array<int, string>  $requested
     * @return array<int, string>
     */
    private function allowedCapabilities(ConnectorInstallation $installation, array $requested): array
    {
        return collect($requested)
            ->intersect($this->availableCapabilities($installation))
            ->values()
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private function availableCapabilities(ConnectorInstallation $installation): array
    {
        return $installation->version
            ->capabilities()
            ->where('is_enabled', true)
            ->pluck('capability')
            ->all();
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<int, string>
     */
    private function validateConnectorEventPayload(string $type, array $payload, ?string $message): array
    {
        $errors = [];

        if (str_starts_with($type, 'content.')) {
            if (! isset($payload['content_id']) && ! isset($payload['content_uuid']) && ! isset($payload['external_id'])) {
                $errors[] = 'Content events require payload.content_id, payload.content_uuid or payload.external_id.';
            }

            foreach (['language', 'locale', 'external_locale', 'external_translation_group'] as $field) {
                if (isset($payload[$field]) && ! is_string($payload[$field])) {
                    $errors[] = "payload.{$field} must be a string when present.";
                }
            }

            if (isset($payload['external_canonical_url']) && filter_var($payload['external_canonical_url'], FILTER_VALIDATE_URL) === false) {
                $errors[] = 'payload.external_canonical_url must be a valid URL when present.';
            }

            if ($type === 'content.failed' && ! ($message || isset($payload['error']) || isset($payload['error_message']))) {
                $errors[] = 'content.failed requires message, payload.error or payload.error_message.';
            }
        }

        if (str_starts_with($type, 'health.')) {
            if (in_array($type, ['health.warning', 'health.failed'], true) && ! ($message || isset($payload['message']))) {
                $errors[] = "{$type} requires message or payload.message.";
            }
        }

        if ($type === 'taxonomy.synced' && ! isset($payload['taxonomy'])) {
            $errors[] = 'taxonomy.synced requires payload.taxonomy.';
        }

        if ($type === 'author.synced' && ! isset($payload['author_id']) && ! isset($payload['email']) && ! isset($payload['name'])) {
            $errors[] = 'author.synced requires payload.author_id, payload.email or payload.name.';
        }

        if ($type === 'media.uploaded' && ! isset($payload['url'])) {
            $errors[] = 'media.uploaded requires payload.url.';
        }

        return $errors;
    }

    private function shouldSignalConnectorEvent(string $type): bool
    {
        return in_array($type, ['content.failed', 'health.warning', 'health.failed'], true);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function log(ConnectorInstallation $installation, string $event, string $level, ?string $status = null, ?string $message = null, array $context = []): void
    {
        ConnectorLog::query()->create([
            'connector_installation_id' => $installation->id,
            'account_id' => $installation->account_id,
            'brand_id' => $installation->brand_id,
            'level' => $level,
            'event' => $event,
            'status' => $status,
            'message' => $message,
            'context' => $context,
            'occurred_at' => now(),
        ]);
    }

    private function error(string $code, string $message, int $status): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
        ], $status);
    }

    /**
     * @return array<string, mixed>
     */
    private function connectorPayload(ConnectorInstallation $installation): array
    {
        return [
            'id' => $installation->uuid,
            'status' => $installation->status,
            'account_id' => $installation->account_id,
            'brand_id' => $installation->brand_id,
            'channel_id' => $installation->channel_id,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function pendingPayload(PublishingAction $action): array
    {
        return [
            'publishing_action' => $this->publishingActionPayload($action),
            'content' => $this->contentPayload($action->contentAsset),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function publishingActionPayload(PublishingAction $action): array
    {
        return [
            'id' => $action->id,
            'uuid' => $action->uuid,
            'action' => $action->action,
            'status' => $action->status,
            'language' => $action->language,
            'locale' => $action->locale,
            'market' => $action->brand->market,
            'scheduled_at' => $action->scheduled_at?->toISOString(),
            'published_at' => $action->published_at?->toISOString(),
            'external_id' => $action->external_id,
            'external_url' => $action->external_url,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function contentPayload(ContentAsset $asset): array
    {
        return [
            'id' => $asset->id,
            'uuid' => $asset->uuid,
            'type' => $asset->type,
            'status' => $asset->status,
            'title' => $asset->title,
            'slug' => $asset->slug,
            'language' => $asset->language,
            'locale' => $asset->locale,
            'market' => $asset->brand->market,
            'canonical_url' => $asset->canonical_url,
            'hreflang' => $this->hreflangPayload($asset),
            'translated_from' => $this->translatedFromPayload($asset),
            'translation_group_id' => $this->translationGroupId($asset),
            'excerpt' => $asset->excerpt,
            'body' => $asset->body,
            'metadata' => $asset->metadata ?? [],
            'seo_metadata' => $asset->seo_metadata ?? [],
            'answer_blocks' => $asset->answerBlocks
                ->sortBy('position')
                ->values()
                ->map(fn ($block) => [
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
            'published_at' => $asset->published_at?->toISOString(),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function hreflangPayload(ContentAsset $asset): array
    {
        $asset->loadMissing([
            'sourceTranslations.translatedContentAsset',
            'translatedFrom.sourceContentAsset.sourceTranslations.translatedContentAsset',
        ]);

        $source = $asset->translatedFrom->first()?->sourceContentAsset ?? $asset;

        return collect([$source])
            ->merge($source->sourceTranslations->pluck('translatedContentAsset')->filter())
            ->unique('id')
            ->values()
            ->map(fn (ContentAsset $content) => [
                'content_id' => $content->id,
                'content_uuid' => $content->uuid,
                'language' => $content->language,
                'locale' => $content->locale,
                'url' => $content->canonical_url,
            ])
            ->all();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function translatedFromPayload(ContentAsset $asset): ?array
    {
        $asset->loadMissing('translatedFrom.sourceContentAsset');
        $translation = $asset->translatedFrom->first();
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

    private function translationGroupId(ContentAsset $asset): ?int
    {
        $asset->loadMissing('sourceTranslations', 'translatedFrom');

        if ($asset->translatedFrom->isNotEmpty()) {
            return $asset->translatedFrom->first()->source_content_asset_id;
        }

        return $asset->sourceTranslations->isNotEmpty() ? $asset->id : null;
    }
}
