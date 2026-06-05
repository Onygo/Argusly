<?php

namespace App\Actions\Drafts;

use App\Enums\AsyncOperationType;
use App\Models\ApiKey;
use App\Models\Brief;
use App\Models\Content;
use App\Models\Draft;
use App\Services\BriefProcessing\BriefToDraftService;
use App\Services\Integrations\ApiWebhookPublisher;
use App\Services\Integrations\AsyncOperationService;
use Illuminate\Support\Str;

class QueueDraftGenerationAction
{
    public function __construct(
        private readonly BriefToDraftService $briefToDraftService,
        private readonly AsyncOperationService $operations,
        private readonly ApiWebhookPublisher $webhookPublisher,
    ) {}

    /**
     * @param  array<string, mixed>  $requestPayload
     * @return array{draft: Draft, operation: \App\Models\AsyncOperationRun}
     */
    public function execute(Brief $brief, ?ApiKey $apiKey = null, array $requestPayload = []): array
    {
        $brief->loadMissing('clientSite.workspace');

        if (! $brief->content_id) {
            $content = Content::query()->create([
                'id' => (string) Str::uuid(),
                'workspace_id' => $brief->clientSite?->workspace_id,
                'client_site_id' => $brief->client_site_id,
                'content_destination_id' => $brief->content_destination_id,
                'title' => (string) $brief->title,
                'language' => (string) $brief->language,
                'type' => 'article',
                'status' => 'brief',
                'source' => 'api',
                'external_key' => (string) Str::uuid(),
                'generation_mode' => 'balanced',
            ]);

            $brief->content_id = $content->id;
            $brief->save();
        }

        if ((string) $brief->status === 'draft') {
            $brief->status = 'ready_for_generation';
            $brief->save();
        }

        $draft = $this->briefToDraftService->claimAndCreateDraft((string) $brief->id);
        if (! $draft) {
            throw new \RuntimeException('Brief could not be queued for draft generation.');
        }

        $operation = $this->operations->create(
            workspace: $brief->clientSite->workspace,
            type: AsyncOperationType::DRAFT_GENERATION,
            apiKey: $apiKey,
            contentDestinationId: $brief->content_destination_id,
            resourceType: 'draft',
            resourceId: (string) $draft->id,
            requestPayload: $requestPayload,
        );

        $meta = is_array($draft->meta) ? $draft->meta : [];
        $meta['async_operation_id'] = (string) $operation->id;
        if (array_key_exists('requested_max_output_tokens', $requestPayload)) {
            $meta['requested_max_output_tokens'] = $requestPayload['requested_max_output_tokens'];
        }
        $draft->meta = $meta;
        $draft->save();

        $this->webhookPublisher->publish(
            workspace: $brief->clientSite->workspace,
            eventType: 'draft.generation.started',
            payload: [
                'brief_id' => (string) $brief->id,
                'draft_id' => (string) $draft->id,
                'operation_id' => (string) $operation->id,
            ],
            contentDestinationId: $brief->content_destination_id,
            eventId: (string) $operation->id,
        );

        return [
            'draft' => $draft,
            'operation' => $operation,
        ];
    }
}
