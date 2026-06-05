<?php

namespace App\Actions\Briefs;

use App\Actions\Drafts\QueueDraftGenerationAction;
use App\Models\ApiKey;
use App\Models\Brief;
use App\Models\Content;
use App\Models\Workspace;
use App\Services\Content\ContentDeduplicationService;
use App\Services\Content\ContentLifecycleService;
use App\Services\Integrations\ApiWebhookPublisher;
use App\Services\Integrations\DestinationBillingSiteService;
use App\Services\Integrations\DestinationResolverService;
use App\Support\ContentPersistencePayloadNormalizer;
use Illuminate\Support\Str;

class CreateBriefAction
{
    public function __construct(
        private readonly DestinationResolverService $destinationResolver,
        private readonly DestinationBillingSiteService $billingSiteService,
        private readonly ContentLifecycleService $contentLifecycleService,
        private readonly ContentDeduplicationService $contentDeduplicationService,
        private readonly QueueDraftGenerationAction $queueDraftGenerationAction,
        private readonly ApiWebhookPublisher $webhookPublisher,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array{brief: Brief, draft: ?\App\Models\Draft, operation: ?\App\Models\AsyncOperationRun}
     */
    public function execute(
        Workspace $workspace,
        array $payload,
        ?ApiKey $apiKey = null,
        ?int $createdBy = null,
    ): array {
        $destination = $this->destinationResolver->resolve(
            workspace: $workspace,
            apiKey: $apiKey,
            destinationId: isset($payload['content_destination_id']) ? (string) $payload['content_destination_id'] : null,
        );

        $billingSite = $this->billingSiteService->ensureBillingSite($destination);

        $outputType = trim((string) ($payload['output_type'] ?? 'kb_article')) ?: 'kb_article';
        $contentType = trim((string) ($payload['content_type'] ?? ''));
        if ($contentType === '') {
            $contentType = $this->contentLifecycleService->mapOutputTypeToContentType($outputType);
        }

        $language = trim((string) ($payload['language'] ?? ''));
        if ($language === '') {
            $language = (string) ($workspace->default_content_language?->value ?? $destination->default_language ?: 'en');
        }

        $externalKey = trim((string) ($payload['external_key'] ?? $payload['idempotency_key'] ?? ''));
        $contentPayload = [
            'id' => (string) Str::uuid(),
            'workspace_id' => $workspace->id,
            'client_site_id' => $billingSite->id,
            'content_destination_id' => $destination->id,
            'title' => (string) $payload['title'],
            'language' => $language,
            'type' => $contentType,
            'status' => 'brief',
            'source' => 'api',
            'external_key' => $externalKey !== '' ? $externalKey : (string) Str::uuid(),
            'primary_keyword' => (string) ($payload['primary_keyword'] ?? ''),
            'generation_mode' => 'balanced',
            'preferred_length' => 'medium',
            'created_by' => $createdBy,
            'updated_by' => $createdBy,
        ];

        $content = $externalKey !== ''
            ? $this->contentDeduplicationService->createOrReuse($contentPayload, [
                'workspace_id' => (string) $workspace->id,
                'client_site_id' => (string) $billingSite->id,
                'content_destination_id' => (string) $destination->id,
                'language' => $language,
                'type' => $contentType,
                'external_key' => $externalKey,
            ])
            : Content::query()->create($contentPayload);

        $brief = Brief::query()
            ->where('content_id', (string) $content->id)
            ->latest('created_at')
            ->first();

        if (! $brief) {
            $brief = Brief::query()->create(ContentPersistencePayloadNormalizer::normalizeBrief([
            'id' => (string) Str::uuid(),
            'client_site_id' => $billingSite->id,
            'content_destination_id' => $destination->id,
            'created_by_user_id' => $createdBy,
            'content_id' => $content->id,
            'status' => 'draft',
            'source' => 'api',
            'progress' => 0,
            'title' => (string) $payload['title'],
            'language' => $language,
            'content_type' => $contentType,
            'output_type' => $outputType,
            'intent' => $payload['intent'] ?? null,
            'primary_keyword' => $payload['primary_keyword'] ?? null,
            'secondary_keywords' => is_array($payload['secondary_keywords'] ?? null) ? array_values($payload['secondary_keywords']) : null,
            'audience' => $payload['audience'] ?? null,
            'audience_details' => $payload['audience_details'] ?? null,
            'target_audience' => $payload['target_audience'] ?? null,
            'funnel_stage' => $payload['funnel_stage'] ?? null,
            'search_intent' => $payload['search_intent'] ?? null,
            'notes' => $payload['notes'] ?? null,
            'tone_of_voice' => $payload['tone_of_voice'] ?? null,
            'unique_angle' => $payload['unique_angle'] ?? null,
            'key_points' => is_array($payload['key_points'] ?? null) ? array_values($payload['key_points']) : null,
            'call_to_action' => $payload['call_to_action'] ?? null,
            'desired_length_min' => $payload['desired_length_min'] ?? null,
            'desired_length_max' => $payload['desired_length_max'] ?? null,
            'client_refs' => [
                'channel' => 'api_headless',
                'destination_id' => (string) $destination->id,
                'destination_type' => (string) $destination->type->value,
                'requested_max_output_tokens' => isset($payload['requested_max_output_tokens'])
                    ? (int) $payload['requested_max_output_tokens']
                    : null,
            ],
            'wp_site_id' => null,
            ]));
        }

        $destination->last_used_at = now();
        $destination->save();

        $this->webhookPublisher->publish(
            workspace: $workspace,
            eventType: 'brief.created',
            payload: [
                'brief_id' => (string) $brief->id,
                'content_id' => (string) $content->id,
                'destination_id' => (string) $destination->id,
            ],
            contentDestinationId: (string) $destination->id,
            eventId: (string) $brief->id,
        );

        $draft = null;
        $operation = null;
        if ((bool) ($payload['generate_draft'] ?? false)) {
            $queue = $this->queueDraftGenerationAction->execute(
                brief: $brief,
                apiKey: $apiKey,
                requestPayload: [
                    'requested_max_output_tokens' => $payload['requested_max_output_tokens'] ?? null,
                ],
            );
            $draft = $queue['draft'];
            $operation = $queue['operation'];
        }

        return [
            'brief' => $brief,
            'draft' => $draft,
            'operation' => $operation,
        ];
    }
}
