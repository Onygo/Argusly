<?php

namespace App\Actions\Drafts;

use App\Enums\AsyncOperationType;
use App\Jobs\TranslateDraftJob;
use App\Models\ApiKey;
use App\Models\Draft;
use App\Services\Integrations\AsyncOperationService;

class TranslateDraftAction
{
    public function __construct(private readonly AsyncOperationService $operations) {}

    public function execute(Draft $draft, string $targetLanguage, ?ApiKey $apiKey = null, ?string $model = null): \App\Models\AsyncOperationRun
    {
        $draft->loadMissing('clientSite.workspace');
        $sourceDraft = $draft->getOriginalSourceDraft() ?? $draft;

        $operation = $this->operations->create(
            workspace: $sourceDraft->clientSite->workspace,
            type: AsyncOperationType::DRAFT_TRANSLATION,
            apiKey: $apiKey,
            contentDestinationId: $sourceDraft->content_destination_id,
            resourceType: 'draft',
            resourceId: (string) $sourceDraft->id,
            requestPayload: [
                'requested_from_draft_id' => (string) $draft->id,
                'source_draft_id' => (string) $sourceDraft->id,
                'target_language' => $targetLanguage,
                'model' => $model,
            ],
        );

        TranslateDraftJob::dispatch(
            sourceDraftId: (string) $sourceDraft->id,
            targetLanguage: $targetLanguage,
            userId: null,
            modelOverride: $model,
            operationId: (string) $operation->id,
        );

        return $operation;
    }
}
