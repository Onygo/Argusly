<?php

namespace App\Actions\Drafts;

use App\Enums\AsyncOperationType;
use App\Jobs\GenerateDraftJob;
use App\Models\ApiKey;
use App\Models\Draft;
use App\Services\Integrations\ApiWebhookPublisher;
use App\Services\Integrations\AsyncOperationService;

class RegenerateDraftAction
{
    public function __construct(
        private readonly AsyncOperationService $operations,
        private readonly ApiWebhookPublisher $webhookPublisher,
    ) {}

    /**
     * @param  array<string, mixed>  $requestPayload
     */
    public function execute(Draft $draft, ?ApiKey $apiKey = null, array $requestPayload = []): \App\Models\AsyncOperationRun
    {
        $draft->loadMissing('clientSite.workspace');

        $operation = $this->operations->create(
            workspace: $draft->clientSite->workspace,
            type: AsyncOperationType::DRAFT_REGENERATION,
            apiKey: $apiKey,
            contentDestinationId: $draft->content_destination_id,
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
        $draft->status = 'queued';
        $draft->last_error = null;
        $draft->save();

        GenerateDraftJob::dispatch((string) $draft->id);

        $this->webhookPublisher->publish(
            workspace: $draft->clientSite->workspace,
            eventType: 'draft.generation.started',
            payload: [
                'draft_id' => (string) $draft->id,
                'operation_id' => (string) $operation->id,
                'regenerate' => true,
            ],
            contentDestinationId: $draft->content_destination_id,
            eventId: (string) $operation->id,
        );

        return $operation;
    }
}
