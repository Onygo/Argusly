<?php

namespace App\Http\Controllers\Api\V1\Headless;

use App\Http\Controllers\Api\V1\Concerns\RespondsWithApi;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Headless\CreateWebhookRequest;
use App\Http\Requests\Api\V1\Headless\UpdateWebhookRequest;
use App\Http\Resources\Api\V1\ApiWebhookResource;
use App\Models\ApiWebhook;
use App\Services\Integrations\ApiCapabilityService;
use App\Support\Webhooks\WebhookEventRegistry;
use Illuminate\Http\Request;

class WebhookController extends Controller
{
    use RespondsWithApi;

    public function index(Request $request)
    {
        $workspace = $request->attributes->get('workspace');

        $items = ApiWebhook::query()
            ->where('workspace_id', $workspace->id)
            ->orderByDesc('created_at')
            ->get();

        return $this->success(ApiWebhookResource::collection($items)->resolve());
    }

    public function store(CreateWebhookRequest $request, ApiCapabilityService $capabilities)
    {
        $workspace = $request->attributes->get('workspace');
        try {
            $capabilities->assertWebhooksEnabled($workspace);
        } catch (\RuntimeException $exception) {
            return $this->error($exception->getMessage(), code: 'PLAN_LIMIT_REACHED', status: 422);
        }

        $validated = $request->validated();

        if (! empty($validated['content_destination_id'])) {
            $belongs = $workspace->contentDestinations()
                ->where('id', $validated['content_destination_id'])
                ->exists();
            if (! $belongs) {
                return $this->error(
                    'Destination not found for workspace',
                    ['content_destination_id' => ['Invalid destination id']],
                    'DESTINATION_NOT_FOUND',
                    422
                );
            }
        }

        $model = ApiWebhook::query()->create([
            'workspace_id' => $workspace->id,
            'content_destination_id' => $validated['content_destination_id'] ?? null,
            'name' => $validated['name'],
            'target_url' => $validated['target_url'],
            'secret' => $validated['secret'],
            'events' => array_values($validated['events']),
            'is_active' => (bool) ($validated['is_active'] ?? true),
            'created_by' => optional($request->user())->id,
        ]);

        return $this->success((new ApiWebhookResource($model))->resolve(), status: 201);
    }

    public function update(UpdateWebhookRequest $request, string $webhook)
    {
        $workspace = $request->attributes->get('workspace');

        $model = ApiWebhook::query()
            ->where('workspace_id', $workspace->id)
            ->where('id', $webhook)
            ->firstOrFail();

        $validated = $request->validated();
        if (array_key_exists('events', $validated)) {
            $validated['events'] = array_values($validated['events']);
        }

        $model->update($validated);

        return $this->success((new ApiWebhookResource($model->fresh()))->resolve());
    }

    public function destroy(Request $request, string $webhook)
    {
        $workspace = $request->attributes->get('workspace');

        $model = ApiWebhook::query()
            ->where('workspace_id', $workspace->id)
            ->where('id', $webhook)
            ->firstOrFail();

        $model->delete();

        return $this->success(['deleted' => true]);
    }

    /**
     * List all available webhook event types.
     *
     * Returns the complete catalog of webhook events that can be subscribed to,
     * including event descriptions and deprecation information.
     */
    public function events()
    {
        $catalog = WebhookEventRegistry::catalog();
        $currentVersion = WebhookEventRegistry::CURRENT_VERSION;

        $events = [];
        foreach ($catalog as $category => $categoryEvents) {
            foreach ($categoryEvents as $eventKey => $eventInfo) {
                $events[] = [
                    'event' => $eventInfo['event'],
                    'category' => $category,
                    'description' => $eventInfo['description'],
                    'deprecated' => $eventInfo['deprecated'] ?? false,
                    'version' => $currentVersion,
                ];
            }
        }

        return $this->success([
            'events' => $events,
            'version' => $currentVersion,
            'categories' => array_keys($catalog),
            'deprecation_notice' => 'Events in the "legacy" category are deprecated and will be removed after 2026-06-01. Please migrate to their replacements.',
        ]);
    }

    /**
     * Get a sample payload for a specific event type.
     *
     * Returns a sample webhook payload structure for the given event type.
     * Useful for understanding the payload shape before implementing webhook handlers.
     */
    public function eventSample(string $event)
    {
        if (! WebhookEventRegistry::isValid($event)) {
            return $this->error(
                "Unknown event type: {$event}",
                code: 'INVALID_EVENT_TYPE',
                status: 404
            );
        }

        $sample = $this->buildSamplePayload($event);

        return $this->success([
            'event' => $event,
            'version' => WebhookEventRegistry::CURRENT_VERSION,
            'deprecated' => WebhookEventRegistry::isDeprecated($event),
            'replacement' => WebhookEventRegistry::getReplacementEvent($event),
            'sample_payload' => $sample,
            'headers' => [
                'Content-Type' => 'application/json',
                'X-PublishLayer-Event' => $event,
                'X-PublishLayer-Event-Version' => WebhookEventRegistry::CURRENT_VERSION,
                'X-PublishLayer-Event-ID' => 'evt_01HXY...',
                'X-PublishLayer-Signature' => 'sha256=...',
                'X-PublishLayer-Delivery-Attempt' => '1',
                'X-PublishLayer-Timestamp' => now()->format('Y-m-d\TH:i:s.u\Z'),
            ],
        ]);
    }

    private function buildSamplePayload(string $event): array
    {
        $envelope = [
            'event' => $event,
            'event_version' => WebhookEventRegistry::CURRENT_VERSION,
            'event_id' => 'evt_01HXYZ123456789ABCDEF_a1b2c3d4',
            'sent_at' => now()->format('Y-m-d\TH:i:s.u\Z'),
            'workspace_id' => 'ws_01HXYZ...',
            'data' => [],
            'links' => [],
        ];

        $envelope['data'] = match ($event) {
            WebhookEventRegistry::ARTICLE_CREATED,
            WebhookEventRegistry::ARTICLE_UPDATED,
            WebhookEventRegistry::ARTICLE_SUBMITTED,
            WebhookEventRegistry::ARTICLE_APPROVED,
            WebhookEventRegistry::ARTICLE_REJECTED,
            WebhookEventRegistry::ARTICLE_SCHEDULED,
            WebhookEventRegistry::ARTICLE_ARCHIVED => [
                'article_id' => 'art_01HXYZ...',
                'title' => 'Sample Article Title',
                'status' => 'draft',
                'type' => 'article',
                'language' => 'en',
                'primary_keyword' => 'sample keyword',
                'seo_title' => 'Sample SEO Title',
                'workspace_id' => 'ws_01HXYZ...',
                'client_site_id' => 'site_01HXYZ...',
                'destination_id' => 'dest_01HXYZ...',
                'created_at' => now()->subDay()->toIso8601String(),
                'updated_at' => now()->toIso8601String(),
            ],
            WebhookEventRegistry::DRAFT_GENERATION_STARTED,
            WebhookEventRegistry::DRAFT_GENERATION_SUCCEEDED,
            WebhookEventRegistry::LEGACY_DRAFT_GENERATION_COMPLETED => [
                'draft_id' => 'draft_01HXYZ...',
                'brief_id' => 'brief_01HXYZ...',
                'article_id' => 'art_01HXYZ...',
                'title' => 'Sample Draft Title',
                'status' => 'generated',
                'language' => 'en',
                'model_used' => 'gpt-4',
                'operation_id' => 'op_01HXYZ...',
            ],
            WebhookEventRegistry::DRAFT_GENERATION_FAILED => [
                'draft_id' => 'draft_01HXYZ...',
                'brief_id' => 'brief_01HXYZ...',
                'article_id' => 'art_01HXYZ...',
                'error' => 'Generation failed: timeout',
                'operation_id' => 'op_01HXYZ...',
            ],
            WebhookEventRegistry::DRAFT_TRANSLATION_SUCCEEDED,
            WebhookEventRegistry::LEGACY_DRAFT_TRANSLATED => [
                'draft_id' => 'draft_01HXYZ...',
                'source_draft_id' => 'draft_01HXYZ...',
                'source_language' => 'en',
                'target_language' => 'nl',
                'operation_id' => 'op_01HXYZ...',
            ],
            WebhookEventRegistry::PUBLICATION_STARTED,
            WebhookEventRegistry::PUBLICATION_SUCCEEDED => [
                'article_id' => 'art_01HXYZ...',
                'publication_id' => 'pub_01HXYZ...',
                'draft_id' => 'draft_01HXYZ...',
                'provider' => 'wordpress',
                'remote_id' => '12345',
                'remote_url' => 'https://example.com/sample-article',
                'remote_type' => 'post',
                'delivery_status' => 'delivered',
            ],
            WebhookEventRegistry::PUBLICATION_FAILED => [
                'article_id' => 'art_01HXYZ...',
                'draft_id' => 'draft_01HXYZ...',
                'provider' => 'wordpress',
                'error' => 'Connection timeout',
            ],
            WebhookEventRegistry::PUBLICATION_VERIFIED => [
                'article_id' => 'art_01HXYZ...',
                'publication_id' => 'pub_01HXYZ...',
                'remote_id' => '12345',
                'remote_url' => 'https://example.com/sample-article',
            ],
            WebhookEventRegistry::MEDIA_GENERATED => [
                'media_id' => 'img_01HXYZ...',
                'article_id' => 'art_01HXYZ...',
                'type' => 'featured',
                'status' => 'ready',
                'url' => 'https://cdn.example.com/images/sample.jpg',
                'width' => 1200,
                'height' => 630,
                'format' => 'jpg',
            ],
            WebhookEventRegistry::SEO_AUDIT_COMPLETED => [
                'seo_audit_id' => 'audit_01HXYZ...',
                'status' => 'completed',
                'pages_crawled' => 25,
                'issues_found' => 3,
                'score' => 85,
            ],
            WebhookEventRegistry::SEO_AUDIT_FAILED => [
                'seo_audit_id' => 'audit_01HXYZ...',
                'error' => 'Unable to crawl site',
                'operation_id' => 'op_01HXYZ...',
            ],
            WebhookEventRegistry::CREDITS_LOW => [
                'workspace_id' => 'ws_01HXYZ...',
                'workspace_name' => 'My Workspace',
                'current_credits' => 50,
                'threshold_credits' => 100,
                'percentage_remaining' => 10,
            ],
            WebhookEventRegistry::LEGACY_BRIEF_CREATED => [
                'brief_id' => 'brief_01HXYZ...',
                'content_id' => 'art_01HXYZ...',
                'title' => 'Sample Brief Title',
                'topic' => 'Sample Topic',
                'primary_keyword' => 'sample keyword',
                'status' => 'approved',
            ],
            default => ['message' => 'Sample data for ' . $event],
        };

        $envelope['links'] = match ($event) {
            WebhookEventRegistry::ARTICLE_CREATED,
            WebhookEventRegistry::ARTICLE_UPDATED => [
                'article' => config('app.url') . '/api/v1/articles/art_01HXYZ...',
            ],
            WebhookEventRegistry::DRAFT_GENERATION_SUCCEEDED => [
                'draft' => config('app.url') . '/api/v1/drafts/draft_01HXYZ...',
                'article' => config('app.url') . '/api/v1/articles/art_01HXYZ...',
            ],
            WebhookEventRegistry::PUBLICATION_SUCCEEDED => [
                'article' => config('app.url') . '/api/v1/articles/art_01HXYZ...',
                'remote' => 'https://example.com/sample-article',
            ],
            default => [],
        };

        return $envelope;
    }
}
