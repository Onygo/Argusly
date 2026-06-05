<?php

namespace App\Services\Integrations;

use App\Enums\AsyncOperationStatus;
use App\Enums\AsyncOperationType;
use App\Models\ApiKey;
use App\Models\AsyncOperationRun;
use App\Models\Workspace;

class AsyncOperationService
{
    public function create(
        Workspace $workspace,
        AsyncOperationType $type,
        ?ApiKey $apiKey = null,
        ?string $contentDestinationId = null,
        ?string $resourceType = null,
        ?string $resourceId = null,
        ?array $requestPayload = null,
    ): AsyncOperationRun {
        return AsyncOperationRun::query()->create([
            'workspace_id' => $workspace->id,
            'content_destination_id' => $contentDestinationId,
            'api_key_id' => $apiKey?->id,
            'operation_type' => $type,
            'status' => AsyncOperationStatus::QUEUED,
            'resource_type' => $resourceType,
            'resource_id' => $resourceId,
            'request_payload' => $requestPayload,
        ]);
    }

    public function markProcessing(string $operationId): void
    {
        AsyncOperationRun::query()
            ->whereKey($operationId)
            ->update([
                'status' => AsyncOperationStatus::PROCESSING,
                'started_at' => now(),
                'failed_at' => null,
                'error_code' => null,
                'error_message' => null,
            ]);
    }

    public function markCompleted(string $operationId, ?array $resultPayload = null): void
    {
        AsyncOperationRun::query()
            ->whereKey($operationId)
            ->update([
                'status' => AsyncOperationStatus::COMPLETED,
                'completed_at' => now(),
                'failed_at' => null,
                'result_payload' => $resultPayload,
                'error_code' => null,
                'error_message' => null,
            ]);
    }

    public function markFailed(string $operationId, string $errorMessage, ?string $errorCode = null, ?array $resultPayload = null): void
    {
        AsyncOperationRun::query()
            ->whereKey($operationId)
            ->update([
                'status' => AsyncOperationStatus::FAILED,
                'failed_at' => now(),
                'result_payload' => $resultPayload,
                'error_code' => $errorCode,
                'error_message' => mb_substr($errorMessage, 0, 5000),
            ]);
    }
}
