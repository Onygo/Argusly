<?php

namespace App\Actions\SeoAudits;

use App\Enums\AsyncOperationType;
use App\Jobs\SeoAudit\RunSeoAuditJob;
use App\Models\ApiKey;
use App\Models\Workspace;
use App\Services\Integrations\AsyncOperationService;
use App\Services\Integrations\DestinationBillingSiteService;
use App\Services\Integrations\DestinationResolverService;

class StartSeoAuditAction
{
    public function __construct(
        private readonly DestinationResolverService $destinationResolver,
        private readonly DestinationBillingSiteService $billingSiteService,
        private readonly AsyncOperationService $operations,
    ) {}

    public function execute(
        Workspace $workspace,
        ?ApiKey $apiKey = null,
        ?string $contentDestinationId = null,
        int $maxPages = 50,
    ): \App\Models\AsyncOperationRun {
        $destination = $this->destinationResolver->resolve($workspace, $apiKey, $contentDestinationId);
        $billingSite = $this->billingSiteService->ensureBillingSite($destination);

        $operation = $this->operations->create(
            workspace: $workspace,
            type: AsyncOperationType::SEO_AUDIT,
            apiKey: $apiKey,
            contentDestinationId: (string) $destination->id,
            resourceType: 'client_site',
            resourceId: (string) $billingSite->id,
            requestPayload: [
                'max_pages' => $maxPages,
            ],
        );

        RunSeoAuditJob::dispatch(
            siteId: (string) $billingSite->id,
            maxPages: max(1, $maxPages),
            operationId: (string) $operation->id,
            contentDestinationId: (string) $destination->id,
        );

        return $operation;
    }
}
